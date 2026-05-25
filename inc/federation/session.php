<?php
declare(strict_types=1);

/**
 * Pluriverse-website session layer: cookie ↔ raw bytes ↔ DB row.
 *
 * Cookie shape:
 *   name:     pluriverse_session
 *   value:    base64url(32-byte random session_id)
 *   flags:    HttpOnly + Secure + SameSite=Lax + path=/
 *   ttl:      same as the sessions row (14d default; sliding via
 *             db_validate_session which touches last_activity_at).
 *
 * No JavaScript reads this cookie. No cross-origin form submissions read this
 * cookie. SameSite=Lax allows top-level navigations to carry it, which is
 * fine for /dashboard click-throughs from the verification email but not
 * fine for any state-changing POST endpoint, so any future state-changing
 * route MUST add CSRF (2g-ii) on top.
 */

require_once __DIR__ . '/../db.php';

const PLURIVERSE_SESSION_COOKIE = 'pluriverse_session';
const PLURIVERSE_SESSION_TTL = 1209600; // 14 days

/**
 * Set the session cookie. Caller is expected to have already created the
 * sessions row via db_create_session.
 */
function pluriverse_session_set_cookie(string $rawSessionId, int $ttlSeconds = PLURIVERSE_SESSION_TTL): void {
    if (strlen($rawSessionId) !== 32) {
        throw new InvalidArgumentException('pluriverse_session_set_cookie: raw session id must be 32 bytes');
    }
    $encoded = federation_token_url_encode($rawSessionId);
    setcookie(PLURIVERSE_SESSION_COOKIE, $encoded, [
        'expires' => time() + $ttlSeconds,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Clear the session cookie. Used on logout and on session-not-found.
 *
 * Browsers honour both an explicit expires-in-the-past and an empty value;
 * we set both for belt-and-braces.
 */
function pluriverse_session_clear_cookie(): void {
    setcookie(PLURIVERSE_SESSION_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Read the session cookie and decode to raw bytes. Returns '' if absent
 * or malformed. The caller passes the result to db_validate_session.
 */
function pluriverse_session_read_cookie(): string {
    $raw = (string)($_COOKIE[PLURIVERSE_SESSION_COOKIE] ?? '');
    if ($raw === '') return '';
    return federation_token_url_decode($raw);
}

/**
 * One-shot: read the cookie, validate against DB, return the subject info
 * or null. Clears the cookie if the session is unknown/expired.
 *
 * @return array{subject_type:string, subject_id:int}|null
 */
function pluriverse_current_session(): ?array {
    $raw = pluriverse_session_read_cookie();
    if ($raw === '' || strlen($raw) !== 32) return null;
    $session = db_validate_session($raw);
    if ($session === null) {
        // Cookie present but DB doesn't know it. Drop it.
        pluriverse_session_clear_cookie();
        return null;
    }
    return [
        'subject_type' => (string)$session['subject_type'],
        'subject_id' => (int)$session['subject_id'],
    ];
}
