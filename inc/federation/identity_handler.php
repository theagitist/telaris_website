<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/identity (Pluriverse-coord variant)
 *
 * Returns the Pluriverse's coordination identity envelope. Distinct from
 * the instance-side response: the `kind` field disambiguates so verifiers
 * can parse both surfaces with one decoder and dispatch on `kind`.
 *
 * Spec: P2P federation plan v10 § Instance-side endpoint catalogue
 *       (line 217), § Key management → Keys on the Pluriverse (line 824),
 *       § Pluriverse coordination key rotation (line 836+).
 *
 * Authentication: none. Public-read.
 * Rate limit: 60 req/min/IP via APCu, plus the nginx-level limit_req zone.
 *
 * No state writes; no DB writes. Reads only the in-process-cached coord
 * public key derived from secrets/pluriverse-coord.key.
 *
 * Trust model: peers TOFU this on first contact (HTTPS to www.telaris.ca
 * is the anchor). Once cached, the coord public key is only swapped by a
 * signed coord_rotation event verified against the previously cached coord
 * key. Operators MAY manually verify the fingerprint out-of-band at
 * onboarding.
 */

require_once __DIR__ . '/identity.php';

// Best-effort per-IP rate limit. nginx limit_req remains the load-bearing
// protection; this is defence in depth.
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_identity:' . date('YmdHi') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(
            429,
            'rate_limited',
            'Too many identity requests from this IP this minute; retry shortly.',
            '/api/pluriverse/identity'
        );
        return;
    }
}

try {
    $publicKey = federation_coord_public_key();
    $fingerprint = federation_coord_fingerprint();
} catch (Throwable $e) {
    // pluriverse-coord.key missing or unreadable. Surface as 503 so monitoring
    // distinguishes not-yet-provisioned from "endpoint broken".
    error_log('pluriverse/identity: ' . $e->getMessage());
    federation_router_problem(
        503,
        'identity_unavailable',
        'The Pluriverse has not been provisioned with a coordination identity yet.',
        '/api/pluriverse/identity'
    );
    return;
}

$hostname = (string)($_SERVER['HTTP_HOST'] ?? 'www.telaris.ca');
if (str_contains($hostname, ':')) {
    $hostname = (string)strstr($hostname, ':', true);
}

$payload = [
    'kind' => 'pluriverse-coord',
    'hostname' => $hostname,
    'label' => 'Pluriverse',
    'pluriverse_version' => '1.0',
    'protocol_version' => '1.0',
    'public_key' => base64_encode($publicKey),
    'public_key_fingerprint' => $fingerprint,
];

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
header('X-Content-Type-Options: nosniff');
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
