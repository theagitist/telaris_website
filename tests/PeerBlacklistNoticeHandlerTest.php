<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/peer-blacklist-notice (peer_blacklist_notice_handler.php).
 *
 * Advisory peer-blacklist report from a published instance (stage 6e). The
 * only side effect is one info-level anomaly_log row; no auto-action. Fully
 * exercisable in the harness including the 202 happy path, since the success
 * path writes to the isolated test DB and never makes a network call.
 */
final class PeerBlacklistNoticeHandlerTest extends PluriverseHandlerTestCase
{
    private const HANDLER = 'inc/federation/peer_blacklist_notice_handler.php';
    private const PATH = '/api/pluriverse/peer-blacklist-notice';

    private string $sk = '';
    private string $pk = '';
    private string $keyid = '';
    private int $signerId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        db_ensure_instances_table();
        db_ensure_anomaly_log_table();
        getDB()->exec('DELETE FROM anomaly_log');

        [$this->sk, $this->pk] = $this->keypair();
        $this->keyid = $this->keyidFor('reporter.example', $this->pk);
        $this->signerId = $this->seedInstance('reporter.example', $this->pk, 'Reporter Instance', 'published');
    }

    private function body(array $overrides = []): string
    {
        return (string)json_encode(array_merge([
            'blacklisted_hostname' => 'bad.example',
            'blacklisted_at' => '2026-06-04T00:00:00Z',
            'reason' => 'repeated spam',
            'category' => 'spam',
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
        $server = $this->signedServer(self::PATH, '', $this->sk, $this->keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, '');
        $this->assertSame(400, $res['status']);
        $this->assertSame('empty_body', $res['json']['code']);
    }

    public function testBodyTooLargeRejected(): void
    {
        $big = str_repeat('x', 9 * 1024);
        $server = $this->signedServer(self::PATH, $big, $this->sk, $this->keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $big);
        $this->assertSame(413, $res['status']);
        $this->assertSame('body_too_large', $res['json']['code']);
    }

    public function testSignerNotInDirectoryRejected(): void
    {
        [$sk, $pk] = $this->keypair();
        $keyid = $this->keyidFor('stranger.example', $pk);
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $sk, $keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(404, $res['status']);
        $this->assertSame('signer_not_in_directory', $res['json']['code']);
    }

    public function testSignerNotPublishedRejected(): void
    {
        [$sk, $pk] = $this->keypair();
        $keyid = $this->keyidFor('pending.example', $pk);
        $this->seedInstance('pending.example', $pk, 'Pending Instance', 'pending');
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $sk, $keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(403, $res['status']);
        $this->assertSame('signer_not_published', $res['json']['code']);
    }

    public function testFingerprintMismatchRejected(): void
    {
        // Right host, wrong fingerprint (a different key's fp) -> rejected
        // before signature verification.
        [, $otherPub] = $this->keypair();
        $keyid = $this->keyidFor('reporter.example', $otherPub);
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $this->sk, $keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(401, $res['status']);
        $this->assertSame('fingerprint_mismatch', $res['json']['code']);
    }

    public function testForeignSignatureRejected(): void
    {
        // keyid claims the seeded fingerprint, but the request is signed with
        // a different secret key -> RFC 9421 verify fails.
        [$otherSk] = $this->keypair();
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $otherSk, $this->keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(401, $res['status']);
        $this->assertSame('signature_invalid', $res['json']['code']);
    }

    public function testWrongTagRejected(): void
    {
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-relay');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(401, $res['status']);
        $this->assertSame('signature_invalid', $res['json']['code']);
    }

    public function testInvalidJsonRejected(): void
    {
        $body = 'not json at all';
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('invalid_json', $res['json']['code']);
    }

    public function testMissingBlacklistedHostnameRejected(): void
    {
        $body = $this->body(['blacklisted_hostname' => '']);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(400, $res['status']);
        $this->assertSame('invalid_blacklisted_hostname', $res['json']['code']);
    }

    public function testValidNoticeRecordedInAnomalyLog(): void
    {
        $body = $this->body();
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);

        $this->assertSame(202, $res['status']);
        $this->assertSame('recorded', $res['json']['status']);

        $row = getDB()->query("
            SELECT instance_id, anomaly_type, severity, details_summary
            FROM anomaly_log ORDER BY id DESC LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($this->signerId, (int)$row['instance_id']);
        $this->assertSame('peer_blacklist_report', $row['anomaly_type']);
        $this->assertSame('info', $row['severity']);
        $this->assertStringContainsString('reported=bad.example', $row['details_summary']);
        $this->assertStringContainsString('category=spam', $row['details_summary']);
        $this->assertStringContainsString('reason=repeated spam', $row['details_summary']);
    }

    public function testUnknownCategoryNormalizedToOther(): void
    {
        $body = $this->body(['category' => 'bogus-category']);
        $server = $this->signedServer(self::PATH, $body, $this->sk, $this->keyid, 'tel-bl-notice');
        $res = $this->dispatch(self::HANDLER, $server, $body);
        $this->assertSame(202, $res['status']);

        $summary = getDB()->query('SELECT details_summary FROM anomaly_log ORDER BY id DESC LIMIT 1')->fetchColumn();
        $this->assertStringContainsString('category=other', (string)$summary);
    }
}
