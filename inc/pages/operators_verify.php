<?php
declare(strict_types=1);

/**
 * GET /operators/verify-magic-link?t=<base64url-token>
 *
 * Confirms the operator's email after a join request. The token was minted by
 * the apply endpoint (`db_create_magic_link_token`) and emailed to the
 * operator inside a one-hour ack message. Clicking the link:
 *
 *   1. base64url-decodes the token, validates the 32-byte length.
 *   2. atomically consumes the token (single-use, TTL-bounded).
 *   3. looks up the instance by email_lookup_hash, switches admission_status
 *      from `pending` to `verified`, logs the transition.
 *   4. re-renders this page in the operator's preferred locale (stored on
 *      `instances.locale` since 2g-i, captured from the apply body).
 *
 * The endpoint is idempotent: a second click on an already-consumed token
 * (or on an instance that is already past `pending`) renders the
 * "already verified" surface instead of erroring.
 *
 * No session is created here. The session for the operator dashboard arrives
 * with 2g-ii / 2h. After verification, the operator's next touchpoint with
 * the Pluriverse is the admin-decision email.
 */

require_once __DIR__ . '/../db_federation.php';

global $pluriverseLocale, $pluriverseInfo, $pluriversePrefix;

// -----------------------------------------------------------------------
// Step 1: decode + consume the token.
// -----------------------------------------------------------------------
$rawParam = (string)($_GET['t'] ?? '');
$tokenBytes = federation_token_url_decode($rawParam);

$state = 'invalid';
$instance = null;

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
        // Token was clicked before; treat as success-already if the instance
        // moved past `pending`. Otherwise the verify clearly hasn't completed
        // (unusual race) and we surface the already-used message.
        $instance = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
        $state = ($instance !== null && $instance['admission_status'] !== 'pending')
            ? 'already_verified'
            : 'already_used';
    } else { // 'consumed'
        $instance = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
        if ($instance === null) {
            // Token consumed but instance gone (manually deleted, e.g.). Rare.
            $state = 'instance_missing';
        } elseif ($instance['admission_status'] !== 'pending') {
            // Already verified by a previous click within the same TTL window.
            $state = 'already_verified';
        } else {
            $ok = db_transition_instance_admission(
                (int)$instance['id'],
                'pending',
                'verified',
                'operator',
                'email verified via magic link'
            );
            if ($ok) {
                $state = 'verified';
                $instance['admission_status'] = 'verified';
            } else {
                // Lost the race against a parallel transition; refetch.
                $refresh = db_get_instance_by_email_lookup_hash($consume['email_lookup_hash']);
                $instance = $refresh ?? $instance;
                $state = ($instance['admission_status'] !== 'pending')
                    ? 'already_verified'
                    : 'invalid';
            }
        }
    }
}

// -----------------------------------------------------------------------
// Step 2: switch locale to the operator's preferred language if we know it.
// $pluriverseLocale already carries the URL-derived value (en at root); we
// override only when the instance row has a usable locale.
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
// Step 3: render. Strings come from project_info via info(); the keys are
// `verify_*` and live alongside the rest of the chrome.
// -----------------------------------------------------------------------
$headingKey = 'verify_heading_' . $state;
$bodyKey = 'verify_body_' . $state;
$pageTitle = info($headingKey) !== '' ? info($headingKey) : info('verify_heading_invalid');
$bodyClass = 'page-verify';
$includeBg = false;

if ($state !== 'verified' && $state !== 'already_verified') {
    http_response_code(410);
}

require __DIR__ . '/../partials/head.php';
?>

<main class="page">
  <h1 class="page-title"><?= h($pageTitle) ?></h1>
  <p class="page-lead"><?= h(info($bodyKey)) ?></p>
<?php if ($instance !== null && in_array($state, ['verified', 'already_verified'], true)): ?>
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
