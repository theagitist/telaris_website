<?php
declare(strict_types=1);

/**
 * GET /operators/verify-magic-link?t=<base64url-token>
 *
 * Single endpoint for all magic-link consumes. Two callers:
 *
 *   - Apply ack email (2f-iii): "confirm your join request".
 *   - Dashboard sign-in email (2h): "sign in to your dashboard".
 *
 * The endpoint doesn't distinguish them: it consumes the token, looks up the
 * instance, and decides what to do based on the instance's admission_status.
 *
 *   pending  → transition to verified, create session, redirect to /dashboard
 *   verified | published | outdated | withdrawn
 *            → create session, redirect to /dashboard
 *   rejected | blacklisted | revoked
 *            → no session, render the not-eligible message
 *
 * Single-use rule: a session is only minted on a *fresh* consume. An
 * already_consumed token (re-click, replay) yields the "link already used"
 * message even if the instance is past pending; the operator must request
 * a new sign-in link from /dashboard to obtain a session.
 *
 * Error states (rendered with HTTP 410): expired, invalid (malformed or
 * unknown), missing (no `t` param), instance_missing (consumed but the row
 * is gone), not_eligible (status blocks dashboard access).
 */

require_once __DIR__ . '/../db_federation.php';
require_once __DIR__ . '/../federation/session.php';

global $pluriverseLocale, $pluriverseInfo, $pluriversePrefix;

// -----------------------------------------------------------------------
// Decode + consume the token. Branch on consume status.
// -----------------------------------------------------------------------
$rawParam = (string)($_GET['t'] ?? '');
$tokenBytes = federation_token_url_decode($rawParam);

$state = 'invalid';
$instance = null;
$consumeFresh = false;

if ($rawParam === '') {
    $state = 'missing';
} elseif (strlen($tokenBytes) !== 32) {
    $state = 'invalid';
} else {
    $consume = db_consume_magic_link_token($tokenBytes);
    if ($consume === null) {
        $state = 'invalid';
    } elseif ($consume['status'] === 'expired') {
        $state = 'expired';
        $instance = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
    } elseif ($consume['status'] === 'already_consumed') {
        // Re-click on a burned token. No session minted (preserves single-use).
        $instance = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
        $state = 'already_used';
    } else { // 'consumed', fresh
        $consumeFresh = true;
        $instance = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
        if ($instance === null) {
            $state = 'instance_missing';
        } elseif ($instance['admission_status'] === 'pending') {
            $ok = db_transition_instance_admission(
                (int)$instance['id'],
                'pending',
                'verified',
                'operator',
                'email verified via magic link'
            );
            if ($ok) {
                $instance['admission_status'] = 'verified';
                $state = 'verified';
            } else {
                // Lost the race against a parallel transition; refetch and
                // continue as if we'd seen the post-transition state.
                $refresh = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
                $instance = $refresh ?? $instance;
                $state = ($instance['admission_status'] !== 'pending') ? 'verified' : 'invalid';
            }
        } else {
            // Fresh consume on an already-past-pending instance. This is the
            // dashboard sign-in case: token proves email ownership, grant
            // session.
            $state = 'verified';
        }
    }
}

// -----------------------------------------------------------------------
// On a fresh consume into a session-eligible state, mint the session and
// redirect to /dashboard. The cookie is set in the same response.
// -----------------------------------------------------------------------
$sessionEligibleStates = ['verified', 'published', 'outdated', 'withdrawn'];
if ($consumeFresh && $state === 'verified' && $instance !== null) {
    if (in_array((string)$instance['admission_status'], $sessionEligibleStates, true)) {
        try {
            $rawSession = db_create_session('operator', (int)$instance['id']);
            pluriverse_session_set_cookie($rawSession);
            $instanceLocale = (string)$instance['locale'];
            $localePrefix = in_array($instanceLocale, ['es', 'pt', 'fr'], true) ? '/' . $instanceLocale : '';
            header('Location: ' . $localePrefix . '/dashboard');
            http_response_code(303);
            return;
        } catch (Throwable $e) {
            error_log('verify: session create failed for instance ' . $instance['id'] . ': ' . $e->getMessage());
            // Fall through and render the success page without a session.
        }
    } else {
        // Fresh consume but instance is rejected/blacklisted/revoked. The
        // operator authenticated, but the Pluriverse won't let them in.
        $state = 'not_eligible';
    }
}

// -----------------------------------------------------------------------
// Switch chrome locale to the operator's preferred language when known.
// -----------------------------------------------------------------------
if ($instance !== null && isset($instance['locale'])
    && in_array($instance['locale'], ['en', 'es', 'pt', 'fr'], true)
) {
    $pluriverseLocale = (string)$instance['locale'];
    $pluriversePrefix = ($pluriverseLocale === 'en') ? '' : '/' . $pluriverseLocale;
    $pluriverseInfo = db_get_project_info_for_locale($pluriverseLocale);
    if ($pluriverseInfo === []) {
        $pluriverseInfo = db_get_project_info_for_locale('en');
        $pluriverseLocale = 'en';
        $pluriversePrefix = '';
    }
}

// -----------------------------------------------------------------------
// Render. Error states use HTTP 410.
// -----------------------------------------------------------------------
$headingKey = 'verify_heading_' . $state;
$bodyKey = 'verify_body_' . $state;
$pageTitle = info($headingKey) !== '' ? info($headingKey) : info('verify_heading_invalid');
$bodyClass = 'page-verify';
$includeBg = false;

if ($state !== 'verified') {
    http_response_code(410);
}

require __DIR__ . '/../partials/head.php';
?>

<main class="page">
  <h1 class="page-title"><?= h($pageTitle) ?></h1>
  <p class="page-lead"><?= h(info($bodyKey)) ?></p>
<?php if ($instance !== null && $state === 'verified'): ?>
  <dl class="verify-instance">
    <dt><?= h(info('verify_label_name')) ?></dt>
    <dd><?= h((string)$instance['label']) ?></dd>
    <dt><?= h(info('verify_label_hostname')) ?></dt>
    <dd><code><?= h((string)$instance['hostname']) ?></code></dd>
    <dt><?= h(info('verify_label_status')) ?></dt>
    <dd><?= h(info('verify_status_' . $instance['admission_status'])) ?></dd>
  </dl>
<?php endif; ?>
  <p class="page-footer-link"><a href="<?= h($pluriversePrefix . '/') ?>"><?= h(info('verify_back_home')) ?></a></p>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
