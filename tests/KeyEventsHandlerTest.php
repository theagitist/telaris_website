<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/key-events.json (key_events_handler.php).
 *
 * Public, unsigned feed of signed key-rotation events. Fully exercisable in
 * the harness: seed key_events_signed rows in the test DB and read the JSON
 * back, including the ?since filter and the conditional-GET 304 path.
 */
final class KeyEventsHandlerTest extends PluriverseHandlerTestCase
{
    private const HANDLER = 'inc/federation/key_events_handler.php';

    protected function setUp(): void
    {
        parent::setUp();
        db_ensure_key_events_signed_table();
        getDB()->exec('DELETE FROM key_events_signed');
    }

    private function seedEvent(string $originHost, string $eventType, string $occurredAt, string $payload): void
    {
        $stmt = getDB()->prepare("
            INSERT INTO key_events_signed (origin_host, event_type, occurred_at, signed_payload)
            VALUES (:o, :t, :at, :p)
        ");
        $stmt->execute([':o' => $originHost, ':t' => $eventType, ':at' => $occurredAt, ':p' => $payload]);
    }

    public function testEmptyFeedReturnsZeroEvents(): void
    {
        $res = $this->dispatch(self::HANDLER);
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json']);
        $this->assertSame('1.0', $res['json']['version']);
        $this->assertSame(0, $res['json']['count']);
        $this->assertSame([], $res['json']['events']);
        $this->assertArrayHasKey('generated_at', $res['json']);
    }

    public function testFeedReturnsSeededEvents(): void
    {
        $this->seedEvent('alpha.example', 'scheduled_rotation', '2026-01-01 10:00:00', 'aaa.bbb.ccc');
        $this->seedEvent('beta.example', 'revocation', '2026-02-01 12:30:00', 'ddd.eee.fff');

        $res = $this->dispatch(self::HANDLER);
        $this->assertSame(200, $res['status']);
        $this->assertSame(2, $res['json']['count']);

        // Ordered by occurred_at ASC: alpha first.
        $this->assertSame('alpha.example', $res['json']['events'][0]['origin_host']);
        $this->assertSame('scheduled_rotation', $res['json']['events'][0]['event_type']);
        $this->assertSame('2026-01-01T10:00:00Z', $res['json']['events'][0]['occurred_at']);
        $this->assertSame('aaa.bbb.ccc', $res['json']['events'][0]['signed_payload']);
        $this->assertSame('beta.example', $res['json']['events'][1]['origin_host']);
        $this->assertSame('revocation', $res['json']['events'][1]['event_type']);
    }

    public function testSinceFilterExcludesOlderEvents(): void
    {
        $this->seedEvent('alpha.example', 'scheduled_rotation', '2026-01-01 10:00:00', 'old.old.old');
        $this->seedEvent('beta.example', 'revocation', '2026-03-01 09:00:00', 'new.new.new');

        $res = $this->dispatch(self::HANDLER, [], '', ['since' => '2026-02-01T00:00:00Z']);
        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['json']['count']);
        $this->assertSame('beta.example', $res['json']['events'][0]['origin_host']);
    }

    public function testInvalidSinceRejected(): void
    {
        $res = $this->dispatch(self::HANDLER, [], '', ['since' => 'not-a-timestamp']);
        $this->assertSame(400, $res['status']);
        $this->assertSame('invalid_since', $res['json']['code']);
    }

    public function testConditionalGetReturns304ForMatchingEtag(): void
    {
        $this->seedEvent('alpha.example', 'scheduled_rotation', '2026-01-01 10:00:00', 'aaa.bbb.ccc');

        // Recompute the ETag exactly as the handler does (hash over the
        // version/count/events data payload, NOT the full body which carries
        // a volatile generated_at).
        $events = [[
            'origin_host' => 'alpha.example',
            'event_type' => 'scheduled_rotation',
            'occurred_at' => '2026-01-01T10:00:00Z',
            'signed_payload' => 'aaa.bbb.ccc',
        ]];
        $dataPayload = ['version' => '1.0', 'count' => 1, 'events' => $events];
        $etag = '"' . substr(hash('sha256', json_encode($dataPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16) . '"';

        $res = $this->dispatch(self::HANDLER, ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertSame(304, $res['status']);
        $this->assertSame('', $res['body'], '304 carries no body');
    }

    public function testStaleEtagStillReturns200(): void
    {
        $this->seedEvent('alpha.example', 'scheduled_rotation', '2026-01-01 10:00:00', 'aaa.bbb.ccc');
        $res = $this->dispatch(self::HANDLER, ['HTTP_IF_NONE_MATCH' => '"deadbeefdeadbeef"']);
        $this->assertSame(200, $res['status']);
        $this->assertSame(1, $res['json']['count']);
    }
}
