<?php
declare(strict_types=1);

/**
 * Pluriverse coordination identity (stage 2b).
 *
 * The Pluriverse's Ed25519 identity lives in a single file at
 * <site-root>/secrets/pluriverse-coord.key (64 bytes — libsodium secret-key
 * form). Distinct from the instance-side identity (which lives in
 * <instance-root>/secrets/pluriverse.key on every Telaris instance). The
 * coord key is the trust anchor every peer caches for verifying
 * Pluriverse-signed pushes (key events, relay-forwarded messages,
 * anomaly notices). TOFU on first contact; once cached, only a
 * signed coord_rotation event can swap it.
 *
 * Spec: P2P federation plan v10 § Key management → Keys on the Pluriverse
 *       (line 813+) and § Pluriverse coordination key rotation (line 836+).
 *
 * Pattern mirrors the instance-side inc/federation/identity.php:
 *   - Pure functions (derive_public_key, compute_fingerprint) for testing
 *     and call-site use without touching the disk.
 *   - File-backed accessors (load_coord_secret_key, coord_public_key,
 *     coord_fingerprint, coord_keyid) with a process-local static cache for
 *     the public key.
 *   - Fingerprint format: base64url(SHA-256(public_key)[0..16]), no padding,
 *     22 chars. Used as the suffix half of the `kid` in JWS / HTTP Signature
 *     headers (format <host>:<fingerprint>).
 *
 * Override the key path for tests by defining FEDERATION_COORD_KEY_PATH
 * before this file is loaded.
 */

function federation_coord_key_path(): string {
    if (defined('FEDERATION_COORD_KEY_PATH')) {
        return (string)FEDERATION_COORD_KEY_PATH;
    }
    return dirname(__DIR__, 2) . '/secrets/pluriverse-coord.key';
}

function federation_load_coord_secret_key(): string {
    $path = federation_coord_key_path();
    if (!file_exists($path)) {
        throw new RuntimeException(
            'federation_load_coord_secret_key: ' . $path . ' missing; run bin/init-coord-key'
        );
    }
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException('federation_load_coord_secret_key: ' . $path . ' unreadable');
    }
    $expected = SODIUM_CRYPTO_SIGN_SECRETKEYBYTES;
    if (strlen($bytes) !== $expected) {
        throw new RuntimeException(
            'federation_load_coord_secret_key: ' . $path
            . ' wrong length (got ' . strlen($bytes) . ', expected ' . $expected . ')'
        );
    }
    return $bytes;
}

function federation_derive_public_key(string $secretKey): string {
    if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new InvalidArgumentException(
            'federation_derive_public_key: secret key must be '
            . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . ' bytes'
        );
    }
    return sodium_crypto_sign_publickey_from_secretkey($secretKey);
}

function federation_coord_public_key(): string {
    static $pubkey = null;
    if ($pubkey === null) {
        $pubkey = federation_derive_public_key(federation_load_coord_secret_key());
    }
    return $pubkey;
}

function federation_compute_fingerprint(string $publicKey): string {
    if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        throw new InvalidArgumentException(
            'federation_compute_fingerprint: public key must be '
            . SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES . ' bytes'
        );
    }
    $hash = hash('sha256', $publicKey, true);
    return rtrim(strtr(base64_encode(substr($hash, 0, 16)), '+/', '-_'), '=');
}

function federation_coord_fingerprint(): string {
    return federation_compute_fingerprint(federation_coord_public_key());
}

function federation_coord_keyid(string $hostname = 'www.telaris.ca'): string {
    return $hostname . ':' . federation_coord_fingerprint();
}
