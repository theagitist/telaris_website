<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/operators/status
 *
 * An instance asks the Pluriverse "what is my admission_status right now?".
 * The instance signs the request with its pluriverse.key (RFC 9421,
 * tag=pluriverse-status); the Pluriverse identifies the instance by the
 * signer hostname extracted from the keyid (`<hostname>:<fingerprint>`).
 *
 * Why signed-only:
 *   - The Pluriverse never serves cross-instance status (no operator
 *     should be able to ask "what's hostname X's status?"). Signing
 *     binds the request to the asking instance.
 *   - No data fields in the response are sensitive beyond what a peer
 *     could see in /api/pluriverse/peers.json (for published rows). The
 *     value of signing is access control on the *non-published* states.
 *
 * Response on 200:
 *   {
 *     "hostname": "...",
 *     "admission_status": "pending|verified|published|rejected|...",
 *     "is_highlighted": true|false,
 *     "updated_at": "ISO8601"
 *   }
 *
 * Errors (Problem Details): 401 if signature missing/invalid; 422 if the
 * signer's identity envelope cannot be validated; 404 if no instance row
 * exists for the signer hostname.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/identity_client.php';
require_once __DIR__ . '/http_sig.php';

$sigInput = $_SERVER['HTTP_SIGNATURE_INPUT'] ?? '';
$sigValue = $_SERVER['HTTP_SIGNATURE'] ?? '';
if ($sigInput === '' || $sigValue === '') {
    federation_router_problem(
        401,
        'signature_required',
        'GET /api/pluriverse/operators/status requires an RFC 9421 HTTP Signature from your instance\'s pluriverse.key.',
        '/api/pluriverse/operators/status'
    );
    return;
}

[$inputLabel, $inputRest] = federation_http_sig_split_label($sigInput);
if ($inputLabel !== 'sig1') {
    federation_router_problem(401, 'invalid_signature', 'Unsupported Signature-Input label.', '/api/pluriverse/operators/status');
    return;
}
$parsed = federation_http_sig_parse_inner_list($inputRest);
if ($parsed === null) {
    federation_router_problem(401, 'invalid_signature', 'Malformed Signature-Input inner list.', '/api/pluriverse/operators/status');
    return;
}
[, $sigParams] = $parsed;
$keyid = isset($sigParams['keyid']) && is_string($sigParams['keyid']) ? $sigParams['keyid'] : '';
if ($keyid === '' || strpos($keyid, ':') === false) {
    federation_router_problem(401, 'invalid_keyid', 'Signature keyid must be "<hostname>:<fingerprint>".', '/api/pluriverse/operators/status');
    return;
}
[$signerHostname, $signerFingerprint] = explode(':', $keyid, 2);
$signerHostname = strtolower(trim($signerHostname));
$signerFingerprint = trim($signerFingerprint);
if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $signerHostname) || strlen($signerHostname) > 255) {
    federation_router_problem(401, 'invalid_keyid', 'Signature keyid hostname is malformed.', '/api/pluriverse/operators/status');
    return;
}

try {
    $identity = federation_fetch_identity('https://' . $signerHostname . '/api/pluriverse/identity');
} catch (FederationIdentityFetchError $e) {
    federation_router_problem(
        422,
        'identity_unverifiable',
        'Could not verify the signing instance: ' . $e->getMessage(),
        '/api/pluriverse/operators/status'
    );
    return;
}
if (!hash_equals($identity['public_key_fingerprint'], $signerFingerprint)) {
    federation_router_problem(
        401,
        'fingerprint_mismatch',
        "The instance at {$signerHostname} currently publishes a key with fingerprint {$identity['public_key_fingerprint']}, not the {$signerFingerprint} in the request keyid.",
        '/api/pluriverse/operators/status'
    );
    return;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$verifyRequest = [
    'method' => 'GET',
    'target_uri' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.telaris.ca') . ($_SERVER['REQUEST_URI'] ?? '/api/pluriverse/operators/status'),
    'headers' => [
        'signature'       => $sigValue,
        'signature-input' => $sigInput,
        'host'            => $_SERVER['HTTP_HOST'] ?? '',
        'date'            => $_SERVER['HTTP_DATE'] ?? '',
    ],
    'body' => '',
];
$verify = federation_http_sig_verify($verifyRequest, $identity['public_key'], [
    'expected_tag' => 'pluriverse-status',
]);
if (!$verify['valid']) {
    federation_router_problem(
        401,
        'signature_invalid',
        'Signature verification failed: ' . $verify['reason'],
        '/api/pluriverse/operators/status'
    );
    return;
}

try {
    $pdo = getDB();
    db_ensure_instances_table();
    $stmt = $pdo->prepare("
        SELECT hostname, admission_status, is_highlighted, updated_at
        FROM instances
        WHERE hostname = :h
        LIMIT 1
    ");
    $stmt->execute([':h' => $signerHostname]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('status_handler: query failed: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not read instance status.', '/api/pluriverse/operators/status');
    return;
}

if ($row === false) {
    federation_router_problem(
        404,
        'instance_not_found',
        "No instance row on file for hostname '{$signerHostname}'. The operator may need to re-join.",
        '/api/pluriverse/operators/status'
    );
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-cache, max-age=0');
echo json_encode([
    'hostname' => (string)$row['hostname'],
    'admission_status' => (string)$row['admission_status'],
    'is_highlighted' => (bool)$row['is_highlighted'],
    'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$row['updated_at'])),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
