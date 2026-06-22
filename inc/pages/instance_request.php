<?php
declare(strict_types=1);

/**
 * Public self-service instance request (Phase 3c) + double-opt-in email
 * confirmation (Phase 3d).
 *
 * GET  /request-instance              -> the request form (or a closed notice)
 * GET  /request-instance?confirm=TOK  -> consume a confirmation token
 * POST /request-instance              -> create a pending_confirmation request
 *                                        and email a confirmation link
 *
 * Anti-abuse stack (no captcha at launch, by operator decision): APCu rate
 * limiting + a honeypot field + the double opt-in email + super-admin vetting
 * (the real gate, 3e). Open/closed is a super-admin switch (default closed).
 *
 * Anti-enumeration: a syntactically valid submission always renders the same
 * "check your email" result, whether or not we actually created a request
 * (banned operator, over the per-operator cap, or a duplicate). Only input
 * errors (bad email, bad/taken subdomain, missing name, missing consent) are
 * shown inline; none of those reveal whether an account exists.
 */

require_once __DIR__ . '/../content.php';
require_once __DIR__ . '/../db_federation.php';
require_once __DIR__ . '/../federation/pii.php';

global $pluriverseLocale, $pluriversePrefix;
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/** Public-facing label validation (the worker re-validates authoritatively). */
function pluriverse_request_label_valid(string $label): bool {
    if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $label)) return false;
    if (str_contains($label, '--') || str_ends_with($label, '-')) return false;
    static $reserved = [
        'www', 'mail', 'admin', 'api', 'ns', 'ns1', 'ns2', 'mx', 'ftp', 'smtp',
        'imap', 'pop', 'webmail', 'cpanel', 'whm', 'localhost', 'pluriverse',
        'orrery', 'observatory', 'telaris', 'starmaps', 'grsj306', 'qwsp',
        'fleet', 'test', 'staging', 'prod', 'production', 'root', 'postgres',
    ];
    return !in_array($label, $reserved, true);
}

// ===========================================================================
// Confirm sub-flow (3d): /request-instance?confirm=TOKEN
// ===========================================================================
$confirmTokenEnc = trim((string)($_GET['confirm'] ?? ''));
if ($confirmTokenEnc !== '') {
    $heading = info('request_confirm_heading_invalid');
    $body    = info('request_confirm_body_invalid');

    $raw = federation_token_url_decode($confirmTokenEnc);
    if ($raw !== '' && strlen($raw) === 32) {
        try {
            $res = db_consume_magic_link_token($raw);
            if ($res !== null && ($res['purpose'] ?? '') === 'request') {
                $requestId = $res['instance_id'] !== null ? (int)$res['instance_id'] : 0;
                if ($res['status'] === 'consumed') {
                    // First valid click: flip pending_confirmation -> confirmed.
                    $ok = $requestId > 0 && db_transition_instance_request(
                        $requestId, 'pending_confirmation', 'confirmed', 'self-service', ['confirmed_at' => true]
                    );
                    if ($ok) {
                        $heading = info('request_confirm_heading_ok');
                        $body    = info('request_confirm_body_ok');
                    } else {
                        // Token was fresh but the request is no longer pending
                        // (already confirmed elsewhere, or rejected/banned).
                        $heading = info('request_confirm_heading_already');
                        $body    = info('request_confirm_body_already');
                    }
                } elseif ($res['status'] === 'already_consumed') {
                    $heading = info('request_confirm_heading_already');
                    $body    = info('request_confirm_body_already');
                } elseif ($res['status'] === 'expired') {
                    $heading = info('request_confirm_heading_expired');
                    $body    = info('request_confirm_body_expired');
                }
            }
        } catch (Throwable $e) {
            error_log('instance_request confirm: ' . $e->getMessage());
        }
    }

    $pageTitle = $heading;
    $bodyClass = 'page-request page-request-confirm';
    $includeBg = false;
    $useDaisyui = true;
    require __DIR__ . '/../partials/head.php';
    ?>
    <main class="page ss-daisy" data-theme="dark">
      <h1 class="page-title"><?= h($heading) ?></h1>
      <p class="page-lead"><?= h($body) ?></p>
      <p><a class="btn btn-primary btn-sm" href="<?= h($pluriversePrefix) ?>/"><?= h(info('verify_back_home')) ?></a></p>
    </main>
    <?php
    require __DIR__ . '/../partials/footer.php';
    return;
}

// ===========================================================================
// Form + submission
// ===========================================================================
$open = db_self_service_is_open();
$submitted = false;
$errors = [];
$v = [
    'name' => trim((string)($_POST['name'] ?? '')),
    'email' => trim((string)($_POST['email'] ?? '')),
    'label' => strtolower(trim((string)($_POST['label'] ?? ''))),
    'site_name' => trim((string)($_POST['site_name'] ?? '')),
    'tagline' => trim((string)($_POST['tagline'] ?? '')),
    'locale' => (string)($_POST['locale'] ?? $pluriverseLocale),
    'framing' => trim((string)($_POST['framing'] ?? '')),
    'federate' => !isset($_POST['submitted']) ? true : isset($_POST['federate']),
];
if (!in_array($v['locale'], ['en', 'es', 'pt', 'fr'], true)) $v['locale'] = 'en';

if ($method === 'POST' && $open) {
    // Honeypot: a real user never fills the off-screen "website" field.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $submitted = true; // pretend success; create nothing
    } else {
        // Rate limit per client IP (Cloudflare-aware).
        $rateLimited = false;
        if (function_exists('apcu_inc')) {
            $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
            $bucket = 'pluriverse_instance_request:' . date('YmdH') . ':' . $rateIp;
            $success = false;
            $count = apcu_inc($bucket, 1, $success, 3700);
            if ($count !== false && (int)$count > 5) {
                http_response_code(429);
                $errors['rate'] = info('request_err_rate_limited');
                $rateLimited = true;
            }
        }

        if (!$rateLimited) {
            // Field validation (these errors are about the input, not about
            // whether an account exists, so showing them leaks nothing).
            if ($v['name'] === '' || mb_strlen($v['name']) > 255) {
                $errors['name'] = info('request_err_name');
            }
            if ($v['email'] === '' || !filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 254) {
                $errors['email'] = info('request_err_email');
            }
            if (!pluriverse_request_label_valid($v['label'])) {
                $errors['label'] = info('request_err_label_invalid');
            }
            if ($v['site_name'] === '' || strlen($v['site_name']) > 255) {
                $errors['site_name'] = info('request_err_sitename');
            }
            if ($v['framing'] === '' || mb_strlen($v['framing']) > 2000) {
                $errors['framing'] = info('request_err_framing');
            }
            if (!isset($_POST['consent'])) {
                $errors['consent'] = info('request_err_consent');
            }
            // Subdomain availability (labels are public; revealing this leaks nothing).
            if (!isset($errors['label']) && db_label_in_use($v['label'])) {
                $errors['label'] = info('request_err_label_taken');
            }

            if ($errors === []) {
                // Past validation. From here we ALWAYS render the generic
                // success, whether or not we create a request, so a banned /
                // capped / duplicate operator cannot be enumerated.
                $submitted = true;
                try {
                    $lookupHash = federation_pii_lookup_hash($v['email']);
                    $banned = db_operator_is_banned($lookupHash);
                    $overCap = db_count_active_requests_by_lookup_hash($lookupHash) >= db_self_service_operator_cap();
                    if (!$banned && !$overCap) {
                        $requestId = db_insert_instance_request([
                            'label' => $v['label'],
                            'site_name' => $v['site_name'],
                            'site_tagline' => $v['tagline'],
                            'editorial_framing' => $v['framing'],
                            'locale' => $v['locale'],
                            'federate' => $v['federate'],
                            'operator_name' => $v['name'],
                            'operator_email' => $v['email'],
                            'request_ip' => (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
                        ]);
                        // 48h single-use confirmation token; request id rides in
                        // the token's instance_id slot (no FK; documented).
                        $tokenRaw = db_create_magic_link_token($lookupHash, 172800, 'request', $requestId);
                        $prefix = $v['locale'] === 'en' ? '' : '/' . $v['locale'];
                        $confirmUrl = 'https://www.telaris.ca' . $prefix . '/request-instance?confirm='
                            . federation_token_url_encode($tokenRaw);
                        pluriverse_send_request_confirmation($v['email'], $v['locale'], $v['label'], $confirmUrl);
                        pluriverse_log_event('instance_request_submitted', 'success', null, 'request#' . $requestId, 'label=' . $v['label']);
                    }
                } catch (Throwable $e) {
                    error_log('instance_request submit: ' . $e->getMessage());
                    // Still render success (anti-enumeration); the operator can retry.
                }
            }
        }
    }
}

/** Send the localized double-opt-in confirmation email. */
function pluriverse_send_request_confirmation(string $to, string $locale, string $label, string $confirmUrl): void {
    require_once __DIR__ . '/../mail.php';
    require_once __DIR__ . '/../email-template.php';
    $bodies = [
        'en' => [
            'subject' => 'Confirm your Telaris instance request',
            'heading' => 'Confirm your instance request',
            'paragraphs' => [
                "You (or someone using your email) asked for a Telaris instance at \"{$label}.telaris.ca\". Confirm the request with the button below so an administrator can review it. The link is single-use and expires in 48 hours.",
            ],
            'cta_label' => 'Confirm my request',
            'note' => 'If you did not ask for an instance, you can safely ignore this email.',
        ],
        'es' => [
            'subject' => 'Confirma tu solicitud de instancia de Telaris',
            'heading' => 'Confirma tu solicitud de instancia',
            'paragraphs' => [
                "Tú (o alguien con tu correo) solicitó una instancia de Telaris en \"{$label}.telaris.ca\". Confirma la solicitud con el botón de abajo para que la administración pueda revisarla. El enlace es de un solo uso y caduca en 48 horas.",
            ],
            'cta_label' => 'Confirmar mi solicitud',
            'note' => 'Si no solicitaste una instancia, puedes ignorar este correo.',
        ],
        'pt' => [
            'subject' => 'Confirme sua solicitação de instância do Telaris',
            'heading' => 'Confirme sua solicitação de instância',
            'paragraphs' => [
                "Você (ou alguém com o seu email) solicitou uma instância do Telaris em \"{$label}.telaris.ca\". Confirme a solicitação com o botão abaixo para que a administração possa revisá-la. O link é de uso único e expira em 48 horas.",
            ],
            'cta_label' => 'Confirmar minha solicitação',
            'note' => 'Se você não solicitou uma instância, pode ignorar este email.',
        ],
        'fr' => [
            'subject' => 'Confirme ta demande d\'instance Telaris',
            'heading' => 'Confirme ta demande d\'instance',
            'paragraphs' => [
                "Toi (ou quelqu'un avec ton courriel) as demandé une instance Telaris à \"{$label}.telaris.ca\". Confirme la demande avec le bouton ci-dessous pour qu'une administration puisse l'examiner. Le lien est à usage unique et expire dans 48 heures.",
            ],
            'cta_label' => 'Confirmer ma demande',
            'note' => "Si tu n'as pas demandé d'instance, tu peux ignorer ce courriel.",
        ],
    ];
    $tpl = $bodies[$locale] ?? $bodies['en'];
    $rendered = pluriverse_email_render([
        'heading' => $tpl['heading'],
        'paragraphs' => $tpl['paragraphs'],
        'cta' => ['label' => $tpl['cta_label'], 'url' => $confirmUrl],
        'note' => $tpl['note'],
        'locale' => $locale,
    ]);
    pluriverse_send_mail($to, $tpl['subject'], $rendered['text'], $rendered['html']);
}

// ===========================================================================
// Render
// ===========================================================================
$pageTitle = info('request_title');
$bodyClass = 'page-request';
$includeBg = false;
$useDaisyui = true;
require __DIR__ . '/../partials/head.php';
?>
<main class="page ss-daisy" data-theme="dark">
  <h1 class="page-title"><?= h(info('request_title')) ?></h1>

<?php if ($submitted): ?>
  <h2><?= h(info('request_success_heading')) ?></h2>
  <p class="page-lead"><?= h(info('request_success_body')) ?></p>
  <p><a class="btn btn-primary btn-sm" href="<?= h($pluriversePrefix) ?>/"><?= h(info('verify_back_home')) ?></a></p>

<?php elseif (!$open): ?>
  <div class="prose"><?= pluriverse_commonmark()->convert(info('request_closed_notice'))->getContent() ?></div>

<?php else: ?>
  <p class="page-lead"><?= h(info('request_lead')) ?></p>
  <p class="ss-required-note"><?= h(info('request_required_note')) ?></p>

<?php if (!empty($errors)): ?>
  <div class="form-errors" role="alert">
    <ul>
<?php foreach ($errors as $msg): ?>
      <li><?= h($msg) ?></li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

  <form method="post" action="<?= h($pluriversePrefix) ?>/request-instance" class="request-form" novalidate>
    <input type="hidden" name="submitted" value="1">
    <!-- Honeypot: visually hidden; bots fill it, humans never see it. -->
    <div aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden">
      <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <div class="form-control ss-field">
      <label class="label" for="rq-name"><span class="label-text"><?= h(info('request_name_label')) ?> <span class="ss-required" aria-hidden="true">*</span></span></label>
      <input id="rq-name" type="text" name="name" required maxlength="255" value="<?= h($v['name']) ?>" placeholder="<?= h(info('request_name_ph')) ?>" class="input input-bordered">
    </div>

    <div class="form-control ss-field">
      <label class="label" for="rq-email"><span class="label-text"><?= h(info('request_email_label')) ?> <span class="ss-required" aria-hidden="true">*</span></span></label>
      <input id="rq-email" type="email" name="email" required maxlength="254" value="<?= h($v['email']) ?>" placeholder="<?= h(info('request_email_ph')) ?>" class="input input-bordered">
    </div>

    <div class="form-control ss-field">
      <label class="label" for="rq-label"><span class="label-text"><?= h(info('request_label_label')) ?> <span class="ss-required" aria-hidden="true">*</span></span></label>
      <input id="rq-label" type="text" name="label" required maxlength="31" value="<?= h($v['label']) ?>" pattern="[a-z][a-z0-9-]{1,30}" class="input input-bordered">
      <span class="ss-help"><?= h(info('request_label_help')) ?></span>
    </div>

    <div class="form-control ss-field">
      <label class="label" for="rq-sitename"><span class="label-text"><?= h(info('request_sitename_label')) ?> <span class="ss-required" aria-hidden="true">*</span></span></label>
      <input id="rq-sitename" type="text" name="site_name" required maxlength="255" value="<?= h($v['site_name']) ?>" placeholder="<?= h(info('request_sitename_ph')) ?>" class="input input-bordered">
    </div>

    <div class="form-control ss-field">
      <label class="label" for="rq-tagline"><span class="label-text"><?= h(info('request_tagline_label')) ?></span></label>
      <input id="rq-tagline" type="text" name="tagline" maxlength="512" value="<?= h($v['tagline']) ?>" placeholder="<?= h(info('request_tagline_ph')) ?>" class="input input-bordered">
    </div>

    <div class="form-control ss-field">
      <label class="label" for="rq-locale"><span class="label-text"><?= h(info('request_locale_label')) ?> <span class="ss-required" aria-hidden="true">*</span></span></label>
      <select id="rq-locale" name="locale" required class="select select-bordered">
<?php foreach (['en' => 'English', 'es' => 'Español', 'pt' => 'Português', 'fr' => 'Français'] as $code => $name): ?>
        <option value="<?= h($code) ?>"<?= $v['locale'] === $code ? ' selected' : '' ?>><?= h($name) ?></option>
<?php endforeach; ?>
      </select>
    </div>

    <div class="form-control ss-field">
      <label class="label" for="rq-framing"><span class="label-text"><?= h(info('request_framing_label')) ?> <span class="ss-required" aria-hidden="true">*</span></span></label>
      <textarea id="rq-framing" name="framing" rows="3" required maxlength="2000" class="textarea textarea-bordered"><?= h($v['framing']) ?></textarea>
      <span class="ss-help"><?= h(info('request_framing_help')) ?></span>
    </div>

    <div class="form-control ss-field">
      <label class="ss-checkbox-row">
        <input type="checkbox" name="federate" value="1"<?= $v['federate'] ? ' checked' : '' ?> class="checkbox checkbox-sm">
        <span><strong><?= h(info('request_federate_label')) ?></strong>: <?= h(info('request_federate_help')) ?></span>
      </label>
    </div>

    <div class="form-control ss-field">
      <label class="ss-checkbox-row">
        <input type="checkbox" name="consent" value="1"<?= isset($_POST['consent']) ? ' checked' : '' ?> required class="checkbox checkbox-sm">
        <span><?= info('request_consent_html') ?></span>
      </label>
    </div>

    <div class="ss-field">
      <button type="submit" class="btn btn-primary"><?= h(info('request_submit')) ?></button>
    </div>
  </form>
<?php endif; ?>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
