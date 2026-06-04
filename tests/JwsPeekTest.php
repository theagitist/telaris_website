<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pluriverse relay JWS peek (no-verify decode of the routing claims). Pure.
 */
final class JwsPeekTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/inc/federation/jws_peek.php';
    }

    private function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function jws(array $header, array $payload, string $sig = 'sig'): string
    {
        return $this->b64u(json_encode($header)) . '.' . $this->b64u(json_encode($payload)) . '.' . $this->b64u($sig);
    }

    public function testDecodesHeaderAndPayload(): void
    {
        $jws = $this->jws(
            ['alg' => 'EdDSA', 'kid' => 'a.example.invalid:fp'],
            ['recipient_host' => 'b.example.invalid', 'thread_id' => 'abc']
        );
        $out = federation_jws_peek($jws);
        $this->assertIsArray($out);
        $this->assertSame('a.example.invalid:fp', $out['header']['kid']);
        $this->assertSame('b.example.invalid', $out['payload']['recipient_host']);
    }

    public function testRejectsWrongSegmentCount(): void
    {
        $this->assertNull(federation_jws_peek('only.two'));
        $this->assertNull(federation_jws_peek('a.b.c.d'));
        $this->assertNull(federation_jws_peek(''));
    }

    public function testRejectsEmptySegments(): void
    {
        $this->assertNull(federation_jws_peek('.' . $this->b64u('{}') . '.sig'));
    }

    public function testRejectsNonJsonSegments(): void
    {
        $this->assertNull(federation_jws_peek($this->b64u('not json') . '.' . $this->b64u('{}') . '.sig'));
    }

    public function testRejectsOversizedHeader(): void
    {
        $huge = $this->b64u(str_repeat('a', 5000));
        $this->assertNull(federation_jws_peek($huge . '.' . $this->b64u('{}') . '.sig'));
    }
}
