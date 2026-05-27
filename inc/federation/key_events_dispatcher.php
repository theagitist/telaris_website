<?php
declare(strict_types=1);

/**
 * Pluriverse-side key-events push dispatcher (stage 4h).
 *
 * Drains key_event_push_attempts: for each row whose delivery is overdue,
 * fetches the pre-built signed JWS from key_events_signed, wraps it in a
 * coord-signed HTTP request, and POSTs to <peer>/api/pluriverse/key-events-push
 * with tag = tel-key-events. The JWS itself is unchanged across the hop;
 * only the transport-layer signature differs per peer.
 *
 * Backoff schedule mirrors v10 § Outbound queue + the instance-side
 * dispatcher (stage 4d): 1m / 5m / 30m / 2h / 6h / 12h / 24h, then
 * give-up after the 7th attempt.
 *
 * Idempotent. Caller (bin/key-events-dispatch via cron) self-bounds via
 * KEY_EVENTS_DISPATCH_BATCH_SIZE (50 rows per invocation).
 *
 * Spec: P2P federation plan v10 § Key management → Push channel;
 *       Stage 4 handshake design § Pluriverse-side key-events push
 *       dispatcher (4h).
 */

require_once __DIR__ . '/http_sig.php';
require_once __DIR__ . '/identity.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/db_federation.php';

const KEY_EVENTS_DISPATCH_BATCH_SIZE = 50;
const KEY_EVENTS_DISPATCH_GIVE_UP_AFTER = 7;
const KEY_EVENTS_DISPATCH_HTTP_CONNECT_TIMEOUT = 5;
const KEY_EVENTS_DISPATCH_HTTP_TIMEOUT = 15;

/**
 * Backoff in seconds keyed by 1-based attempt number. The Nth entry is
 * the delay AFTER the Nth attempt fails. After the 7th failure the row
 * flips to 'given_up' and is no longer eligible.
 *
 * @var array<int,int>
 */
const KEY_EVENTS_DISPATCH_BACKOFF = [
    1 => 60,        // 1m
    2 => 300,       // 5m
    3 => 1800,      // 30m
    4 => 7200,      // 2h
    5 => 21600,     // 6h
    6 => 43200,     // 12h
    7 => 86400,     // 24h
];

/**
 * Process a batch of overdue push attempts.
 *
 * @return array{processed:int,delivered:int,failed:int,given_up:int}
 */
function key_events_dispatcher_process_batch(int $batchSize = KEY_EVENTS_DISPATCH_BATCH_SIZE): array {
    db_ensure_key_event_push_attempts_table();
    $pdo = getDB();

    // Eligible: pending OR failed, and next_attempt_at NULL (never tried)
    // or in the past. Order by next_attempt_at so the oldest overdue row
    // goes first; LIMIT bounds the wall-clock cost per cron tick.
    $stmt = $pdo->prepare("
        SELECT key_event_id, instance_id
        FROM key_event_push_attempts
        WHERE delivery_status IN ('pending', 'failed')
          AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
        ORDER BY next_attempt_at IS NOT NULL, next_attempt_at ASC
        LIMIT :n
    ");
    $stmt->bindValue(':n', max(1, $batchSize), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'given_up' => 0];
    foreach ($rows as $row) {
        $r = key_events_dispatcher_deliver_one((int)$row['key_event_id'], (int)$row['instance_id']);
        $result['processed']++;
        if ($r['outcome'] === 'delivered') $result['delivered']++;
        elseif ($r['outcome'] === 'given_up') $result['given_up']++;
        else $result['failed']++;
    }
    return $result;
}

/**
 * Deliver one (key_event_id, instance_id) pair.
 *
 * @return array{outcome:string,http_status:?int,error:?string}
 */
function key_events_dispatcher_deliver_one(int $keyEventId, int $instanceId): array {
    $pdo = getDB();

    // Load the signed JWS + target host in one query. If either row is
    // gone (FK cascade or row deleted between batch select and now), we
    // mark the attempt as given_up immediately (nothing to send).
    $stmt = $pdo->prepare("
        SELECT k.signed_payload, k.event_type, k.origin_host,
               i.hostname, i.admission_status
        FROM key_event_push_attempts a
        JOIN key_events_signed k ON k.id = a.key_event_id
        JOIN instances i ON i.id = a.instance_id
        WHERE a.key_event_id = :k AND a.instance_id = :i
        LIMIT 1
    ");
    $stmt->execute([':k' => $keyEventId, ':i' => $instanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        // Row vanished; advance to given_up so we stop selecting it.
        key_events_dispatcher_advance($keyEventId, $instanceId, 'given_up', null, 'attempt_row_missing');
        return ['outcome' => 'given_up', 'http_status' => null, 'error' => 'attempt_row_missing'];
    }

    // We only push to peers that are currently published. A blacklisted
    // or withdrawn peer is not a valid target; mark the attempt as
    // given_up rather than failing it (no retry will help).
    if ((string)$row['admission_status'] !== 'published') {
        key_events_dispatcher_advance($keyEventId, $instanceId, 'given_up', null, 'recipient_not_published:' . $row['admission_status']);
        return ['outcome' => 'given_up', 'http_status' => null, 'error' => 'recipient_not_published'];
    }

    try {
        $secret = federation_load_coord_secret_key();
        $keyid = federation_coord_keyid();
    } catch (Throwable $e) {
        // Coord key unavailable: not a per-row failure; back off briefly
        // and try again next tick. Use a "failed" transition with the
        // smallest backoff so the next cron firing retries.
        key_events_dispatcher_advance($keyEventId, $instanceId, 'failed', null, 'coord_key_unavailable: ' . substr($e->getMessage(), 0, 200));
        return ['outcome' => 'failed', 'http_status' => null, 'error' => 'coord_key_unavailable'];
    }

    $body = (string)json_encode(['envelope' => (string)$row['signed_payload']], JSON_UNESCAPED_SLASHES);
    $targetUri = 'https://' . $row['hostname'] . '/api/pluriverse/key-events-push';

    [$delivered, $status, $error] = key_events_dispatcher_http_post($targetUri, $body, 'tel-key-events', $keyid, $secret);

    if ($delivered) {
        key_events_dispatcher_advance($keyEventId, $instanceId, 'delivered', $status, null);
        return ['outcome' => 'delivered', 'http_status' => $status, 'error' => null];
    }

    // Failed. Bump attempt_count + decide next state.
    $countStmt = $pdo->prepare("SELECT attempt_count FROM key_event_push_attempts WHERE key_event_id = :k AND instance_id = :i");
    $countStmt->execute([':k' => $keyEventId, ':i' => $instanceId]);
    $newCount = (int)$countStmt->fetchColumn() + 1;

    $finalState = $newCount >= KEY_EVENTS_DISPATCH_GIVE_UP_AFTER ? 'given_up' : 'failed';
    key_events_dispatcher_advance($keyEventId, $instanceId, $finalState, $status, $error);
    return ['outcome' => $finalState, 'http_status' => $status, 'error' => $error];
}

/**
 * Sign the request and POST it. Returns [delivered_2xx, http_status, error_msg].
 *
 * @return array{0:bool,1:?int,2:?string}
 */
function key_events_dispatcher_http_post(string $targetUri, string $body, string $tag, string $keyid, string $secret): array {
    $headers = [
        'Host' => parse_url($targetUri, PHP_URL_HOST) ?: '',
        'Date' => gmdate('D, d M Y H:i:s') . ' GMT',
        'Content-Type' => 'application/json',
    ];
    $signed = federation_http_sig_sign(
        ['method' => 'POST', 'target_uri' => $targetUri, 'headers' => $headers, 'body' => $body],
        $secret,
        ['keyid' => $keyid, 'tag' => $tag, 'nonce' => federation_http_sig_generate_nonce()]
    );
    $headers['Content-Digest'] = $signed['content_digest'] ?? federation_http_sig_content_digest($body);
    $headers['Content-Length'] = (string)strlen($body);
    $headers['Signature-Input'] = $signed['signature_input'];
    $headers['Signature'] = $signed['signature'];

    $curlHeaders = [];
    foreach ($headers as $k => $v) $curlHeaders[] = $k . ': ' . $v;

    $ch = curl_init($targetUri);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $curlHeaders,
        CURLOPT_CONNECTTIMEOUT => KEY_EVENTS_DISPATCH_HTTP_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => KEY_EVENTS_DISPATCH_HTTP_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Pluriverse/4h key-events dispatcher',
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        return [true, $status, null];
    }
    // Return only the transport-level error (curl) here; advance() composes
    // the final "http_<status> ..." string so the HTTP status isn't doubled.
    $err = $cerr !== '' ? $cerr : null;
    return [false, $status > 0 ? $status : null, $err];
}

/**
 * Update the attempt row. On 'delivered': set delivered + nullify next_attempt.
 * On 'failed': bump attempt_count, schedule next_attempt_at per backoff. On
 * 'given_up': bump attempt_count, freeze the row (next_attempt_at NULL).
 *
 * last_error is truncated to fit the VARCHAR(1024) column.
 */
function key_events_dispatcher_advance(int $keyEventId, int $instanceId, string $finalState, ?int $httpStatus, ?string $error): void {
    $pdo = getDB();
    $now = date('Y-m-d H:i:s');

    if ($finalState === 'delivered') {
        $stmt = $pdo->prepare("
            UPDATE key_event_push_attempts
            SET delivery_status = 'delivered',
                attempt_count = attempt_count + 1,
                last_attempt_at = :ts,
                next_attempt_at = NULL,
                last_error = NULL
            WHERE key_event_id = :k AND instance_id = :i
        ");
        $stmt->execute([':ts' => $now, ':k' => $keyEventId, ':i' => $instanceId]);
        return;
    }

    $countStmt = $pdo->prepare("SELECT attempt_count FROM key_event_push_attempts WHERE key_event_id = :k AND instance_id = :i");
    $countStmt->execute([':k' => $keyEventId, ':i' => $instanceId]);
    $current = (int)$countStmt->fetchColumn();
    $next = $current + 1;
    $parts = [];
    if ($httpStatus !== null) $parts[] = 'http_' . $httpStatus;
    if ($error !== null && $error !== '') $parts[] = (string)$error;
    $errClipped = $parts === [] ? null : substr(implode(' ', $parts), 0, 1023);

    if ($finalState === 'given_up') {
        $stmt = $pdo->prepare("
            UPDATE key_event_push_attempts
            SET delivery_status = 'given_up',
                attempt_count = :n,
                last_attempt_at = :ts,
                next_attempt_at = NULL,
                last_error = :e
            WHERE key_event_id = :k AND instance_id = :i
        ");
        $stmt->execute([':n' => $next, ':ts' => $now, ':e' => $errClipped, ':k' => $keyEventId, ':i' => $instanceId]);
        return;
    }

    // failed (transient): schedule next attempt.
    $backoff = KEY_EVENTS_DISPATCH_BACKOFF[$next] ?? KEY_EVENTS_DISPATCH_BACKOFF[KEY_EVENTS_DISPATCH_GIVE_UP_AFTER];
    $nextAt = date('Y-m-d H:i:s', time() + $backoff);
    $stmt = $pdo->prepare("
        UPDATE key_event_push_attempts
        SET delivery_status = 'failed',
            attempt_count = :n,
            last_attempt_at = :ts,
            next_attempt_at = :nx,
            last_error = :e
        WHERE key_event_id = :k AND instance_id = :i
    ");
    $stmt->execute([':n' => $next, ':ts' => $now, ':nx' => $nextAt, ':e' => $errClipped, ':k' => $keyEventId, ':i' => $instanceId]);
}

/**
 * Snapshot of the queue for the --status CLI flag. Counts by state, plus
 * the head-of-line wait time.
 *
 * @return array{by_state:array<string,int>,overdue:int,head_of_line_seconds:?int}
 */
function key_events_dispatcher_queue_snapshot(): array {
    db_ensure_key_event_push_attempts_table();
    $pdo = getDB();
    $rows = $pdo->query("
        SELECT delivery_status, COUNT(*) AS n
        FROM key_event_push_attempts
        GROUP BY delivery_status
    ")->fetchAll(PDO::FETCH_ASSOC);
    $by = [];
    foreach ($rows as $r) $by[(string)$r['delivery_status']] = (int)$r['n'];

    $overdue = (int)$pdo->query("
        SELECT COUNT(*) FROM key_event_push_attempts
        WHERE delivery_status IN ('pending', 'failed')
          AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
    ")->fetchColumn();

    $headStmt = $pdo->query("
        SELECT TIMESTAMPDIFF(SECOND, next_attempt_at, NOW()) AS wait
        FROM key_event_push_attempts
        WHERE delivery_status IN ('pending', 'failed')
          AND next_attempt_at IS NOT NULL AND next_attempt_at <= NOW()
        ORDER BY next_attempt_at ASC
        LIMIT 1
    ");
    $headRow = $headStmt->fetch(PDO::FETCH_ASSOC);
    $head = $headRow !== false ? (int)$headRow['wait'] : null;

    return ['by_state' => $by, 'overdue' => $overdue, 'head_of_line_seconds' => $head];
}
