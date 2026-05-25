<?php
declare(strict_types=1);

/**
 * /admin, Pluriverse admin self-service.
 *
 * Two unauthenticated states + one authenticated state:
 *
 *   GET, no admin session:
 *     Localized sign-in form. Same shape as /dashboard.
 *
 *   POST, no admin session:
 *     Look up the supplied email in registry_admins (active only). If a
 *     row matches, mint an admin-purpose magic-link token and email the
 *     sign-in link to that address. The confirmation page is non-
 *     enumerating: we render the same "if we found an account" callout
 *     whether or not the email matched.
 *
 *   GET, valid admin session:
 *     Landing: list every instances row grouped by admission_status,
 *     ordered newest first. This chunk is read-only; the publish /
 *     reject / blacklist actions land in 2i-ii alongside CSRF wiring.
 *
 * Logout for admins shares /admin POST action=logout, same CSRF helper as
 * the operator dashboard.
 *
 * Rate limit: 5 sign-in requests per IP per hour. Separate APCu bucket
 * from the operator /dashboard so admin requests don't share quota with
 * operator sign-ins.
 */

require_once __DIR__ . '/../db_federation.php';
require_once __DIR__ . '/../federation/session.php';
require_once __DIR__ . '/../federation/csrf.php';
require_once __DIR__ . '/../federation/pii.php';

global $pluriverseLocale, $pluriverseInfo, $pluriversePrefix;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$session = pluriverse_current_session();

// -----------------------------------------------------------------------
// POST action=logout, before any rendering so we can redirect.
// -----------------------------------------------------------------------
if ($method === 'POST'
    && (string)($_POST['action'] ?? '') === 'logout'
    && $session !== null
    && $session['subject_type'] === 'admin'
) {
    if (pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        db_destroy_session($session['session_id']);
        pluriverse_session_clear_cookie();
        pluriverse_current_session_invalidate();
    }
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    header('Location: ' . $localePrefix . '/admin');
    http_response_code(303);
    return;
}
$session = pluriverse_current_session();

// -----------------------------------------------------------------------
// Authenticated view (admin only).
// -----------------------------------------------------------------------
if ($session !== null && $session['subject_type'] === 'admin') {
    $admin = db_get_admin_by_id($session['subject_id']);
    if ($admin === null || !$admin['is_active']) {
        // Session present but admin row gone or deactivated. Drop session.
        db_destroy_session($session['session_id']);
        pluriverse_session_clear_cookie();
        pluriverse_current_session_invalidate();
        $session = null;
    } else {
        // Sweep stale pending instances so the admin sees the current
        // federation state without waiting for the next apply attempt.
        db_expire_stale_pending_instances();

        $pdo = getDB();
        $rows = $pdo->query("
            SELECT id, hostname, label, admission_status, created_at, verify_by_at, locale
            FROM instances
            ORDER BY
                FIELD(admission_status, 'verified', 'pending', 'published', 'outdated', 'withdrawn', 'expired', 'rejected', 'blacklisted', 'revoked'),
                created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Bucket counts for the summary line.
        $counts = [];
        foreach ($rows as $r) {
            $s = (string)$r['admission_status'];
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }

        $pageTitle = info('admin_title');
        $bodyClass = 'page-admin';
        $includeBg = false;
        require __DIR__ . '/../partials/head.php';
        ?>
        <main class="page page-admin">
          <h1 class="page-title"><?= h(info('admin_title')) ?></h1>
          <p class="page-lead">
            <?= h(sprintf(info('admin_lead_signed_in_as_fmt'), (string)$admin['display_name'])) ?>
          </p>

          <section class="dashboard-section">
            <h2><?= h(info('admin_section_instances')) ?></h2>
<?php if ($rows === []): ?>
            <p class="dashboard-help"><?= h(info('admin_instances_none')) ?></p>
<?php else: ?>
            <p class="dashboard-help">
              <?= h(sprintf(info('admin_instances_total_fmt'), count($rows))) ?>
            </p>
            <table class="admin-instances">
              <thead>
                <tr>
                  <th><?= h(info('admin_col_name')) ?></th>
                  <th><?= h(info('admin_col_hostname')) ?></th>
                  <th><?= h(info('admin_col_status')) ?></th>
                  <th><?= h(info('admin_col_locale')) ?></th>
                  <th><?= h(info('admin_col_created')) ?></th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($rows as $r): ?>
                <tr class="admin-instance-row admin-instance-row-<?= h((string)$r['admission_status']) ?>">
                  <td><?= h((string)$r['label']) ?></td>
                  <td><code><?= h((string)$r['hostname']) ?></code></td>
                  <td><?= h(info('verify_status_' . $r['admission_status'])) ?></td>
                  <td><?= h((string)$r['locale']) ?></td>
                  <td><time datetime="<?= h((string)$r['created_at']) ?>"><?= h((string)$r['created_at']) ?></time></td>
                </tr>
<?php endforeach; ?>
              </tbody>
            </table>
<?php endif; ?>
          </section>

          <p class="dashboard-footer-note"><?= h(info('admin_actions_pending')) ?></p>

          <form method="post" action="<?= h($pluriversePrefix . '/admin') ?>" class="dashboard-logout-form">
            <?= pluriverse_csrf_field() ?>
            <input type="hidden" name="action" value="logout">
            <button type="submit"><?= h(info('dashboard_logout_button')) ?></button>
          </form>
        </main>
        <?php
        require __DIR__ . '/../partials/footer.php';
        return;
    }
}

// -----------------------------------------------------------------------
// Unauthenticated path: a non-admin landed on /admin OR an operator-session
// landed here. In either case treat as logged-out.
// -----------------------------------------------------------------------
$loginSent = false;
$loginError = '';

if ($method === 'POST') {
    if (function_exists('apcu_inc')) {
        $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
        $bucket = 'pluriverse_admin_login:' . date('YmdH') . ':' . $rateIp;
        $success = false;
        $count = apcu_inc($bucket, 1, $success, 3700);
        if ($count !== false && (int)$count > 5) {
            http_response_code(429);
            $loginError = info('admin_login_rate_limited');
        }
    }
    if ($loginError === '') {
        $emailInput = trim((string)($_POST['email'] ?? ''));
        if ($emailInput === '' || !filter_var($emailInput, FILTER_VALIDATE_EMAIL) || strlen($emailInput) > 254) {
            $loginError = info('admin_login_invalid_email');
        } else {
            try {
                $lookupHash = federation_pii_lookup_hash($emailInput);
                $admin = db_get_admin_by_email_lookup_hash($lookupHash);
                if ($admin !== null) {
                    $tokenRaw = db_create_magic_link_token($lookupHash, 86400, 'admin');
                    $tokenUrl = 'https://www.telaris.ca/operators/verify-magic-link?t=' . federation_token_url_encode($tokenRaw);

                    // Admin emails ship in EN for now (admin chrome doesn't
                    // yet have a per-admin locale preference).
                    $subject = 'Sign in to the Pluriverse admin';
                    $body = "Hello {$admin['display_name']},\n\n"
                          . "Open the link below to sign in to the Pluriverse admin surface.\n"
                          . "The link is single-use and expires in 24 hours.\n\n"
                          . "  {$tokenUrl}\n\n"
                          . "If you did not request a sign-in, you can safely ignore this email.\n\n"
                          . "Pluriverse - https://www.telaris.ca/\n";
                    require_once __DIR__ . '/../mail.php';
                    pluriverse_send_mail($emailInput, $subject, $body);
                }
                $loginSent = true;
            } catch (Throwable $e) {
                error_log('admin: login request failed: ' . $e->getMessage());
                $loginSent = true; // non-enumerating: still pretend
            }
        }
    }
}

$pageTitle = info('admin_login_title');
$bodyClass = 'page-admin page-admin-login';
$includeBg = false;
require __DIR__ . '/../partials/head.php';
?>
<main class="page page-dashboard-login">
  <h1 class="page-title"><?= h(info('admin_login_title')) ?></h1>
  <p class="page-lead"><?= h(info('admin_login_lead')) ?></p>

<?php if ($loginSent): ?>
  <div class="dashboard-callout dashboard-callout-ok">
    <p><?= h(info('admin_login_sent')) ?></p>
  </div>
<?php else: ?>
  <?php if ($loginError !== ''): ?>
    <div class="dashboard-callout dashboard-callout-error">
      <p><?= h($loginError) ?></p>
    </div>
  <?php endif; ?>
  <form method="post" action="<?= h($pluriversePrefix . '/admin') ?>" class="dashboard-login-form">
    <label for="admin-email"><?= h(info('admin_login_email_label')) ?></label>
    <input type="email" id="admin-email" name="email" required maxlength="254" autocomplete="email" inputmode="email">
    <button type="submit"><?= h(info('admin_login_button')) ?></button>
  </form>
<?php endif; ?>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
