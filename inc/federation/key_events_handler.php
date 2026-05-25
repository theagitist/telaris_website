<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/key-events.json
 *
 * Public read of the signed key-rotation event log. Other peers pull
 * this as a 5-minute fallback for the push-based compromise channel
 * (see v10 plan: /api/pluriverse/key-events-push for the live channel,
 * this endpoint for the pull fallback).
 *
 * Each entry is the raw `signed_payload` blob from key_events_signed,
 * which is itself a JWS Compact Serialization (header.payload.signature)
 * signed by either the affected instance (for that instance's own
 * rotation) or by the Pluriverse coord key (for revocations). Peers
 * verify each entry's signature individually before applying.
 *
 * Querystring:
 *   ?since=<rfc3339>   filter to events with occurred_at >= since
 *
 * Caching: ETag + Last-Modified, Cache-Control: public, max-age=300.
 * Conditional GET yields 304. Empty until the first key rotation.
 */

require_once dirname(__DIR__, 2) . '/config.php';

try {
    $pdo = getDB();
    db_ensure_key_events_signed_table();

    $since = (string)($_GET['since'] ?? '');
    $sinceTs = null;
    if ($since !== '') {
        $sinceTs = strtotime($since);
        if ($sinceTs === false) {
            federation_router_problem(400, 'invalid_since', "Querystring 'since' must be an RFC 3339 timestamp.", '/api/pluriverse/key-events.json');
            return;
        }
    }

    if ($sinceTs !== null) {
        $stmt = $pdo->prepare("
            SELECT origin_host, event_type, occurred_at, signed_payload, created_at
            FROM key_events_signed
            WHERE occurred_at >= FROM_UNIXTIME(:t)
            ORDER BY occurred_at ASC, id ASC
            LIMIT 1000
        ");
        $stmt->execute([':t' => $sinceTs]);
    } else {
        $stmt = $pdo->query("
            SELECT origin_host, event_type, occurred_at, signed_payload, created_at
            FROM key_events_signed
            ORDER BY occurred_at ASC, id ASC
            LIMIT 1000
        ");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('key_events_handler: query failed: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not read the key-events feed.', '/api/pluriverse/key-events.json');
    return;
}

$maxCreated = 0;
$events = [];
foreach ($rows as $r) {
    $events[] = [
        'origin_host' => (string)$r['origin_host'],
        'event_type' => (string)$r['event_type'],
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$r['occurred_at'])),
        'signed_payload' => (string)$r['signed_payload'],
    ];
    $c = strtotime((string)$r['created_at']);
    if ($c !== false && $c > $maxCreated) $maxCreated = $c;
}

$dataPayload = ['version' => '1.0', 'count' => count($events), 'events' => $events];
$etag = '"' . substr(hash('sha256', json_encode($dataPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16) . '"';

$body = json_encode([
    'version' => '1.0',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => count($events),
    'events' => $events,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$lastModified = $maxCreated > 0 ? gmdate('D, d M Y H:i:s', $maxCreated) . ' GMT' : null;

$ifNoneMatch = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifNoneMatchStripped = preg_replace('/^W\//', '', $ifNoneMatch);
if ($ifNoneMatchStripped !== '' && hash_equals($etag, $ifNoneMatchStripped)) {
    http_response_code(304);
    header('ETag: ' . $etag);
    if ($lastModified !== null) header('Last-Modified: ' . $lastModified);
    header('Cache-Control: public, max-age=300');
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('ETag: ' . $etag);
if ($lastModified !== null) header('Last-Modified: ' . $lastModified);
header('Cache-Control: public, max-age=300');
echo $body;
