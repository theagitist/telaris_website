<?php
declare(strict_types=1);

/**
 * HTTPS fetch + parse of a Telaris instance's identity envelope.
 *
 * Used by the operator-application flow (POST /api/pluriverse/operators/apply)
 * to verify the applicant supplied a real Telaris instance (kind === "telaris-
 * instance") and to capture its Ed25519 public key + fingerprint into the new
 * `instances` row.
 *
 * Hardened defaults:
 *   - HTTPS only (HTTP refused at the parse_url stage).
 *   - 5 s connect, 10 s total wall-clock budget.
 *   - No redirect follow (the applicant supplies the canonical URL; a chain
 *     would let an attacker point us at an internal host).
 *   - TLS peer verification on.
 *   - Response body capped at 16 KB (identity envelopes are well under 1 KB).
 *   - JSON parse limited to depth 8.
 *   - Required fields validated: kind, hostname, public_key,
 *     public_key_fingerprint, protocol_version.
 *   - kind MUST equal "telaris-instance".
 *   - protocol_version MUST equal "1.0" (the only Pluriverse protocol
 *     version that exists in stage 2).
 *   - public_key MUST base64-decode to exactly 32 bytes.
 *   - Returned fingerprint MUST match a locally-recomputed
 *     base64url(SHA-256(public_key)[0..16]) (defence against a mis-encoded
 *     or hand-rolled response).
 *
 * On any failure throws FederationIdentityFetchError; the caller maps to an
 * appropriate HTTP status (typically 422 Unprocessable Content for the apply
 * endpoint).
 */

class FederationIdentityFetchError extends RuntimeException {}

const FEDERATION_IDENTITY_MAX_BODY = 16384;

/**
 * Fetch and validate an instance identity envelope.
 *
 * Returns an associative array with: kind, hostname, label, protocol_version,
 * public_key (raw 32 bytes, NOT base64), public_key_fingerprint,
 * pluriverse_endpoint, telaris_version. The caller is responsible for any
 * further consistency checks (e.g. hostname matches the applicant's claim).
 */
function federation_fetch_identity(string $url): array {
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
        throw new FederationIdentityFetchError('pluriverse_endpoint must be an https:// URL with a host');
    }
    if (!function_exists('curl_init')) {
        throw new FederationIdentityFetchError('curl extension required for identity fetch');
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Pluriverse/1.0 (+https://www.telaris.ca/)',
        ],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new FederationIdentityFetchError('identity fetch failed: ' . $err);
    }
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new FederationIdentityFetchError("identity endpoint returned HTTP {$httpCode}");
    }
    if (strlen($body) > FEDERATION_IDENTITY_MAX_BODY) {
        throw new FederationIdentityFetchError('identity response exceeds ' . FEDERATION_IDENTITY_MAX_BODY . ' bytes');
    }

    try {
        $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new FederationIdentityFetchError('identity response is not valid JSON: ' . $e->getMessage());
    }
    if (!is_array($data)) {
        throw new FederationIdentityFetchError('identity response is not a JSON object');
    }

    foreach (['kind', 'hostname', 'public_key', 'public_key_fingerprint', 'protocol_version'] as $field) {
        if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
            throw new FederationIdentityFetchError("identity response missing required field: {$field}");
        }
    }
    if ($data['kind'] !== 'telaris-instance') {
        throw new FederationIdentityFetchError("identity.kind must be 'telaris-instance', got: " . $data['kind']);
    }
    if ($data['protocol_version'] !== '1.0') {
        throw new FederationIdentityFetchError("identity.protocol_version unsupported: " . $data['protocol_version']);
    }

    $pkBytes = base64_decode($data['public_key'], true);
    if ($pkBytes === false || strlen($pkBytes) !== 32) {
        throw new FederationIdentityFetchError('identity.public_key does not base64-decode to 32 bytes');
    }

    // Local fingerprint recomputation. The peer should never lie here, but
    // a transcription error or a misaligned client could put us out of sync.
    $expected = rtrim(strtr(base64_encode(substr(hash('sha256', $pkBytes, true), 0, 16)), '+/', '-_'), '=');
    if (!hash_equals($expected, $data['public_key_fingerprint'])) {
        throw new FederationIdentityFetchError('identity.public_key_fingerprint does not match locally-recomputed value');
    }

    return [
        'kind' => $data['kind'],
        'hostname' => $data['hostname'],
        'label' => isset($data['label']) && is_string($data['label']) ? $data['label'] : '',
        'protocol_version' => $data['protocol_version'],
        'public_key' => $pkBytes,
        'public_key_fingerprint' => $data['public_key_fingerprint'],
        'pluriverse_endpoint' => isset($data['pluriverse_endpoint']) && is_string($data['pluriverse_endpoint']) ? $data['pluriverse_endpoint'] : '',
        'telaris_version' => isset($data['telaris_version']) && is_string($data['telaris_version']) ? $data['telaris_version'] : '',
    ];
}
