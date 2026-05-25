<?php
declare(strict_types=1);

/**
 * Pluriverse-website CSRF protection.
 *
 * Synchronizer-token pattern bound to the session: every session row carries
 * a 32-byte random `csrf_token` minted at session creation. Forms include
 * the token in a hidden field; POST handlers compare against the session's
 * token via constant-time hash_equals.
 *
 * Why not double-submit cookie:
 *   - We already have a server-side session, so the extra cookie is
 *     redundant.
 *   - Synchronizer-token bound to session is simpler to reason about and
 *     doesn't depend on cookie partitioning behaviour for safety.
 *
 * Token lifecycle:
 *   - Created with the session by db_create_session.
 *   - Lazy-migrated for pre-2g-ii sessions (db_validate_session generates
 *     one on first hit if NULL).
 *   - Rotated only on session re-creation (no per-request rotation; that
 *     would break back-button and multi-tab use).
 *   - Destroyed with the session.
 *
 * Field name: `csrf`. Helpers render the hidden input directly so call
 * sites don't accidentally diverge.
 */

require_once __DIR__ . '/session.php';

/**
 * Raw 32-byte CSRF token for the current session, or null if unauth.
 */
function pluriverse_csrf_token_raw(): ?string {
    $session = pluriverse_current_session();
    if ($session === null) return null;
    $csrf = $session['csrf_token'] ?? '';
    return strlen($csrf) === 32 ? $csrf : null;
}

/**
 * Base64url-encoded CSRF token suitable for a hidden form field. Empty
 * string when there is no session.
 */
function pluriverse_csrf_token(): string {
    $raw = pluriverse_csrf_token_raw();
    return $raw === null ? '' : federation_token_url_encode($raw);
}

/**
 * Render `<input type="hidden" name="csrf" value="...">` for embedding in
 * a form. Returns the empty string when there is no session (caller can
 * still emit the form; the POST will simply fail verification).
 */
function pluriverse_csrf_field(): string {
    $token = pluriverse_csrf_token();
    if ($token === '') return '';
    return '<input type="hidden" name="csrf" value="'
        . htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        . '">';
}

/**
 * Verify a submitted CSRF token. Constant-time. Returns false when no
 * session is active, when the submitted value is empty or malformed, or
 * when the value does not match.
 *
 * Pass the value from `$_POST['csrf']` directly; this helper handles
 * decoding and length checks.
 */
function pluriverse_csrf_verify(?string $submitted): bool {
    if ($submitted === null || $submitted === '') return false;
    $expected = pluriverse_csrf_token_raw();
    if ($expected === null) return false;
    $decoded = federation_token_url_decode($submitted);
    if (strlen($decoded) !== 32) return false;
    return hash_equals($expected, $decoded);
}
