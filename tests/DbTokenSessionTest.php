<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DB-backed tests for the auth data layer: magic-link tokens (single-use,
 * expiry) and sessions (create / validate / destroy / chooser bind). Runs
 * against the isolated `pluriverse_test` database (see tests/bootstrap.php).
 */
final class DbTokenSessionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/inc/db_federation.php';
    }

    // ---- magic-link tokens ----

    public function testTokenCreateAndConsume(): void
    {
        $lookup = random_bytes(32);
        $raw = db_create_magic_link_token($lookup, 3600, 'operator');
        $this->assertSame(32, strlen($raw));

        $res = db_consume_magic_link_token($raw);
        $this->assertIsArray($res);
        $this->assertSame('consumed', $res['status']);
        $this->assertSame($lookup, $res['email_lookup_hash']);
        $this->assertSame('operator', $res['purpose']);
    }

    public function testTokenIsSingleUse(): void
    {
        $raw = db_create_magic_link_token(random_bytes(32), 3600, 'operator');
        $this->assertSame('consumed', db_consume_magic_link_token($raw)['status']);
        $this->assertSame('already_consumed', db_consume_magic_link_token($raw)['status']);
    }

    public function testExpiredTokenRejected(): void
    {
        $raw = db_create_magic_link_token(random_bytes(32), -10, 'operator'); // already expired
        $res = db_consume_magic_link_token($raw);
        $this->assertIsArray($res);
        $this->assertSame('expired', $res['status']);
    }

    public function testUnknownTokenReturnsNull(): void
    {
        $this->assertNull(db_consume_magic_link_token(random_bytes(32)));
    }

    public function testMalformedTokenReturnsNull(): void
    {
        $this->assertNull(db_consume_magic_link_token('too-short'));
    }

    public function testInvalidPurposeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        db_create_magic_link_token(random_bytes(32), 3600, 'bogus');
    }

    public function testTokenUrlCodecRoundTrips(): void
    {
        $raw = random_bytes(32);
        $enc = federation_token_url_encode($raw);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $enc);
        $this->assertSame($raw, federation_token_url_decode($enc));
        $this->assertSame('', federation_token_url_decode('not valid base64!'));
        $this->assertSame('', federation_token_url_decode(str_repeat('a', 100)));
    }

    // ---- sessions ----

    public function testSessionCreateValidateDestroy(): void
    {
        $raw = db_create_session('operator', 123);
        $this->assertSame(32, strlen($raw));

        $sess = db_validate_session($raw);
        $this->assertIsArray($sess);
        $this->assertSame('operator', $sess['subject_type']);
        $this->assertSame(123, (int)$sess['subject_id']);

        $this->assertTrue(db_destroy_session($raw));
        $this->assertNull(db_validate_session($raw), 'destroyed session no longer validates');
    }

    public function testSessionRejectsBadSubjectType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        db_create_session('intruder', 1);
    }

    public function testChooserSessionBindsToInstance(): void
    {
        $lookup = random_bytes(32);
        $raw = db_create_chooser_session($lookup, 900);

        $pre = db_validate_session($raw);
        $this->assertIsArray($pre);
        $this->assertSame('operator-chooser', $pre['subject_type']);
        $this->assertSame($lookup, $pre['chooser_email_hash']);

        $this->assertTrue(db_bind_chooser_session($raw, 456));

        $post = db_validate_session($raw);
        $this->assertSame('operator', $post['subject_type']);
        $this->assertSame(456, (int)$post['subject_id']);
        $this->assertNull($post['chooser_email_hash'], 'chooser hash cleared on bind');
    }

    public function testBindRejectsNonChooserSession(): void
    {
        // A real operator session cannot be re-bound via the chooser path.
        $raw = db_create_session('operator', 789);
        $this->assertFalse(db_bind_chooser_session($raw, 999));
    }

    // ---- label availability ----

    public function testLabelAvailability(): void
    {
        $this->assertTrue(db_label_available('unique-' . bin2hex(random_bytes(6))));
        $this->assertFalse(db_label_available(''), 'empty label is not available');
    }
}
