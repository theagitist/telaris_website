<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pluriverse HTTP Message Signatures (RFC 9421 subset). Pure crypto: no DB, no
 * network. Uses ephemeral Ed25519 keypairs.
 */
final class HttpSigTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/inc/federation/http_sig.php';
    }

    /** @return array{0:string,1:string} [secret, public] */
    private function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [sodium_crypto_sign_secretkey($kp), sodium_crypto_sign_publickey($kp)];
    }

    private function getRequest(): array
    {
        return [
            'method' => 'GET',
            'target_uri' => 'https://www.telaris.ca/api/pluriverse/relay',
            'headers' => ['Host' => 'www.telaris.ca', 'Date' => gmdate('D, d M Y H:i:s') . ' GMT'],
            'body' => '',
        ];
    }

    private function signInto(array $request, string $secret, array $params): array
    {
        $signed = federation_http_sig_sign($request, $secret, $params);
        // For body methods the signature covers content-digest + content-length;
        // the real sender transmits both, so the test must present them too.
        $method = strtoupper((string)($request['method'] ?? 'GET'));
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $request['headers']['Content-Length'] = (string)strlen((string)($request['body'] ?? ''));
            if (isset($signed['content_digest'])) {
                $request['headers']['Content-Digest'] = $signed['content_digest'];
            }
        }
        $request['headers']['Signature-Input'] = $signed['signature_input'];
        $request['headers']['Signature'] = $signed['signature'];
        return $request;
    }

    public function testGetRoundTripVerifies(): void
    {
        [$sk, $pk] = $this->keypair();
        $req = $this->signInto($this->getRequest(), $sk, ['keyid' => 'www.telaris.ca:fp', 'tag' => 'tel-test']);
        $v = federation_http_sig_verify($req, $pk, ['expected_tag' => 'tel-test']);
        $this->assertTrue($v['valid'], $v['reason']);
    }

    public function testPostWithBodyRoundTripVerifies(): void
    {
        [$sk, $pk] = $this->keypair();
        $req = [
            'method' => 'POST',
            'target_uri' => 'https://www.telaris.ca/api/pluriverse/relay',
            'headers' => ['Host' => 'www.telaris.ca', 'Date' => gmdate('D, d M Y H:i:s') . ' GMT', 'Content-Type' => 'application/json'],
            'body' => '{"hello":"world"}',
        ];
        $req = $this->signInto($req, $sk, ['keyid' => 'www.telaris.ca:fp', 'tag' => 'tel-relay', 'nonce' => federation_http_sig_generate_nonce()]);
        $v = federation_http_sig_verify($req, $pk, ['expected_tag' => 'tel-relay']);
        $this->assertTrue($v['valid'], $v['reason']);
    }

    public function testForeignKeyRejected(): void
    {
        [$sk] = $this->keypair();
        [, $otherPub] = $this->keypair();
        $req = $this->signInto($this->getRequest(), $sk, ['keyid' => 'www.telaris.ca:fp', 'tag' => 'tel-test']);
        $v = federation_http_sig_verify($req, $otherPub, ['expected_tag' => 'tel-test']);
        $this->assertFalse($v['valid']);
        $this->assertSame('signature_invalid', $v['reason']);
    }

    public function testTamperedTargetRejected(): void
    {
        [$sk, $pk] = $this->keypair();
        $req = $this->signInto($this->getRequest(), $sk, ['keyid' => 'www.telaris.ca:fp', 'tag' => 'tel-test']);
        $req['target_uri'] = 'https://www.telaris.ca/api/pluriverse/peers.json'; // covered component changed
        $v = federation_http_sig_verify($req, $pk, ['expected_tag' => 'tel-test']);
        $this->assertFalse($v['valid']);
        $this->assertSame('signature_invalid', $v['reason']);
    }

    public function testWrongTagRejected(): void
    {
        [$sk, $pk] = $this->keypair();
        $req = $this->signInto($this->getRequest(), $sk, ['keyid' => 'www.telaris.ca:fp', 'tag' => 'tel-test']);
        $v = federation_http_sig_verify($req, $pk, ['expected_tag' => 'tel-relay']);
        $this->assertFalse($v['valid']);
        $this->assertSame('wrong_tag', $v['reason']);
    }

    public function testStaleCreatedRejected(): void
    {
        [$sk, $pk] = $this->keypair();
        $past = time() - 100000;
        $req = $this->signInto($this->getRequest(), $sk, ['keyid' => 'www.telaris.ca:fp', 'tag' => 'tel-test', 'created' => $past, 'expires' => $past + 300]);
        $v = federation_http_sig_verify($req, $pk, ['expected_tag' => 'tel-test']);
        $this->assertFalse($v['valid']);
        $this->assertContains($v['reason'], ['created_outside_skew', 'expired', 'date_outside_skew']);
    }

    public function testMissingSignatureHeadersRejected(): void
    {
        [, $pk] = $this->keypair();
        $v = federation_http_sig_verify($this->getRequest(), $pk, ['expected_tag' => 'tel-test']);
        $this->assertFalse($v['valid']);
        $this->assertSame('missing_signature_headers', $v['reason']);
    }

    public function testNonceIsFreshAndUrlSafe(): void
    {
        $a = federation_http_sig_generate_nonce();
        $b = federation_http_sig_generate_nonce();
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $a, 'base64url, no padding');
    }
}
