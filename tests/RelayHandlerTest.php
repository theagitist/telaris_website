<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/relay (relay_handler.php).
 *
 * The handshake relay forwards a signer's pre-signed JWS to a recipient
 * instance. The harness can drive every validation / auth / routing-claim
 * branch up to the downstream forward. The 202 happy path itself requires a
 * live recipient to POST to, so it is not reachable in CLI; the "all checks
 * pass" case is asserted by the handler reaching the downstream attempt and
 * failing there (502), which proves signer auth + JWS-peek routing all
 * succeeded. Steps that read recipient PII run only after a 2xx downstream,
 * so the harness never decrypts anything.
 */
final class RelayHandlerTest extends PluriverseHandlerTestCase
{
    private const HANDLER = 'inc/federation/relay_handler.php';
    private const PATH = '/api/pluriverse/relay';

    private string $sk = '';
    private string $pk = '';
    private string $keyid = '';

    protected function setUp(): void
    {
        parent::setUp();
        db_ensure_instances_table();

        [$this->sk, $this->pk] = $this->keypair();
        $this->keyid = $this->keyidFor('alpha.example', $this->pk);
        $this->seedInstance('alpha.example', $this->pk, 'Alpha Instance', 'published');
    }

    /** A well-formed inner JWS routed alpha -> beta. */
    private function jws(string $senderHost = 'alpha.example', string $recipientHost = 'beta.example', ?string $kidHost = null): string
    {
        $kidHost ??= $senderHost;
        return $this->fakeJws(
            ['alg' => 'EdDSA', 'kid' => $kidHost . ':fp', 'typ' => 'application/jose'],
            ['sender_host' => $senderHost, 'recipient_host' => $recipientHost, 'message_type' => 'handshake_init', 'thread_id' => 'abc']
        );
    }

    private function body(array $overrides = []): string
    {
        return (string)json_encode(array_merge([
            'recipient_hostname' => 'beta.example',
            'envelope' => $this->jws(),
        ], $overrides));
    }

    public function testMissingSignatureRejected(): void
    {
        $res = $this->dispatch(self::HANDLER, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => self::PATH], $this->body());
        $this->assertSame(401, $res['status']);
        $this->assertSame('signature_required', $res['json']['code']);
    }

    public function testEmptyBodyRejected(): void
    {
        $server = $this->signedServer(self::PATH, '', $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, '');
        $this->assertSame(400, $res['status']);
        $this->assertSame('empty_body', $res['json']['code']);
    }

    public function testBodyTooLargeRejected(): void
    {
        $big = str_repeat('x', 81 * 1024);
        $server = $this->signedServer(self::PATH, $big, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $big);
        $this->assertSame(413, $res['status']);
        $this->assertSame('body_too_large', $res['json']['code']);
    }

    public function testSignerNotInDirectoryRejected(): void
    {
        [$sk, $pk] = $this->keypair();
        $keyid = $this->keyidFor('stranger.example', $pk);
        $body = $this->body(['envelope' => $this->jws('stranger.example', 'beta.example')]);
        $server = $this->signedServer(self::PATH, $body, $sk, $keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(404, $res['status']);
        $this->assertSame('signer_not_in_directory', $res['json']['code']);
    }

    public function testSignerNotPublishedRejected(): void
    {
        [$sk, $pk] = $this->keypair();
        $keyid = $this->keyidFor('pending.example', $pk);
        $this->seedInstance('pending.example', $pk, 'Pending Instance', 'pending');
        $body = $this->body(['envelope' => $this->jws('pending.example', 'beta.example')]);
        $server = $this->signedServer(self::PATH, $body, $sk, $keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(403, $res['status']);
        $this->assertSame('signer_not_published', $res['json']['code']);
    }

    public function testFingerprintMismatchRejected(): void
    {
        [, $otherPub] = $this->keypair();
        $keyid = $this->keyidFor('alpha.example', $otherPub);
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $this->sk, $keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(401, $res['status']);
        $this->assertSame('fingerprint_mismatch', $res['json']['code']);
    }

    public function testForeignSignatureRejected(): void
    {
        [$otherSk] = $this->keypair();
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $otherSk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(401, $res['status']);
        $this->assertSame('signature_invalid', $res['json']['code']);
    }

    public function testWrongTagRejected(): void
    {
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(401, $res['status']);
        $this->assertSame('signature_invalid', $res['json']['code']);
    }

    public function testInvalidJsonRejected(): void
    {
        $body = 'definitely-not-json';
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('invalid_json', $res['json']['code']);
    }

    public function testMissingRecipientHostnameRejected(): void
    {
        $body = $this->body(['recipient_hostname' => '']);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('invalid_recipient_hostname', $res['json']['code']);
    }

    public function testSelfRecipientRejected(): void
    {
        $body = $this->body([
            'recipient_hostname' => 'alpha.example',
            'envelope' => $this->jws('alpha.example', 'alpha.example'),
        ]);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('self_recipient', $res['json']['code']);
    }

    public function testMissingEnvelopeRejected(): void
    {
        $body = (string)json_encode(['recipient_hostname' => 'beta.example']);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('missing_envelope', $res['json']['code']);
    }

    public function testMalformedEnvelopeRejected(): void
    {
        $body = $this->body(['envelope' => 'not.a.jws.too.many.parts']);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('malformed_envelope', $res['json']['code']);
    }

    public function testInnerOuterSignerMismatchRejected(): void
    {
        // Inner JWS kid host is someone other than the outer HTTP signer.
        $body = $this->body(['envelope' => $this->jws('alpha.example', 'beta.example', 'gamma.example')]);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('inner_outer_signer_mismatch', $res['json']['code']);
    }

    public function testInnerSenderMismatchRejected(): void
    {
        // kid host matches the signer, but payload.sender_host does not.
        $env = $this->fakeJws(
            ['alg' => 'EdDSA', 'kid' => 'alpha.example:fp'],
            ['sender_host' => 'someoneelse.example', 'recipient_host' => 'beta.example', 'message_type' => 'x']
        );
        $body = $this->body(['envelope' => $env]);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('inner_sender_mismatch', $res['json']['code']);
    }

    public function testInnerRecipientMismatchRejected(): void
    {
        // Inner payload says the message is for gamma, body claims beta.
        $body = $this->body(['envelope' => $this->jws('alpha.example', 'gamma.example')]);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('inner_recipient_mismatch', $res['json']['code']);
    }

    public function testRecipientNotInDirectoryRejected(): void
    {
        // Signer + routing claims all consistent, but beta is unknown.
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(404, $res['status']);
        $this->assertSame('recipient_not_in_directory', $res['json']['code']);
    }

    public function testRecipientNotPublishedRejected(): void
    {
        $this->seedInstance('beta.example', $this->keypair()[1], 'Beta Instance', 'verified');
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(403, $res['status']);
        $this->assertSame('recipient_not_published', $res['json']['code']);
    }

    public function testAllChecksPassReachDownstreamForward(): void
    {
        // beta is a published, resolvable-in-directory recipient. Every auth +
        // routing check passes; the handler then tries to POST to a
        // non-resolvable host and fails at the forward. Reaching this proves
        // the full security path succeeded. (503 if the coord signing key is
        // not loadable by this runner, e.g. not www-data.)
        $this->seedInstance('beta.invalid', $this->keypair()[1], 'Beta Instance', 'published');
        $body = $this->body([
            'recipient_hostname' => 'beta.invalid',
            'envelope' => $this->jws('alpha.example', 'beta.invalid'),
        ]);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);

        $this->assertContains($res['status'], [502, 503], 'expected downstream failure or unavailable coord key, got ' . $res['status'] . ': ' . $res['body']);
        $this->assertContains($res['json']['code'] ?? '', ['downstream_post_failed', 'coord_key_unavailable']);
    }
}
