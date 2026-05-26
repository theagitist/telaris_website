<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/relay
 *
 * First-contact handshake relay. Instance A POSTs a pre-signed JWS envelope
 * here addressed to instance B; the Pluriverse looks up B (its operator's
 * email is encrypted in our DB and not visible to A), forwards the JWS
 * unchanged to B's /api/pluriverse/messages with a fresh outer HTTP-Sig
 * coord-signed (tag = tel-relay), and emails B's operator a brief
 * notification with a deep link to their own admin Pluriverse panel.
 *
 * The notification email reveals only what's already public on the
 * Pluriverse directory: A's hostname + label. No body excerpt, no
 * thread_id, no operator-of-A address. The body itself reaches B via the
 * signed channel, where B's admin can decide whether to read or refuse.
 *
 * Wire shape (set by instance-side inc/federation/dispatcher.php at
 * v6.11.26):
 *
 *   POST /api/pluriverse/relay
 *   HTTP-Sig: A's instance key, tag = tel-relay
 *   Body: {"recipient_hostname": "<B>", "envelope": "<A-signed JWS>"}
 *
 * The relay is a *forwarder*, not a re-signer. A built and signed the JWS;
 * the relay keeps it bit-identical to bind A's authorship at every hop.
 * Only the outer HTTP-Sig (transport layer) is coord-signed.
 *
 * Retry policy: this handler is synchronous. A's dispatcher (v6.11.26)
 * already retries the relay POST on transient failure, so adding a
 * Pluriverse-side queue duplicates the work without payoff at this stage.
 *
 * Spec: P2P federation plan v10 § Layer 3 → Pluriverse relay; Stage 4
 *       handshake design § Pluriverse-side relay (4g).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/db_federation.php';
require_once dirname(__DIR__) . '/mail.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/identity_client.php';
require_once __DIR__ . '/http_sig.php';
require_once __DIR__ . '/jws_peek.php';
require_once __DIR__ . '/pii.php';

// -----------------------------------------------------------------------
// Rate limit. The signed-only nature of this endpoint means accidental
// floods come from buggy peer dispatchers; 60/hour/IP is generous for a
// healthy peer and tight enough to short-circuit a runaway loop.
// -----------------------------------------------------------------------
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_relay:' . date('YmdH') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 3700);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(
            429,
            'rate_limited',
            'Too many relay POSTs from this IP this hour; retry within an hour.',
            '/api/pluriverse/relay'
        );
        return;
    }
}

// -----------------------------------------------------------------------
// Read body once. Needed both for signature verification (content digest)
// and for JSON parsing. Cap at 80 KB (JWS envelope 64 KB max, plus the
// wrapping JSON's recipient_hostname + JSON syntax overhead).
// -----------------------------------------------------------------------
$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    federation_router_problem(400, 'empty_body', 'Request body is empty; expected JSON.', '/api/pluriverse/relay');
    return;
}
if (strlen($raw) > 80 * 1024) {
    federation_router_problem(413, 'body_too_large', 'Request body exceeds 80 KB.', '/api/pluriverse/relay');
    return;
}

// -----------------------------------------------------------------------
// Step 1: extract keyid from Signature-Input (same pattern as apply_handler).
// -----------------------------------------------------------------------
$sigInput = $_SERVER['HTTP_SIGNATURE_INPUT'] ?? '';
$sigValue = $_SERVER['HTTP_SIGNATURE'] ?? '';
if ($sigInput === '' || $sigValue === '') {
    federation_router_problem(
        401,
        'signature_required',
        'POST /api/pluriverse/relay requires an RFC 9421 HTTP Signature from your instance pluriverse.key with tag = tel-relay.',
        '/api/pluriverse/relay'
    );
    return;
}
[$inputLabel, $inputRest] = federation_http_sig_split_label($sigInput);
if ($inputLabel !== 'sig1') {
    federation_router_problem(401, 'invalid_signature', 'Unsupported Signature-Input label.', '/api/pluriverse/relay');
    return;
}
$parsed = federation_http_sig_parse_inner_list($inputRest);
if ($parsed === null) {
    federation_router_problem(401, 'invalid_signature', 'Malformed Signature-Input inner list.', '/api/pluriverse/relay');
    return;
}
[, $sigParams] = $parsed;
$keyid = isset($sigParams['keyid']) && is_string($sigParams['keyid']) ? $sigParams['keyid'] : '';
if ($keyid === '' || strpos($keyid, ':') === false) {
    federation_router_problem(401, 'invalid_keyid', 'Signature keyid must be "<hostname>:<fingerprint>".', '/api/pluriverse/relay');
    return;
}
[$signerHostname, $signerFingerprint] = explode(':', $keyid, 2);
$signerHostname = strtolower(trim($signerHostname));
$signerFingerprint = trim($signerFingerprint);
if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $signerHostname) || strlen($signerHostname) > 255) {
    federation_router_problem(401, 'invalid_keyid', 'Signature keyid hostname is malformed.', '/api/pluriverse/relay');
    return;
}

// -----------------------------------------------------------------------
// Step 2: A must be a published instance in our directory. We resolve
// A's public key via the cached row (NOT via a fresh identity fetch);
// the cached `public_key` was captured at apply time and refreshed by
// the rotation flow. A peer that has not been admitted as `published`
// cannot use the relay (it can still apply via /operators/apply).
// -----------------------------------------------------------------------
try {
    db_ensure_instances_table();
    $stmt = getDB()->prepare("
        SELECT id, hostname, public_key, previous_public_key, label, admission_status
        FROM instances WHERE hostname = :h LIMIT 1
    ");
    $stmt->execute([':h' => $signerHostname]);
    $signerRow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('relay: signer lookup: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not resolve the signer; please retry shortly.', '/api/pluriverse/relay');
    return;
}
if ($signerRow === false) {
    federation_router_problem(
        404,
        'signer_not_in_directory',
        "Instance {$signerHostname} is not in the Pluriverse directory. Apply via /api/pluriverse/operators/apply first.",
        '/api/pluriverse/relay'
    );
    return;
}
if ((string)$signerRow['admission_status'] !== 'published') {
    federation_router_problem(
        403,
        'signer_not_published',
        "Instance {$signerHostname} is in the directory but not currently published (status: {$signerRow['admission_status']}). The relay is only available to published peers.",
        '/api/pluriverse/relay'
    );
    return;
}
$signerPubKey = (string)$signerRow['public_key'];
$signerPrevPubKey = $signerRow['previous_public_key'] !== null ? (string)$signerRow['previous_public_key'] : null;

// Fingerprint check: keyid fingerprint must match current OR (if recently
// rotated) previous public key. Defends against a stale fingerprint claim
// being accepted because the bytes happen to match.
$currentFp = federation_compute_fingerprint($signerPubKey);
$prevFp = $signerPrevPubKey !== null ? federation_compute_fingerprint($signerPrevPubKey) : null;
$matchedPubKey = null;
if (hash_equals($currentFp, $signerFingerprint)) {
    $matchedPubKey = $signerPubKey;
} elseif ($prevFp !== null && hash_equals($prevFp, $signerFingerprint)) {
    $matchedPubKey = $signerPrevPubKey;
}
if ($matchedPubKey === null) {
    federation_router_problem(
        401,
        'fingerprint_mismatch',
        "The keyid fingerprint does not match the public key on file for {$signerHostname}. If you rotated, push the rotation event first; if you haven't, sign with the current key.",
        '/api/pluriverse/relay'
    );
    return;
}

// -----------------------------------------------------------------------
// Step 3: RFC 9421 verify with the resolved key. Expected tag: tel-relay.
// -----------------------------------------------------------------------
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$verifyRequest = [
    'method' => 'POST',
    'target_uri' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.telaris.ca') . ($_SERVER['REQUEST_URI'] ?? '/api/pluriverse/relay'),
    'headers' => [
        'signature'       => $sigValue,
        'signature-input' => $sigInput,
        'host'            => $_SERVER['HTTP_HOST'] ?? '',
        'date'            => $_SERVER['HTTP_DATE'] ?? '',
        'content-type'    => $_SERVER['CONTENT_TYPE'] ?? '',
        'content-length'  => $_SERVER['CONTENT_LENGTH'] ?? (string)strlen($raw),
        'content-digest'  => $_SERVER['HTTP_CONTENT_DIGEST'] ?? '',
    ],
    'body' => $raw,
];
$verify = federation_http_sig_verify($verifyRequest, $matchedPubKey, ['expected_tag' => 'tel-relay']);
if (!$verify['valid']) {
    federation_router_problem(
        401,
        'signature_invalid',
        'Signature verification failed: ' . $verify['reason'],
        '/api/pluriverse/relay'
    );
    return;
}

// -----------------------------------------------------------------------
// Step 4: parse + validate body.
// -----------------------------------------------------------------------
try {
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    federation_router_problem(400, 'invalid_json', 'Request body is not valid JSON: ' . $e->getMessage(), '/api/pluriverse/relay');
    return;
}
if (!is_array($body)) {
    federation_router_problem(400, 'invalid_body', 'Request body must be a JSON object.', '/api/pluriverse/relay');
    return;
}

$recipientHostname = strtolower(trim((string)($body['recipient_hostname'] ?? '')));
if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $recipientHostname) || strlen($recipientHostname) > 255) {
    federation_router_problem(400, 'invalid_recipient_hostname', 'recipient_hostname is missing or malformed.', '/api/pluriverse/relay');
    return;
}
if ($recipientHostname === $signerHostname) {
    federation_router_problem(400, 'self_recipient', 'A peer cannot relay a message to itself through the Pluriverse.', '/api/pluriverse/relay');
    return;
}

$envelope = (string)($body['envelope'] ?? '');
if ($envelope === '') {
    federation_router_problem(400, 'missing_envelope', 'envelope field is required.', '/api/pluriverse/relay');
    return;
}
if (strlen($envelope) > 64 * 1024) {
    federation_router_problem(413, 'envelope_too_large', 'envelope exceeds 64 KB.', '/api/pluriverse/relay');
    return;
}

// -----------------------------------------------------------------------
// Step 5: JWS peek. We do NOT verify A's signature on the inner JWS
// (B will, on receipt). We DO confirm the routing claims line up so we
// can't be tricked into hand-delivering one peer's message to another.
// -----------------------------------------------------------------------
$peek = federation_jws_peek($envelope);
if ($peek === null) {
    federation_router_problem(400, 'malformed_envelope', 'envelope is not a well-formed JWS Compact Serialization.', '/api/pluriverse/relay');
    return;
}
$innerKid = isset($peek['header']['kid']) && is_string($peek['header']['kid']) ? $peek['header']['kid'] : '';
$innerKidHost = '';
if ($innerKid !== '' && strpos($innerKid, ':') !== false) {
    $innerKidHost = strtolower(trim(substr($innerKid, 0, (int)strrpos($innerKid, ':'))));
}
if ($innerKidHost === '' || $innerKidHost !== $signerHostname) {
    federation_router_problem(
        400,
        'inner_outer_signer_mismatch',
        'Inner JWS kid host must match the outer HTTP signer hostname. The relay does not deliver messages signed by one peer on behalf of another.',
        '/api/pluriverse/relay'
    );
    return;
}
$innerSenderHost = isset($peek['payload']['sender_host']) && is_string($peek['payload']['sender_host']) ? strtolower((string)$peek['payload']['sender_host']) : '';
$innerRecipientHost = isset($peek['payload']['recipient_host']) && is_string($peek['payload']['recipient_host']) ? strtolower((string)$peek['payload']['recipient_host']) : '';
if ($innerSenderHost !== $signerHostname) {
    federation_router_problem(400, 'inner_sender_mismatch', 'Inner JWS payload sender_host must match the outer HTTP signer hostname.', '/api/pluriverse/relay');
    return;
}
if ($innerRecipientHost !== $recipientHostname) {
    federation_router_problem(400, 'inner_recipient_mismatch', 'Inner JWS payload recipient_host must match the body recipient_hostname.', '/api/pluriverse/relay');
    return;
}
$messageType = isset($peek['payload']['message_type']) && is_string($peek['payload']['message_type']) ? (string)$peek['payload']['message_type'] : '';

// -----------------------------------------------------------------------
// Step 6: B must be a published instance too.
// -----------------------------------------------------------------------
try {
    $stmt = getDB()->prepare("
        SELECT id, hostname, url, operator_email_enc, operator_email_lookup_hash, label, locale, admission_status
        FROM instances WHERE hostname = :h LIMIT 1
    ");
    $stmt->execute([':h' => $recipientHostname]);
    $recipientRow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('relay: recipient lookup: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not resolve the recipient; please retry shortly.', '/api/pluriverse/relay');
    return;
}
if ($recipientRow === false) {
    federation_router_problem(
        404,
        'recipient_not_in_directory',
        "Instance {$recipientHostname} is not in the Pluriverse directory. Only published peers can be reached via the relay.",
        '/api/pluriverse/relay'
    );
    return;
}
if ((string)$recipientRow['admission_status'] !== 'published') {
    federation_router_problem(
        403,
        'recipient_not_published',
        "Instance {$recipientHostname} is in the directory but not currently published (status: {$recipientRow['admission_status']}). The relay only routes to published peers.",
        '/api/pluriverse/relay'
    );
    return;
}

// -----------------------------------------------------------------------
// Step 7: forward the JWS unchanged to B. Outer HTTP-Sig is coord-signed
// with tag = tel-relay; B's messages_handler dispatches on the coord-tag
// path and verifies the inner JWS against its own peer cache for A.
// -----------------------------------------------------------------------
try {
    $coordSecret = federation_load_coord_secret_key();
    $coordKeyid = federation_coord_keyid(); // www.telaris.ca:<fp>
} catch (Throwable $e) {
    error_log('relay: coord key load: ' . $e->getMessage());
    federation_router_problem(503, 'coord_key_unavailable', 'Pluriverse coordination key is not loaded; relay temporarily unavailable.', '/api/pluriverse/relay');
    return;
}

$downstreamBody = (string)json_encode(['envelope' => $envelope], JSON_UNESCAPED_SLASHES);
$downstreamUrl = 'https://' . $recipientHostname . '/api/pluriverse/messages';
$downstreamHeaders = [
    'Host' => $recipientHostname,
    'Date' => gmdate('D, d M Y H:i:s') . ' GMT',
    'Content-Type' => 'application/json',
];
$signed = federation_http_sig_sign(
    ['method' => 'POST', 'target_uri' => $downstreamUrl, 'headers' => $downstreamHeaders, 'body' => $downstreamBody],
    $coordSecret,
    ['keyid' => $coordKeyid, 'tag' => 'tel-relay']
);
$downstreamHeaders['Content-Digest'] = $signed['content_digest'] ?? federation_http_sig_content_digest($downstreamBody);
$downstreamHeaders['Content-Length'] = (string)strlen($downstreamBody);
$downstreamHeaders['Signature-Input'] = $signed['signature_input'];
$downstreamHeaders['Signature'] = $signed['signature'];

$curlHeaders = [];
foreach ($downstreamHeaders as $k => $v) $curlHeaders[] = $k . ': ' . $v;

$ch = curl_init($downstreamUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $downstreamBody,
    CURLOPT_HTTPHEADER => $curlHeaders,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'Pluriverse/4g relay',
]);
$downstreamResponse = curl_exec($ch);
$downstreamStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$downstreamError = curl_error($ch);
curl_close($ch);

// Best-effort pluriverse_log entry whether or not delivery succeeded; lets
// later audits trace relay attempts without needing per-row state.
try {
    db_ensure_pluriverse_log_tables();
    $outcome = ($downstreamStatus >= 200 && $downstreamStatus < 300) ? 'success' : 'failure';
    $summary = 'relay ' . $signerHostname . ' -> ' . $recipientHostname . ' status=' . $downstreamStatus;
    if ($messageType !== '') $summary .= ' type=' . substr($messageType, 0, 64);
    if ($downstreamError !== '') $summary .= ' err=' . substr($downstreamError, 0, 200);
    $log = getDB()->prepare("
        INSERT INTO pluriverse_log (event_type, actor, target, outcome, details_summary)
        VALUES ('relay_forward', :a, :t, :o, :d)
    ");
    $log->execute([
        ':a' => $signerHostname,
        ':t' => $recipientHostname,
        ':o' => $outcome,
        ':d' => $summary,
    ]);
} catch (Throwable $e) {
    error_log('relay: pluriverse_log INSERT failed (non-fatal): ' . $e->getMessage());
}

if (!($downstreamStatus >= 200 && $downstreamStatus < 300)) {
    federation_router_problem(
        502,
        'downstream_post_failed',
        $downstreamError !== ''
            ? "Could not deliver to {$recipientHostname}: {$downstreamError}"
            : "{$recipientHostname} responded {$downstreamStatus} to /api/pluriverse/messages. Retry from your instance dispatcher.",
        '/api/pluriverse/relay'
    );
    return;
}

// -----------------------------------------------------------------------
// Step 8: best-effort notification email to B's operator. The email
// carries A's public hostname + label and B's admin URL; nothing else.
// No body excerpt, no thread_id, no operator-of-A address. The message
// itself rides the signed channel; B's admin reads it there.
// -----------------------------------------------------------------------
$emailLookupHash = (string)$recipientRow['operator_email_lookup_hash'];
$recipientEmail = null;
try {
    $rowContext = federation_row_context_for_instance($emailLookupHash);
    $recipientEmail = federation_pii_decrypt((string)$recipientRow['operator_email_enc'], $rowContext, 'email');
} catch (Throwable $e) {
    error_log("relay: operator email decrypt failed for instance {$recipientRow['id']}: " . $e->getMessage());
}
if (is_string($recipientEmail) && $recipientEmail !== '') {
    $emailLocale = in_array((string)$recipientRow['locale'], ['en', 'es', 'pt', 'fr'], true) ? (string)$recipientRow['locale'] : 'en';
    $senderLabel = (string)$signerRow['label'];
    $recipientBaseUrl = rtrim((string)$recipientRow['url'], '/');
    $adminUrl = $recipientBaseUrl . '/admin/?tab=pluriverse';
    $tpl = relay_notification_template(
        $emailLocale,
        $senderLabel,
        $signerHostname,
        $adminUrl
    );
    try {
        pluriverse_send_mail($recipientEmail, $tpl['subject'], $tpl['body']);
    } catch (Throwable $e) {
        error_log("relay: notification mail to operator of {$recipientHostname} failed (non-fatal): " . $e->getMessage());
    }
    if (function_exists('sodium_memzero')) {
        sodium_memzero($recipientEmail);
    }
}

http_response_code(202);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status' => 'delivered',
    'recipient_hostname' => $recipientHostname,
    'message' => 'JWS forwarded to recipient and notification email queued. The recipient will see the message in their admin Inbox.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

/**
 * Locale-aware notification email content. Reveals only what's already
 * public in the Pluriverse directory: sender hostname + sender label.
 * No body excerpt, no thread_id, no operator-of-sender address. The
 * actual message rides the signed channel and is read by the recipient
 * in their own admin Pluriverse panel.
 *
 * @return array{subject:string, body:string}
 */
function relay_notification_template(string $locale, string $senderLabel, string $senderHostname, string $adminUrl): array {
    $tpls = [
        'en' => [
            'subject' => 'New Pluriverse message from ' . $senderLabel,
            'body' => "Hello,\n\n"
                    . "The Telaris instance \"{$senderLabel}\" ({$senderHostname}) has\n"
                    . "sent your instance a Pluriverse message. The contents are\n"
                    . "available only in your admin Pluriverse panel; sign in to read\n"
                    . "and decide whether to respond.\n\n"
                    . "  {$adminUrl}\n\n"
                    . "This email is a notification only; it does not carry the\n"
                    . "message body, the sender's contact details, or any routing\n"
                    . "identifiers. Those reach you through your instance's signed\n"
                    . "federation channel.\n\n"
                    . "Pluriverse - https://www.telaris.ca/\n",
        ],
        'es' => [
            'subject' => 'Nuevo mensaje de la Pluriverse de ' . $senderLabel,
            'body' => "Hola,\n\n"
                    . "La instancia Telaris \"{$senderLabel}\" ({$senderHostname}) ha\n"
                    . "enviado un mensaje de la Pluriverse a tu instancia. El contenido\n"
                    . "solo está disponible en tu panel administrativo de la Pluriverse;\n"
                    . "inicia sesión para leerlo y decidir si responder.\n\n"
                    . "  {$adminUrl}\n\n"
                    . "Este correo es solo una notificación; no incluye el cuerpo del\n"
                    . "mensaje, los datos de contacto del remitente, ni identificadores\n"
                    . "de enrutamiento. Esos llegan a tu instancia por el canal federado\n"
                    . "firmado.\n\n"
                    . "Pluriverse - https://www.telaris.ca/\n",
        ],
        'pt' => [
            'subject' => 'Nova mensagem da Pluriverse de ' . $senderLabel,
            'body' => "Olá,\n\n"
                    . "A instância Telaris \"{$senderLabel}\" ({$senderHostname}) enviou\n"
                    . "uma mensagem da Pluriverse para sua instância. O conteúdo só está\n"
                    . "disponível no seu painel administrativo da Pluriverse; entre para\n"
                    . "lê-la e decidir se responde.\n\n"
                    . "  {$adminUrl}\n\n"
                    . "Este email é apenas uma notificação; não inclui o corpo da\n"
                    . "mensagem, os dados de contato do remetente nem identificadores\n"
                    . "de roteamento. Esses chegam à sua instância pelo canal federado\n"
                    . "assinado.\n\n"
                    . "Pluriverse - https://www.telaris.ca/\n",
        ],
        'fr' => [
            'subject' => 'Nouveau message de la Pluriverse de ' . $senderLabel,
            'body' => "Bonjour,\n\n"
                    . "L'instance Telaris « {$senderLabel} » ({$senderHostname}) a envoyé\n"
                    . "un message de la Pluriverse à ton instance. Le contenu n'est\n"
                    . "disponible que dans ton panneau administratif Pluriverse ;\n"
                    . "connecte-toi pour le lire et décider d'y répondre.\n\n"
                    . "  {$adminUrl}\n\n"
                    . "Ce courriel n'est qu'une notification ; il ne contient ni le\n"
                    . "corps du message, ni les coordonnées de l'expéditeur, ni\n"
                    . "d'identifiants de routage. Tout cela arrive à ton instance par\n"
                    . "le canal fédéré signé.\n\n"
                    . "Pluriverse - https://www.telaris.ca/\n",
        ],
    ];
    return $tpls[$locale] ?? $tpls['en'];
}
