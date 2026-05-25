<?php
declare(strict_types=1);

/**
 * /dashboard, operator self-service.
 *
 * Three states:
 *
 *   GET, no session:
 *     Render the login form: a single-field email input.
 *
 *   POST, no session:
 *     Mint a magic-link token for the supplied email IF an instance with
 *     that operator_email_lookup_hash exists and its admission_status
 *     permits login. Always render the same "if we found an account, we
 *     sent a link" confirmation page (no enumeration). Same magic-link
 *     verify URL the apply flow uses; the verify handler decides what
 *     happens on click based on instance status.
 *
 *   GET, valid session:
 *     Render the own-instance view: summary, contacts (decrypted),
 *     galaxies summary, status-log history.
 *
 * Locale: bootstrap's URL-derived value is used until the operator's
 * instance is known, then we switch to instance.locale (same pattern as
 * operators_verify.php). Edits and withdrawal land with 2g-ii (CSRF).
 *
 * Rate limit: 5 login email requests per IP per hour. APCu-backed.
 */

require_once __DIR__ . '/../db_federation.php';
require_once __DIR__ . '/../federation/session.php';
require_once __DIR__ . '/../federation/pii.php';

global $pluriverseLocale, $pluriverseInfo, $pluriversePrefix;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$session = pluriverse_current_session();

// -----------------------------------------------------------------------
// Authenticated view.
// -----------------------------------------------------------------------
if ($session !== null && $session['subject_type'] === 'operator') {
    $instance = db_get_instance_by_id($session['subject_id']);
    if ($instance === null) {
        pluriverse_session_clear_cookie();
        $session = null;
    } else {
        $allowedStates = ['verified', 'published', 'outdated', 'withdrawn'];
        if (!in_array($instance['admission_status'], $allowedStates, true)) {
            // Session exists but instance state denies further dashboard
            // access (rejected, blacklisted, revoked). Drop the session.
            $raw = pluriverse_session_read_cookie();
            if ($raw !== '' && strlen($raw) === 32) db_destroy_session($raw);
            pluriverse_session_clear_cookie();
            $session = null;
            $instance = null;
        }
    }

    if ($instance !== null) {
        // Switch chrome locale to the operator's preferred language.
        if (in_array((string)$instance['locale'], ['en', 'es', 'pt', 'fr'], true)) {
            $pluriverseLocale = (string)$instance['locale'];
            $pluriversePrefix = ($pluriverseLocale === 'en') ? '' : '/' . $pluriverseLocale;
            $pluriverseInfo = db_get_project_info_for_locale($pluriverseLocale);
            if ($pluriverseInfo === []) {
                $pluriverseInfo = db_get_project_info_for_locale('en');
                $pluriverseLocale = 'en';
                $pluriversePrefix = '';
            }
        }

        // Decrypt PII for display. Decryption failures are logged but
        // shouldn't break the dashboard view.
        $emailDisplay = '';
        try {
            $rowContext = federation_row_context_for_instance((string)$instance['operator_email_lookup_hash']);
            $emailDisplay = federation_pii_decrypt((string)$instance['operator_email_enc'], $rowContext, 'email');
        } catch (Throwable $e) {
            error_log('dashboard: email decrypt failed for instance ' . $instance['id'] . ': ' . $e->getMessage());
        }
        $otherContacts = [];
        if (!empty($instance['other_contacts_enc'])) {
            try {
                $json = federation_pii_decrypt((string)$instance['other_contacts_enc'], $rowContext, 'other_contacts');
                $decoded = json_decode($json, true);
                if (is_array($decoded)) $otherContacts = $decoded;
            } catch (Throwable $e) {
                error_log('dashboard: other_contacts decrypt failed for instance ' . $instance['id'] . ': ' . $e->getMessage());
            }
        }
        $publishableSlugs = [];
        if (!empty($instance['publishable_slugs'])) {
            $decoded = json_decode((string)$instance['publishable_slugs'], true);
            if (is_array($decoded)) $publishableSlugs = $decoded;
        }

        $statusLog = db_get_instance_status_log((int)$instance['id'], 25);

        $pageTitle = info('dashboard_title');
        $bodyClass = 'page-dashboard';
        $includeBg = false;
        require __DIR__ . '/../partials/head.php';
        ?>
        <main class="page page-dashboard">
          <h1 class="page-title"><?= h(info('dashboard_title')) ?></h1>
          <p class="page-lead"><?= h(info('dashboard_lead')) ?></p>

          <section class="dashboard-section">
            <h2><?= h(info('dashboard_section_summary')) ?></h2>
            <dl class="dashboard-summary">
              <dt><?= h(info('dashboard_label_name')) ?></dt>
              <dd><?= h((string)$instance['label']) ?></dd>
              <dt><?= h(info('dashboard_label_url')) ?></dt>
              <dd><a href="<?= h((string)$instance['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$instance['url']) ?></a></dd>
              <dt><?= h(info('dashboard_label_hostname')) ?></dt>
              <dd><code><?= h((string)$instance['hostname']) ?></code></dd>
              <dt><?= h(info('dashboard_label_status')) ?></dt>
              <dd><?= h(info('verify_status_' . $instance['admission_status'])) ?></dd>
              <dt><?= h(info('dashboard_label_locale')) ?></dt>
              <dd><?= h((string)$instance['locale']) ?></dd>
              <dt><?= h(info('dashboard_label_created')) ?></dt>
              <dd><time datetime="<?= h((string)$instance['created_at']) ?>"><?= h((string)$instance['created_at']) ?></time></dd>
            </dl>
          </section>

          <section class="dashboard-section">
            <h2><?= h(info('dashboard_section_contacts')) ?></h2>
            <dl class="dashboard-summary">
              <dt><?= h(info('dashboard_label_email')) ?></dt>
              <dd><?= h($emailDisplay) ?></dd>
            </dl>
            <?php if ($otherContacts !== []): ?>
              <p class="dashboard-help"><?= h(info('dashboard_label_other_contacts')) ?></p>
              <ul class="dashboard-contacts">
                <?php foreach ($otherContacts as $c): ?>
                  <?php if (!is_array($c) || !isset($c['service'], $c['user_id'])) continue; ?>
                  <li><strong><?= h((string)$c['service']) ?>:</strong> <?= h((string)$c['user_id']) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>

          <section class="dashboard-section">
            <h2><?= h(info('dashboard_section_galaxies')) ?></h2>
            <?php if ($publishableSlugs === []): ?>
              <p class="dashboard-help"><?= h(info('dashboard_galaxies_none')) ?></p>
            <?php else: ?>
              <p class="dashboard-help"><?= h(sprintf(info('dashboard_galaxies_count_fmt'), count($publishableSlugs))) ?></p>
              <ul class="dashboard-galaxies">
                <?php foreach ($publishableSlugs as $slug): ?>
                  <li><code><?= h((string)$slug) ?></code></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <p class="dashboard-help dashboard-help-quiet"><?= h(info('dashboard_galaxies_rescan_note')) ?></p>
          </section>

          <section class="dashboard-section">
            <h2><?= h(info('dashboard_section_history')) ?></h2>
            <?php if ($statusLog === []): ?>
              <p class="dashboard-help"><?= h(info('dashboard_history_none')) ?></p>
            <?php else: ?>
              <ol class="dashboard-history">
                <?php foreach ($statusLog as $entry): ?>
                  <li>
                    <time datetime="<?= h((string)$entry['created_at']) ?>"><?= h((string)$entry['created_at']) ?></time>
                    <code><?= h((string)$entry['action']) ?></code>
                    <span class="actor"><?= h((string)($entry['actor'] ?? '')) ?></span>
                    <?php if (!empty($entry['details_summary'])): ?>
                      <p class="details"><?= h((string)$entry['details_summary']) ?></p>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </section>

          <p class="dashboard-footer-note"><?= h(info('dashboard_edit_pending')) ?></p>
        </main>
        <?php
        require __DIR__ . '/../partials/footer.php';
        return;
    }
}

// -----------------------------------------------------------------------
// Unauthenticated POST: process login email request.
// -----------------------------------------------------------------------
$loginSent = false;
$loginError = '';
if ($method === 'POST') {
    if (function_exists('apcu_inc')) {
        $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
        $bucket = 'pluriverse_dashboard_login:' . date('YmdH') . ':' . $rateIp;
        $success = false;
        $count = apcu_inc($bucket, 1, $success, 3700);
        if ($count !== false && (int)$count > 5) {
            http_response_code(429);
            $loginError = info('dashboard_login_rate_limited');
        }
    }
    if ($loginError === '') {
        $emailInput = trim((string)($_POST['email'] ?? ''));
        if ($emailInput === '' || !filter_var($emailInput, FILTER_VALIDATE_EMAIL) || strlen($emailInput) > 254) {
            $loginError = info('dashboard_login_invalid_email');
        } else {
            try {
                $lookupHash = federation_pii_lookup_hash($emailInput);
                $instance = db_get_instance_by_email_lookup_hash($lookupHash);
                if ($instance !== null && in_array((string)$instance['admission_status'], ['verified', 'published', 'outdated', 'withdrawn'], true)) {
                    $tokenRaw = db_create_magic_link_token($lookupHash, 3600);
                    $tokenUrl = 'https://www.telaris.ca/operators/verify-magic-link?t=' . federation_token_url_encode($tokenRaw);

                    // Use the operator's stored locale for the email body.
                    $emailLocale = (string)$instance['locale'];
                    if (!in_array($emailLocale, ['en', 'es', 'pt', 'fr'], true)) $emailLocale = 'en';

                    $emailBodies = [
                        'en' => [
                            'subject' => 'Sign in to your Pluriverse dashboard',
                            'body' => "Hello,\n\n"
                                    . "Open the link below to sign in to your Pluriverse dashboard for the\n"
                                    . "instance \"{$instance['label']}\". The link is single-use and expires\n"
                                    . "in one hour.\n\n"
                                    . "  {$tokenUrl}\n\n"
                                    . "If you did not request a sign-in, you can safely ignore this email.\n\n"
                                    . "Pluriverse - https://www.telaris.ca/\n",
                        ],
                        'es' => [
                            'subject' => 'Inicia sesión en tu panel de la Pluriverse',
                            'body' => "Hola,\n\n"
                                    . "Abre el enlace siguiente para iniciar sesión en tu panel de la\n"
                                    . "Pluriverse para la instancia \"{$instance['label']}\". El enlace es de\n"
                                    . "un solo uso y caduca en una hora.\n\n"
                                    . "  {$tokenUrl}\n\n"
                                    . "Si no solicitaste iniciar sesión, puedes ignorar este correo.\n\n"
                                    . "Pluriverse - https://www.telaris.ca/\n",
                        ],
                        'pt' => [
                            'subject' => 'Entrar no seu painel da Pluriverse',
                            'body' => "Olá,\n\n"
                                    . "Abra o link abaixo para entrar no seu painel da Pluriverse para a\n"
                                    . "instância \"{$instance['label']}\". O link é de uso único e expira em\n"
                                    . "uma hora.\n\n"
                                    . "  {$tokenUrl}\n\n"
                                    . "Se você não solicitou entrar, pode ignorar este email.\n\n"
                                    . "Pluriverse - https://www.telaris.ca/\n",
                        ],
                        'fr' => [
                            'subject' => 'Connecte-toi à ton tableau de bord Pluriverse',
                            'body' => "Bonjour,\n\n"
                                    . "Ouvre le lien ci-dessous pour te connecter à ton tableau de bord\n"
                                    . "Pluriverse pour l'instance \"{$instance['label']}\". Le lien est à\n"
                                    . "usage unique et expire dans une heure.\n\n"
                                    . "  {$tokenUrl}\n\n"
                                    . "Si tu n'as pas demandé à te connecter, tu peux ignorer ce courriel.\n\n"
                                    . "Pluriverse - https://www.telaris.ca/\n",
                        ],
                    ];
                    $tpl = $emailBodies[$emailLocale];
                    require_once __DIR__ . '/../mail.php';
                    pluriverse_send_mail($emailInput, $tpl['subject'], $tpl['body']);
                }
                // Always show the same confirmation, regardless of whether
                // we actually sent. Non-enumeration.
                $loginSent = true;
            } catch (Throwable $e) {
                error_log('dashboard: login request failed: ' . $e->getMessage());
                $loginSent = true; // still pretend
            }
        }
    }
}

// -----------------------------------------------------------------------
// Unauthenticated GET (or POST that landed back here).
// -----------------------------------------------------------------------
$pageTitle = info('dashboard_login_title');
$bodyClass = 'page-dashboard page-dashboard-login';
$includeBg = false;
require __DIR__ . '/../partials/head.php';
?>
<main class="page page-dashboard-login">
  <h1 class="page-title"><?= h(info('dashboard_login_title')) ?></h1>
  <p class="page-lead"><?= h(info('dashboard_login_lead')) ?></p>

<?php if ($loginSent): ?>
  <div class="dashboard-callout dashboard-callout-ok">
    <p><?= h(info('dashboard_login_sent')) ?></p>
  </div>
<?php else: ?>
  <?php if ($loginError !== ''): ?>
    <div class="dashboard-callout dashboard-callout-error">
      <p><?= h($loginError) ?></p>
    </div>
  <?php endif; ?>
  <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>" class="dashboard-login-form">
    <label for="dashboard-email"><?= h(info('dashboard_login_email_label')) ?></label>
    <input type="email" id="dashboard-email" name="email" required maxlength="254" autocomplete="email" inputmode="email">
    <button type="submit"><?= h(info('dashboard_login_button')) ?></button>
  </form>
<?php endif; ?>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
