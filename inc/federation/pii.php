<?php
declare(strict_types=1);

/**
 * PII column encryption + lookup hashing (stage 2b).
 *
 * Two operations, two keys:
 *   1. Encrypt a PII string for storage in an _enc column (e.g.
 *      instances.operator_email_enc). libsodium crypto_secretbox with a
 *      per-row key derived from pii_master.key via HKDF-SHA256. The
 *      ciphertext is `nonce (24 bytes) || boxed_payload`; caller treats
 *      it as opaque bytes.
 *   2. Compute a deterministic lookup hash for content-equality search
 *      (e.g. instances.operator_email_lookup_hash). HMAC-SHA256 with
 *      pii_lookup.key. Deterministic so the UNIQUE constraint and
 *      reverse-lookup-by-email (magic link) both work.
 *
 * Spec: P2P federation plan v10 § Standards and crypto (line 487),
 *       § Schema → Pluriverse-side (line 1153-1154), and § Key
 *       management → Keys on the Pluriverse (line 820-821).
 *
 * Row-context decision: v10 anticipates the HKDF info string as
 * `instance_id || ":" || column_name`, but instance_id is auto-increment
 * and not known at INSERT time. Stage 2g (operator-application flow) will
 * settle the row-context choice; this helper accepts `$rowContext` as a
 * string the caller supplies — typically operator_email_lookup_hash at
 * insert time, then re-keyed to instance_id on a follow-up UPDATE if v10's
 * canonical form is preferred. Either choice is supported; the encrypt /
 * decrypt pair must match on $rowContext + $columnName.
 *
 * Path overrides for tests: define FEDERATION_PII_MASTER_KEY_PATH and / or
 * FEDERATION_PII_LOOKUP_KEY_PATH before this file is loaded.
 */

const FEDERATION_PII_KEY_LEN = 32;

function federation_pii_master_key_path(): string {
    if (defined('FEDERATION_PII_MASTER_KEY_PATH')) {
        return (string)FEDERATION_PII_MASTER_KEY_PATH;
    }
    return dirname(__DIR__, 2) . '/secrets/pii_master.key';
}

function federation_pii_lookup_key_path(): string {
    if (defined('FEDERATION_PII_LOOKUP_KEY_PATH')) {
        return (string)FEDERATION_PII_LOOKUP_KEY_PATH;
    }
    return dirname(__DIR__, 2) . '/secrets/pii_lookup.key';
}

function federation_load_pii_master_key(): string {
    return federation_load_32byte_key(federation_pii_master_key_path(), 'pii_master.key', 'bin/init-pii-keys');
}

function federation_load_pii_lookup_key(): string {
    return federation_load_32byte_key(federation_pii_lookup_key_path(), 'pii_lookup.key', 'bin/init-pii-keys');
}

function federation_load_32byte_key(string $path, string $label, string $initHint): string {
    if (!file_exists($path)) {
        throw new RuntimeException("federation_load_32byte_key: {$label} missing at {$path}; run {$initHint}");
    }
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException("federation_load_32byte_key: {$label} at {$path} unreadable");
    }
    if (strlen($bytes) !== FEDERATION_PII_KEY_LEN) {
        throw new RuntimeException(
            "federation_load_32byte_key: {$label} at {$path} wrong length "
            . '(got ' . strlen($bytes) . ', expected ' . FEDERATION_PII_KEY_LEN . ')'
        );
    }
    return $bytes;
}

/**
 * Derive a per-row encryption key from the master via HKDF-SHA256.
 * `info` = "{rowContext}:{columnName}". Length: 32 bytes (libsodium
 * secretbox key length).
 */
function federation_pii_derive_row_key(string $rowContext, string $columnName): string {
    $info = $rowContext . ':' . $columnName;
    return hash_hkdf('sha256', federation_load_pii_master_key(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $info);
}

/**
 * Encrypt a PII string for storage in an _enc column.
 *
 * Returns: nonce (24 bytes) || libsodium secretbox ciphertext. The whole
 * thing is opaque bytes; store in VARBINARY(512).
 *
 * Re-encrypting the same plaintext produces different bytes (random nonce
 * per call) — desirable for semantic security.
 */
function federation_pii_encrypt(string $plaintext, string $rowContext, string $columnName): string {
    $key = federation_pii_derive_row_key($rowContext, $columnName);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    sodium_memzero($key);
    return $nonce . $cipher;
}

/**
 * Decrypt a PII column value previously produced by federation_pii_encrypt
 * with the same ($rowContext, $columnName) pair.
 *
 * Throws RuntimeException on auth-tag mismatch (wrong key, corrupted bytes,
 * or wrong row-context — application bug).
 */
function federation_pii_decrypt(string $sealed, string $rowContext, string $columnName): string {
    $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (strlen($sealed) < $nonceLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RuntimeException('federation_pii_decrypt: ciphertext too short');
    }
    $nonce = substr($sealed, 0, $nonceLen);
    $cipher = substr($sealed, $nonceLen);
    $key = federation_pii_derive_row_key($rowContext, $columnName);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    sodium_memzero($key);
    if ($plain === false) {
        throw new RuntimeException('federation_pii_decrypt: auth tag mismatch (wrong key / corrupt bytes / wrong row-context)');
    }
    return $plain;
}

/**
 * Deterministic lookup hash for content-equality search.
 *
 * Returns 32 raw bytes (suitable for VARBINARY(32)). The same input always
 * produces the same output (HMAC-SHA256 with pii_lookup.key); enables
 * UNIQUE constraint enforcement and reverse-lookup-by-email for magic-link
 * delivery without storing the plaintext email.
 */
function federation_pii_lookup_hash(string $value): string {
    return sodium_crypto_auth($value, federation_load_pii_lookup_key());
}
