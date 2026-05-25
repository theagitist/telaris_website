<?php
declare(strict_types=1);

/**
 * GET /operators/verify-magic-link?t=<base64url-token>
 *
 * Single endpoint for all magic-link consumes. Three callers:
 *
 *   - Apply ack email (2f-iii): purpose='operator', "confirm your join request"
 *   - Dashboard sign-in email (2h): purpose='operator', "sign in to your dashboard"
 *   - Admin sign-in email (2i-i): purpose='admin', "sign in to /admin"
 *
 * The token row carries the purpose; the verify handler dispatches:
 *
 *   purpose='admin'
 *     - lookup registry_admins by email_lookup_hash (active only)
 *     - mint admin session, redirect to /admin
 *     - not found → render not_eligible (admin row missing or deactivated)
 *
 *   purpose='operator' (default; backward-compatible for pre-2i-i tokens)
 *     - existing flow: pending→verified transition, mint operator session,
 *       redirect to /dashboard; rejected/blacklisted/revoked → not_eligible
 *
 * Single-use rule: a session is only minted on a *fresh* consume. An
 * already_consumed token (re-click, replay) yields the "link already used"
 * message even if the instance is past pending; the subject must request
 * a new sign-in link to obtain a session.
 *
 * Error states (rendered with HTTP 410): expired, invalid (malformed or
 * unknown), missing (no `t` param), instance_missing (consumed but the row
 * is gone), not_eligible (status or admin-active blocks access).
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
$purpose = 'operator';

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
        $purpose = (string)($consume['purpose'] ?? 'operator');
        if ($purpose === 'operator') {
            $instance = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
        } elseif ($purpose === 'email-change') {
            $instance = db_get_instance_by_pending_email_lookup_hash($consume['email_lookup_hash']);
        }
    } elseif ($consume['status'] === 'already_consumed') {
        // Re-click on a burned token. No session minted (preserves single-use).
        $purpose = (string)($consume['purpose'] ?? 'operator');
        if ($purpose === 'operator') {
            $instance = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
        } elseif ($purpose === 'email-change') {
            $instance = db_get_instance_by_pending_email_lookup_hash($consume['email_lookup_hash']);
        }
        $state = 'already_used';
    } else { // 'consumed', fresh
        $consumeFresh = true;
        $purpose = (string)($consume['purpose'] ?? 'operator');

        if ($purpose === 'email-change') {
            // Email-change confirmation: the token's lookup hash is the NEW
            // email's hash. Find the instance via pending_email_lookup_hash,
            // promote the change, redirect to /dashboard.
            $instance = db_get_instance_by_pending_email_lookup_hash($consume['email_lookup_hash']);
            if ($instance === null) {
                // Pending row was cancelled or already promoted in a race.
                $state = 'instance_missing';
            } else {
                $ok = db_promote_email_change((int)$instance['id']);
                if (!$ok) {
                    error_log('verify: db_promote_email_change failed for instance ' . $instance['id']);
                    $state = 'invalid';
                } else {
                    $localePrefix = in_array((string)$instance['locale'], ['es', 'pt', 'fr'], true) ? '/' . $instance['locale'] : '';
                    header('Location: ' . $localePrefix . '/dashboard?email_changed=1');
                    http_response_code(303);
                    return;
                }
            }
        } elseif ($purpose === 'admin') {
            // Admin sign-in path: gate on registry_admins membership.
            $admin = db_get_admin_by_email_lookup_hash($consume['email_lookup_hash']);
            if ($admin === null) {
                // Token was minted (so the lookup hash existed in the
                // admins table at that time) but the row is gone or
                // deactivated. Either way: refuse.
                $state = 'not_eligible';
            } else {
                try {
                    $rawSession = db_create_session('admin', (int)$admin['id']);
                    pluriverse_session_set_cookie($rawSession);
                    // Use the URL-derived locale; admins don't yet have a
                    // stored locale preference.
                    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
                    header('Location: ' . $localePrefix . '/admin');
                    http_response_code(303);
                    return;
                } catch (Throwable $e) {
                    error_log('verify: admin session create failed (admin ' . $admin['id'] . '): ' . $e->getMessage());
                    $state = 'invalid';
                }
            }
        } else { // operator
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
                    // Lost the race against a parallel transition; refetch.
                    $refresh = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
                    $instance = $refresh ?? $instance;
                    $state = ($instance['admission_status'] !== 'pending') ? 'verified' : 'invalid';
                }
            } else {
                // Fresh consume on an already-past-pending instance.
                // Dashboard sign-in case: grant session.
                $state = 'verified';
            }
        }
    }
}

// -----------------------------------------------------------------------
// On a fresh OPERATOR consume into a session-eligible state, mint the
// session and redirect to /dashboard. Admin redirect already returned
// above; this only fires for purpose=operator.
// -----------------------------------------------------------------------
$sessionEligibleStates = ['verified', 'published', 'outdated', 'withdrawn'];
if ($consumeFresh && $purpose === 'operator' && $state === 'verified' && $instance !== null) {
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
        // Fresh consume but instance is rejected/blacklisted/revoked.
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
