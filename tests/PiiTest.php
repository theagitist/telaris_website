<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pluriverse PII-at-rest helpers: per-row authenticated encryption + a
 * deterministic blind-lookup hash. Uses the instance's real PII keys (read
 * only, no mutation); skips if those keys are not present/readable in this
 * runner so the suite never lazily mints keys on a box that lacks them.
 */
final class PiiTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/inc/federation/pii.php';
    }

    protected function setUp(): void
    {
        $master = federation_pii_master_key_path();
        $lookup = federation_pii_lookup_key_path();
        if (!is_readable($master) || !is_readable($lookup)) {
            $this->markTestSkipped('PII keys not readable in this runner');
        }
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $plain = 'editor@example.invalid';
        $sealed = federation_pii_encrypt($plain, 'instances:42', 'operator_email');
        $this->assertNotSame($plain, $sealed, 'ciphertext differs from plaintext');
        $this->assertSame($plain, federation_pii_decrypt($sealed, 'instances:42', 'operator_email'));
    }

    public function testWrongContextFailsToDecrypt(): void
    {
        $sealed = federation_pii_encrypt('secret value', 'instances:42', 'operator_email');
        // A different row context must not decrypt (authenticated, context-bound).
        $this->expectException(Throwable::class);
        federation_pii_decrypt($sealed, 'instances:99', 'operator_email');
    }

    public function testLookupHashIsDeterministicAndBlind(): void
    {
        $a = federation_pii_lookup_hash('editor@example.invalid');
        $b = federation_pii_lookup_hash('editor@example.invalid');
        $this->assertSame($a, $b, 'same value -> same hash (enables equality lookup)');
        $this->assertNotSame('editor@example.invalid', $a, 'hash does not reveal the value');
        $this->assertNotSame($a, federation_pii_lookup_hash('other@example.invalid'));
    }
}
