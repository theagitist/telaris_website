<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pluriverse identity primitives that are pure given a key (public-key
 * derivation + fingerprint). The coord-key file accessors are not exercised
 * here (they touch secrets/).
 */
final class IdentityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/inc/federation/identity.php';
    }

    public function testDerivePublicKeyMatchesSodium(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($kp);
        $this->assertSame(
            sodium_crypto_sign_publickey($kp),
            federation_derive_public_key($sk)
        );
    }

    public function testFingerprintIsDeterministicAndUrlSafe(): void
    {
        $pk = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $a = federation_compute_fingerprint($pk);
        $b = federation_compute_fingerprint($pk);
        $this->assertSame($a, $b, 'same key -> same fingerprint');
        $this->assertNotSame('', $a);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $a, 'url-safe, no padding');
    }

    public function testDistinctKeysGiveDistinctFingerprints(): void
    {
        $p1 = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $p2 = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $this->assertNotSame(
            federation_compute_fingerprint($p1),
            federation_compute_fingerprint($p2)
        );
    }
}
