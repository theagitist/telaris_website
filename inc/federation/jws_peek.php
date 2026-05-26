<?php
declare(strict_types=1);

/**
 * Minimal JWS Compact-Serialization decoder for the relay path (4g).
 *
 * The Pluriverse relay forwards A's pre-signed JWS unchanged to B. It does
 * NOT need to verify the signature (that's B's job; B has A's public key
 * via its own peer cache). It DOES need to peek inside the envelope to:
 *
 *   - confirm the inner kid host matches the outer HTTP-Sig caller
 *     (otherwise A could ask us to relay a message signed by someone else),
 *   - confirm payload.recipient_host matches the body's recipient_hostname
 *     field (otherwise A could route message-to-C through us by claiming
 *     "recipient: B" at the body layer while the inner JWS says "to: C").
 *
 * Both checks defend the routing layer without us having to learn A's key
 * for verification. Anything beyond peek (real signature verify, payload
 * schema, sent_at skew) is B's responsibility on receipt.
 *
 * If we later add Pluriverse-side full JWS verification, the parsing here
 * is bit-identical to instance-side inc/federation/jws.php and shares the
 * same b64url helper rules.
 */

function federation_jws_peek(string $jws): ?array {
    if ($jws === '') return null;
    $parts = explode('.', $jws);
    if (count($parts) !== 3) return null;
    [$headerB64, $payloadB64] = $parts;
    if ($headerB64 === '' || $payloadB64 === '') return null;
    if (strlen($headerB64) > 4096 || strlen($payloadB64) > 65536) return null;

    $headerBytes = federation_jws_peek_b64u_decode($headerB64);
    $payloadBytes = federation_jws_peek_b64u_decode($payloadB64);
    if ($headerBytes === null || $payloadBytes === null) return null;

    try {
        $header = json_decode($headerBytes, true, 5, JSON_THROW_ON_ERROR);
        $payload = json_decode($payloadBytes, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $_) {
        return null;
    }
    if (!is_array($header) || !is_array($payload)) return null;
    return ['header' => $header, 'payload' => $payload];
}

function federation_jws_peek_b64u_decode(string $s): ?string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad === 1) return null;
    if ($pad !== 0) $s .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($s, true);
    return $decoded === false ? null : $decoded;
}
