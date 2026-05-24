<?php
declare(strict_types=1);

/**
 * /apply  (and /es/apply, /pt/apply, /fr/apply)
 *
 * Operator-application form. Renders an HTML form that posts to the JSON
 * endpoint at POST /api/pluriverse/operators/apply via fetch(); on success
 * the form area swaps to a thank-you panel and the operator is told to
 * check their inbox for the verification link.
 *
 * Locale-aware routing only at this stage: strings are EN throughout (a
 * transitional inline $strings dict at the top so the i18n sweep in
 * 2f-iii-c can lift them into project_info without searching the
 * markup). Locale URL prefix is preserved on the form's hidden `locale`
 * input so the eventual ack email is rendered in the operator's locale
 * once those templates exist.
 *
 * Hard requirement: JavaScript. The form depends on fetch() for submission
 * and on a small "add another contact" UI for the optional secondary
 * contacts. A <noscript> notice tells visitors without JS to enable it or
 * email the admin directly. Adding a server-side fallback handler is
 * deferred until anyone asks.
 */

$strings = [
    'page_title' => 'Apply to join the Pluriverse',
    'lead' => 'If you run a Telaris instance and want it published in the Pluriverse, fill in the form below. We will fetch your instance\'s identity envelope to capture your public key, then email you a verification link to confirm the address you provide here. After that an admin reviews the application and lets you know when your instance is published.',
    'noscript' => 'This form needs JavaScript to submit. Enable it, or write to the Pluriverse admin out of band.',
    'section_instance' => 'Your instance',
    'section_contact' => 'Contact',
    'section_optional' => 'Optional',

    'field_hostname_label' => 'Hostname',
    'field_hostname_help' => 'DNS-style label only, lowercase. No scheme, no port, no path. Example: starmaps.polivoxia.ca',

    'field_url_label' => 'URL',
    'field_url_help' => 'Canonical https:// URL of your instance. Host must match the hostname above.',

    'field_endpoint_label' => 'Pluriverse endpoint',
    'field_endpoint_help' => 'Where your instance serves /api/pluriverse/identity. Defaults to your URL + that path; change only if your instance is not at the path root.',

    'field_email_label' => 'Operator email',
    'field_email_help' => 'Magic-link target. We encrypt this at rest; nobody but you (via magic-link auth) and the admins will ever read it.',

    'field_label_label' => 'Label',
    'field_label_help' => 'Short editorial name for your instance. Example: "Mocambos archive".',

    'field_framing_label' => 'Editorial framing',
    'field_framing_help' => 'A sentence or three. What is your instance for, and why does the Pluriverse benefit from federating with it? Optional.',

    'field_slugs_label' => 'Publishable galaxies',
    'field_slugs_help' => 'Galaxy slugs you intend to publish through the Pluriverse, one per line. Kebab-case. Optional and editable later.',

    'field_bridges_label' => 'Bridges',
    'field_bridges_help' => 'Which Telaris bridges your instance speaks. Leave unchecked if none apply.',
    'bridge_mocambos' => 'Mocambos',

    'field_contacts_label' => 'Secondary contacts',
    'field_contacts_help' => 'Optional channels we can use if email is failing (Matrix, XMPP, IRC, whatever you use). Up to eight entries.',
    'contact_service_placeholder' => 'service',
    'contact_user_id_placeholder' => 'handle / address',
    'contact_add' => 'Add another',
    'contact_remove' => 'Remove',

    'submit' => 'Submit application',
    'submitting' => 'Submitting…',

    'success_title' => 'Application received',
    'success_body' => 'Check your inbox for a verification link. The link expires in one hour; the pending application itself expires in 48 hours if you do not verify.',
    'success_fingerprint_label' => 'Captured public-key fingerprint',
    'success_fingerprint_help' => 'Compare this against your instance\'s bin/init-identity --check output. If they match, the Pluriverse has the correct key.',

    'error_generic' => 'Something went wrong. Please try again in a minute, or contact the Pluriverse admin if it keeps failing.',
];

$pageTitle = $strings['page_title'];
$bodyClass = 'page-apply';
$includeBg = false;
require __DIR__ . '/../partials/head.php';
?>

<main class="page page-form">
  <p class="page-eyebrow"><?= h($strings['page_title']) ?></p>
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

    <fieldset class="form-section">
      <legend><?= h($strings['section_instance']) ?></legend>

      <label class="form-field">
        <span class="form-label"><?= h($strings['field_hostname_label']) ?></span>
        <input type="text" name="hostname" required pattern="^[a-z0-9][a-z0-9.\-]*[a-z0-9]$" minlength="4" maxlength="255" autocomplete="off" spellcheck="false" inputmode="url">
        <small class="form-help"><?= h($strings['field_hostname_help']) ?></small>
      </label>

      <label class="form-field">
        <span class="form-label"><?= h($strings['field_url_label']) ?></span>
        <input type="url" name="url" required pattern="^https://.+" maxlength="512" autocomplete="off" spellcheck="false">
        <small class="form-help"><?= h($strings['field_url_help']) ?></small>
      </label>

      <label class="form-field">
        <span class="form-label"><?= h($strings['field_endpoint_label']) ?></span>
        <input type="url" name="pluriverse_endpoint" required pattern="^https://.+" maxlength="512" autocomplete="off" spellcheck="false">
        <small class="form-help"><?= h($strings['field_endpoint_help']) ?></small>
      </label>

      <label class="form-field">
        <span class="form-label"><?= h($strings['field_label_label']) ?></span>
        <input type="text" name="label" required maxlength="255" autocomplete="off">
        <small class="form-help"><?= h($strings['field_label_help']) ?></small>
      </label>
    </fieldset>

    <fieldset class="form-section">
      <legend><?= h($strings['section_contact']) ?></legend>

      <label class="form-field">
        <span class="form-label"><?= h($strings['field_email_label']) ?></span>
        <input type="email" name="operator_email" required maxlength="254" autocomplete="email" spellcheck="false">
        <small class="form-help"><?= h($strings['field_email_help']) ?></small>
      </label>

      <fieldset class="form-field form-subfield">
        <legend class="form-label"><?= h($strings['field_contacts_label']) ?></legend>
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
      </fieldset>
    </fieldset>

    <fieldset class="form-section">
      <legend><?= h($strings['section_optional']) ?></legend>

      <label class="form-field">
        <span class="form-label"><?= h($strings['field_framing_label']) ?></span>
        <textarea name="editorial_framing" maxlength="2000" rows="4"></textarea>
        <small class="form-help"><?= h($strings['field_framing_help']) ?></small>
      </label>

      <label class="form-field">
        <span class="form-label"><?= h($strings['field_slugs_label']) ?></span>
        <textarea name="publishable_slugs" rows="3" placeholder="example-galaxy&#10;another-galaxy"></textarea>
        <small class="form-help"><?= h($strings['field_slugs_help']) ?></small>
      </label>

      <fieldset class="form-field form-subfield">
        <legend class="form-label"><?= h($strings['field_bridges_label']) ?></legend>
        <small class="form-help"><?= h($strings['field_bridges_help']) ?></small>
        <label class="form-check">
          <input type="checkbox" name="bridges[]" value="mocambos">
          <span><?= h($strings['bridge_mocambos']) ?></span>
        </label>
      </fieldset>
    </fieldset>

    <button type="submit" id="apply-submit" class="form-submit">
      <span class="label-idle"><?= h($strings['submit']) ?></span>
      <span class="label-busy" hidden><?= h($strings['submitting']) ?></span>
    </button>
  </form>
</main>

<script src="/assets/apply.js" defer></script>
<script>
  window.PLURIVERSE_APPLY_STRINGS = {
    error_generic: <?= json_encode($strings['error_generic'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
  };
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
