<?php
declare(strict_types=1);

/**
 * /apply  (and /es/apply, /pt/apply, /fr/apply)
 *
 * Operator-application form. Posts to /api/pluriverse/operators/apply
 * via fetch(); on success the form area swaps to a thank-you panel with
 * the captured public-key fingerprint.
 *
 * Locale-aware routing only at this stage: form strings stay EN until
 * 2f-iii-c lifts them into project_info. An inline $strings dict holds
 * them so that lift is a key-extraction, not a markup search.
 *
 * Hard requirement: JavaScript (form submission, async name-availability
 * check, dynamic galaxies picker, repeatable contact rows). A noscript
 * notice tells visitors without JS to email the admin out of band.
 */

$strings = [
    'page_title' => 'Apply to join the Pluriverse',
    'lead' => 'If you run a Telaris instance and want it published in the Pluriverse, fill in the form below. We will fetch your instance\'s identity envelope to capture your public key, then email you a verification link. After that an admin reviews the application.',
    'noscript' => 'This form needs JavaScript to submit. Enable it, or write to the Pluriverse admin out of band.',

    'field_url_label' => 'URL',
    'field_url_help' => 'Canonical https:// URL of your instance. The hostname is derived from this.',

    'field_name_label' => 'Name',
    'field_name_help' => 'Short editorial name for your instance, unique across the Pluriverse.',
    'name_checking' => 'Checking…',
    'name_available' => 'Available',
    'name_taken' => 'Already taken',
    'name_invalid' => 'Invalid',

    'field_email_label' => 'Operator email',
    'field_email_help' => 'Magic-link target. Encrypted at rest.',

    'field_framing_label' => 'Editorial framing',
    'field_framing_help' => 'A sentence or three. What is your instance for? Optional.',

    'field_galaxies_label' => 'Publishable galaxies',
    'field_galaxies_help' => 'Load the galaxies from your instance, then uncheck any you do not want public. At least one must stay checked.',
    'galaxies_load' => 'Load from my instance',
    'galaxies_loading' => 'Loading…',
    'galaxies_empty' => 'Your instance returned no galaxies. Add a galaxy in your instance, then come back.',
    'galaxies_load_failed' => 'Could not load galaxies. Confirm the URL is reachable, then try again.',
    'galaxies_check_all' => 'Check all',
    'galaxies_uncheck_all' => 'Uncheck all',
    'galaxies_required' => 'Pick at least one galaxy to publish.',

    'field_contacts_label' => 'Secondary contacts',
    'field_contacts_help' => 'Optional fallback channels. Up to eight.',
    'contact_service_placeholder' => 'service',
    'contact_user_id_placeholder' => 'handle / address',
    'contact_add' => 'Add another',
    'contact_remove' => 'Remove',

    'submit' => 'Submit application',
    'submitting' => 'Submitting…',

    'success_title' => 'Application received',
    'success_body' => 'Check your inbox for a verification link. The link expires in one hour; the pending application itself expires in 48 hours if you do not verify.',
    'success_fingerprint_label' => 'Captured public-key fingerprint',
    'success_fingerprint_help' => 'Compare against your instance\'s bin/init-identity --check to confirm the Pluriverse stored the correct key.',

    'error_generic' => 'Something went wrong. Please try again in a minute, or contact the Pluriverse admin if it keeps failing.',
];

$pageTitle = $strings['page_title'];
$bodyClass = 'page-apply';
$includeBg = false;
require __DIR__ . '/../partials/head.php';
?>

<main class="page page-form">
  <h1 class="page-title"><?= h($strings['page_title']) ?></h1>
  <p class="page-lead"><?= h($strings['lead']) ?></p>

  <noscript>
    <div class="form-alert" role="alert"><?= h($strings['noscript']) ?></div>
  </noscript>

  <div id="apply-error" class="form-alert" role="alert" hidden></div>

  <div id="apply-success" class="form-success" role="status" hidden>
    <h2 class="form-success-title"><?= h($strings['success_title']) ?></h2>
    <p><?= h($strings['success_body']) ?></p>
    <dl class="form-success-meta">
      <dt><?= h($strings['success_fingerprint_label']) ?></dt>
      <dd><code id="apply-success-fp"></code></dd>
    </dl>
    <p class="form-success-help"><?= h($strings['success_fingerprint_help']) ?></p>
  </div>

  <form id="apply-form" class="form" novalidate>
    <input type="hidden" name="locale" value="<?= h($pluriverseLocale) ?>">

    <div class="form-field">
      <label class="form-label" for="apply-url"><?= h($strings['field_url_label']) ?></label>
      <input id="apply-url" type="url" name="url" required pattern="^https://.+" maxlength="512" autocomplete="off" spellcheck="false" placeholder="https://instance.example.org">
      <small class="form-help"><?= h($strings['field_url_help']) ?></small>
    </div>

    <div class="form-field">
      <label class="form-label" for="apply-name"><?= h($strings['field_name_label']) ?></label>
      <div class="form-input-wrap">
        <input id="apply-name" type="text" name="label" required maxlength="255" autocomplete="off" aria-describedby="apply-name-status">
        <span id="apply-name-status" class="form-status-pill" data-state=""></span>
      </div>
      <small class="form-help"><?= h($strings['field_name_help']) ?></small>
    </div>

    <div class="form-field">
      <label class="form-label" for="apply-email"><?= h($strings['field_email_label']) ?></label>
      <input id="apply-email" type="email" name="operator_email" required maxlength="254" autocomplete="email" spellcheck="false">
      <small class="form-help"><?= h($strings['field_email_help']) ?></small>
    </div>

    <div class="form-field">
      <label class="form-label" for="apply-framing"><?= h($strings['field_framing_label']) ?></label>
      <textarea id="apply-framing" name="editorial_framing" maxlength="2000" rows="3"></textarea>
      <small class="form-help"><?= h($strings['field_framing_help']) ?></small>
    </div>

    <div class="form-field">
      <span class="form-label"><?= h($strings['field_galaxies_label']) ?></span>
      <small class="form-help"><?= h($strings['field_galaxies_help']) ?></small>
      <div class="form-row-actions">
        <button type="button" id="galaxies-load" class="form-secondary-btn">
          <span class="label-idle"><?= h($strings['galaxies_load']) ?></span>
          <span class="label-busy" hidden><?= h($strings['galaxies_loading']) ?></span>
        </button>
        <button type="button" id="galaxies-check-all" class="form-link-btn" hidden><?= h($strings['galaxies_check_all']) ?></button>
        <button type="button" id="galaxies-uncheck-all" class="form-link-btn" hidden><?= h($strings['galaxies_uncheck_all']) ?></button>
      </div>
      <div id="galaxies-list" class="galaxies-list" hidden></div>
    </div>

    <div class="form-field">
      <span class="form-label"><?= h($strings['field_contacts_label']) ?></span>
      <small class="form-help"><?= h($strings['field_contacts_help']) ?></small>
      <ol id="contacts-rows" class="contacts-rows"></ol>
      <template id="contact-row-template">
        <li class="contact-row">
          <input type="text" name="contact_service[]" maxlength="64" placeholder="<?= h($strings['contact_service_placeholder']) ?>" autocomplete="off" spellcheck="false">
          <input type="text" name="contact_user_id[]" maxlength="256" placeholder="<?= h($strings['contact_user_id_placeholder']) ?>" autocomplete="off" spellcheck="false">
          <button type="button" class="contact-remove" aria-label="<?= h($strings['contact_remove']) ?>">×</button>
        </li>
      </template>
      <button type="button" id="contacts-add" class="form-secondary-btn">+ <?= h($strings['contact_add']) ?></button>
    </div>

    <button type="submit" id="apply-submit" class="form-submit">
      <span class="label-idle"><?= h($strings['submit']) ?></span>
      <span class="label-busy" hidden><?= h($strings['submitting']) ?></span>
    </button>
  </form>
</main>

<script src="/assets/apply.js?v=<?= h((string)@filemtime(dirname(__DIR__, 2) . '/assets/apply.js') ?: '0') ?>" defer></script>
<script>
  window.PLURIVERSE_APPLY_STRINGS = {
    error_generic:        <?= json_encode($strings['error_generic'],        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    name_checking:        <?= json_encode($strings['name_checking'],        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    name_available:       <?= json_encode($strings['name_available'],       JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    name_taken:           <?= json_encode($strings['name_taken'],           JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    name_invalid:         <?= json_encode($strings['name_invalid'],         JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    galaxies_empty:       <?= json_encode($strings['galaxies_empty'],       JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    galaxies_load_failed: <?= json_encode($strings['galaxies_load_failed'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    galaxies_required:    <?= json_encode($strings['galaxies_required'],    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
  };
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
