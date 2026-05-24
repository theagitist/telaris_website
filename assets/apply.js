/* Operator-application form behaviour.
 *
 * Three responsibilities:
 *   1. Auto-fill `pluriverse_endpoint` from `url` on first blur when the
 *      endpoint field is still empty, since 99% of operators serve the
 *      identity envelope at <url>/api/pluriverse/identity.
 *   2. Dynamic "other contacts" rows: add up to eight, remove any.
 *   3. Submission: build the JSON body, POST to
 *      /api/pluriverse/operators/apply, render success or surface the
 *      error from the Problem Details response.
 *
 * No dependencies. Plain vanilla browser JS, supports current evergreen
 * browsers.
 */

(function () {
  'use strict';

  var form = document.getElementById('apply-form');
  if (!form) return;

  var strings = (window.PLURIVERSE_APPLY_STRINGS || {});
  var errorBanner = document.getElementById('apply-error');
  var successPanel = document.getElementById('apply-success');
  var successFingerprint = document.getElementById('apply-success-fp');
  var submitButton = document.getElementById('apply-submit');
  var contactsList = document.getElementById('contacts-rows');
  var contactRowTemplate = document.getElementById('contact-row-template');
  var contactsAddButton = document.getElementById('contacts-add');

  var MAX_CONTACTS = 8;

  // ----- Pluriverse endpoint auto-fill -----
  var urlInput = form.elements.namedItem('url');
  var endpointInput = form.elements.namedItem('pluriverse_endpoint');
  if (urlInput && endpointInput) {
    urlInput.addEventListener('blur', function () {
      if (endpointInput.value.trim() !== '') return;
      var v = urlInput.value.trim();
      if (!/^https:\/\//.test(v)) return;
      endpointInput.value = v.replace(/\/+$/, '') + '/api/pluriverse/identity';
    });
  }

  // ----- Contacts dynamic rows -----
  function addContactRow() {
    if (contactsList.children.length >= MAX_CONTACTS) return;
    var node = contactRowTemplate.content.firstElementChild.cloneNode(true);
    var removeBtn = node.querySelector('.contact-remove');
    removeBtn.addEventListener('click', function () {
      contactsList.removeChild(node);
    });
    contactsList.appendChild(node);
  }
  if (contactsAddButton) {
    contactsAddButton.addEventListener('click', addContactRow);
  }

  // ----- Submission -----
  function showError(detail) {
    errorBanner.textContent = detail || strings.error_generic || 'Error.';
    errorBanner.hidden = false;
    errorBanner.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function clearError() {
    errorBanner.hidden = true;
    errorBanner.textContent = '';
  }
  function showSuccess(payload) {
    if (successFingerprint && payload.public_key_fingerprint) {
      successFingerprint.textContent = payload.public_key_fingerprint;
    }
    form.hidden = true;
    successPanel.hidden = false;
    successPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function setBusy(busy) {
    submitButton.disabled = busy;
    var idle = submitButton.querySelector('.label-idle');
    var busyLabel = submitButton.querySelector('.label-busy');
    if (idle) idle.hidden = busy;
    if (busyLabel) busyLabel.hidden = !busy;
  }

  function buildBody() {
    var fd = new FormData(form);

    var slugsRaw = (fd.get('publishable_slugs') || '').toString();
    var slugs = slugsRaw.split('\n').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });

    var bridges = fd.getAll('bridges[]').map(String);

    var services = fd.getAll('contact_service[]').map(function (s) { return (s || '').toString().trim(); });
    var userIds = fd.getAll('contact_user_id[]').map(function (s) { return (s || '').toString().trim(); });
    var contacts = [];
    for (var i = 0; i < services.length; i++) {
      if (services[i] !== '' && (userIds[i] || '') !== '') {
        contacts.push({ service: services[i], user_id: userIds[i] });
      }
    }

    return {
      hostname: (fd.get('hostname') || '').toString().trim(),
      url: (fd.get('url') || '').toString().trim(),
      pluriverse_endpoint: (fd.get('pluriverse_endpoint') || '').toString().trim(),
      operator_email: (fd.get('operator_email') || '').toString().trim(),
      label: (fd.get('label') || '').toString().trim(),
      editorial_framing: (fd.get('editorial_framing') || '').toString().trim(),
      publishable_slugs: slugs,
      bridges: bridges,
      other_contacts: contacts,
      locale: (fd.get('locale') || 'en').toString()
    };
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    clearError();
    setBusy(true);

    var body;
    try {
      body = buildBody();
    } catch (e) {
      setBusy(false);
      showError(strings.error_generic);
      return;
    }

    fetch('/api/pluriverse/operators/apply', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'omit'
    })
      .then(function (resp) {
        return resp.json().then(function (json) {
          return { status: resp.status, body: json };
        });
      })
      .then(function (r) {
        setBusy(false);
        if (r.status === 201 && r.body && r.body.status === 'pending') {
          showSuccess(r.body);
          return;
        }
        var detail = (r.body && (r.body.detail || r.body.message)) || strings.error_generic;
        showError(detail);
      })
      .catch(function () {
        setBusy(false);
        showError(strings.error_generic);
      });
  });
})();
