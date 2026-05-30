<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/peer-blacklist-notice
 *
 * Advisory peer-blacklist report (stage 6e). When an operator locally blocks a
 * peer on their instance, the instance sends this courtesy notice so a
 * Pluriverse admin can review patterns (e.g. many instances reporting the same
 * peer). It is ADVISORY ONLY: v10 keeps notices non-binding; the Pluriverse
 * does not auto-blacklist or propagate anything from a single report. We just
 * record it in anomaly_log for human review.
 *
 * Auth: an RFC 9421 HTTP Signature from a PUBLISHED instance's pluriverse.key
 * (keyid "<hostname>:<fingerprint>", tag = tel-bl-notice). The reporter is the
 * signer; it is taken from the keyid, never from the body, so an instance
 * cannot file a report as someone else.
 *
 * Wire shape (instance-side inc/federation/peer_blacklist_notice.php +
 * dispatcher classify, tag tel-bl-notice):
 *
 *   POST /api/pluriverse/peer-blacklist-notice
 *   HTTP-Sig: reporter instance key, tag = tel-bl-notice
 *   Body: {"blacklisted_hostname": "<peer>", "blacklisted_at": "<iso8601>",
 *          "reason": "<text>", "category": "spam|harmful|legal|consent|other"}
 *
 * Rate limit: 60/hour/IP cheap guard, then 30/hour per verified reporter.
 *
 * Spec: P2P federation plan v10 § Blacklist mechanics ("notices are advisory");
 *       Stage 6 trust revocation design (6e).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/db_federation.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/http_sig.php';

// -----------------------------------------------------------------------
// Cheap IP DoS guard (the per-reporter limit below is the real one).
// -----------------------------------------------------------------------
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_blnotice_ip:' . date('YmdH') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 3700);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(429, 'rate_limited', 'Too many notices from this IP this hour; retry within an hour.', '/api/pluriverse/peer-blacklist-notice');
        return;
    }
}

// -----------------------------------------------------------------------
// Read body (small: a hostname + a short reason + a category).
// -----------------------------------------------------------------------
$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    federation_router_problem(400, 'empty_body', 'Request body is empty; expected JSON.', '/api/pluriverse/peer-blacklist-notice');
    return;
}
if (strlen($raw) > 8 * 1024) {
    federation_router_problem(413, 'body_too_large', 'Request body exceeds 8 KB.', '/api/pluriverse/peer-blacklist-notice');
    return;
}

// -----------------------------------------------------------------------
// Extract keyid from Signature-Input (same pattern as relay_handler).
// -----------------------------------------------------------------------
$sigInput = $_SERVER['HTTP_SIGNATURE_INPUT'] ?? '';
$sigValue = $_SERVER['HTTP_SIGNATURE'] ?? '';
if ($sigInput === '' || $sigValue === '') {
    federation_router_problem(401, 'signature_required', 'POST /api/pluriverse/peer-blacklist-notice requires an RFC 9421 HTTP Signature from your instance pluriverse.key with tag = tel-bl-notice.', '/api/pluriverse/peer-blacklist-notice');
    return;
}
[$inputLabel, $inputRest] = federation_http_sig_split_label($sigInput);
if ($inputLabel !== 'sig1') {
    federation_router_problem(401, 'invalid_signature', 'Unsupported Signature-Input label.', '/api/pluriverse/peer-blacklist-notice');
    return;
}
$parsed = federation_http_sig_parse_inner_list($inputRest);
if ($parsed === null) {
    federation_router_problem(401, 'invalid_signature', 'Malformed Signature-Input inner list.', '/api/pluriverse/peer-blacklist-notice');
    return;
}
[, $sigParams] = $parsed;
$keyid = isset($sigParams['keyid']) && is_string($sigParams['keyid']) ? $sigParams['keyid'] : '';
if ($keyid === '' || strpos($keyid, ':') === false) {
    federation_router_problem(401, 'invalid_keyid', 'Signature keyid must be "<hostname>:<fingerprint>".', '/api/pluriverse/peer-blacklist-notice');
    return;
}
[$signerHostname, $signerFingerprint] = explode(':', $keyid, 2);
$signerHostname = strtolower(trim($signerHostname));
$signerFingerprint = trim($signerFingerprint);
if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $signerHostname) || strlen($signerHostname) > 255) {
    federation_router_problem(401, 'invalid_keyid', 'Signature keyid hostname is malformed.', '/api/pluriverse/peer-blacklist-notice');
    return;
}

// -----------------------------------------------------------------------
// Per-reporter rate limit (30/hour). Keyed by the claimed signer; the cheap
// IP guard above bounds attempts to pollute another peer's bucket.
// -----------------------------------------------------------------------
if (function_exists('apcu_inc')) {
    $bucket = 'pluriverse_blnotice_peer:' . date('YmdH') . ':' . $signerHostname;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 3700);
    if ($count !== false && (int)$count > 30) {
        federation_router_problem(429, 'rate_limited', 'Too many notices from this instance this hour (limit 30); retry within an hour.', '/api/pluriverse/peer-blacklist-notice');
        return;
    }
}

// -----------------------------------------------------------------------
// Reporter must be a published instance. Resolve its key from the cached row.
// -----------------------------------------------------------------------
try {
    db_ensure_instances_table();
    $stmt = getDB()->prepare("
        SELECT id, hostname, public_key, previous_public_key, admission_status
        FROM instances WHERE hostname = :h LIMIT 1
    ");
    $stmt->execute([':h' => $signerHostname]);
    $signerRow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('peer-blacklist-notice: signer lookup: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not resolve the signer; please retry shortly.', '/api/pluriverse/peer-blacklist-notice');
    return;
}
if ($signerRow === false) {
    federation_router_problem(404, 'signer_not_in_directory', "Instance {$signerHostname} is not in the Pluriverse directory.", '/api/pluriverse/peer-blacklist-notice');
    return;
}
if ((string)$signerRow['admission_status'] !== 'published') {
    federation_router_problem(403, 'signer_not_published', "Instance {$signerHostname} is not currently published; only published peers may file notices.", '/api/pluriverse/peer-blacklist-notice');
    return;
}
$signerPubKey = (string)$signerRow['public_key'];
$signerPrevPubKey = $signerRow['previous_public_key'] !== null ? (string)$signerRow['previous_public_key'] : null;

// Fingerprint must match the current or (rotation grace) previous key.
$currentFp = federation_compute_fingerprint($signerPubKey);
$prevFp = $signerPrevPubKey !== null ? federation_compute_fingerprint($signerPrevPubKey) : null;
$matchedPubKey = null;
if (hash_equals($currentFp, $signerFingerprint)) {
    $matchedPubKey = $signerPubKey;
} elseif ($prevFp !== null && hash_equals($prevFp, $signerFingerprint)) {
    $matchedPubKey = $signerPrevPubKey;
}
if ($matchedPubKey === null) {
    federation_router_problem(401, 'fingerprint_mismatch', "The keyid fingerprint does not match the public key on file for {$signerHostname}.", '/api/pluriverse/peer-blacklist-notice');
    return;
}

// -----------------------------------------------------------------------
// RFC 9421 verify with the resolved key. Expected tag: tel-bl-notice.
// -----------------------------------------------------------------------
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$verifyRequest = [
    'method' => 'POST',
    'target_uri' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.telaris.ca') . ($_SERVER['REQUEST_URI'] ?? '/api/pluriverse/peer-blacklist-notice'),
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
$verify = federation_http_sig_verify($verifyRequest, $matchedPubKey, ['expected_tag' => 'tel-bl-notice']);
if (!$verify['valid']) {
    federation_router_problem(401, 'signature_invalid', 'Signature verification failed: ' . $verify['reason'], '/api/pluriverse/peer-blacklist-notice');
    return;
}

// -----------------------------------------------------------------------
// Parse + validate the report body.
// -----------------------------------------------------------------------
try {
    $body = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    federation_router_problem(400, 'invalid_json', 'Request body is not valid JSON: ' . $e->getMessage(), '/api/pluriverse/peer-blacklist-notice');
    return;
}
if (!is_array($body)) {
    federation_router_problem(400, 'invalid_body', 'Request body must be a JSON object.', '/api/pluriverse/peer-blacklist-notice');
    return;
}

$blacklistedHostname = strtolower(trim((string)($body['blacklisted_hostname'] ?? '')));
if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $blacklistedHostname) || strlen($blacklistedHostname) > 255) {
    federation_router_problem(400, 'invalid_blacklisted_hostname', 'blacklisted_hostname is missing or malformed.', '/api/pluriverse/peer-blacklist-notice');
    return;
}
$allowedCategories = ['spam', 'harmful', 'legal', 'consent', 'other'];
$category = strtolower(trim((string)($body['category'] ?? 'other')));
if (!in_array($category, $allowedCategories, true)) {
    $category = 'other';
}
$reason = trim((string)($body['reason'] ?? ''));
if (strlen($reason) > 800) {
    $reason = substr($reason, 0, 800);
}

// -----------------------------------------------------------------------
// Record in anomaly_log for human review. instance_id = the REPORTER; the
// reported peer hostname + category + reason live in details_summary. This
// is the only side effect: no auto-blacklist, no propagation.
// -----------------------------------------------------------------------
try {
    db_ensure_anomaly_log_table();
    $summary = 'reported=' . $blacklistedHostname . '; category=' . $category;
    if ($reason !== '') {
        $summary .= '; reason=' . $reason;
    }
    $log = getDB()->prepare("
        INSERT INTO anomaly_log (instance_id, anomaly_type, severity, details_summary)
        VALUES (:iid, 'peer_blacklist_report', 'info', :d)
    ");
    $log->execute([
        ':iid' => (int)$signerRow['id'],
        ':d' => substr($summary, 0, 1024),
    ]);
} catch (Throwable $e) {
    error_log('peer-blacklist-notice: anomaly_log INSERT failed: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not record the notice; please retry shortly.', '/api/pluriverse/peer-blacklist-notice');
    return;
}

http_response_code(202);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status' => 'recorded',
    'message' => 'Advisory peer-blacklist notice recorded for Pluriverse admin review. Notices are advisory only and trigger no automatic network action.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
