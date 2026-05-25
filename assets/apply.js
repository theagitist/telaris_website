/* Operator-application form behaviour.
 *
 * Four responsibilities:
 *   1. Debounced async availability check on the Name field (300 ms
 *      after typing stops; aborts in-flight when a newer value lands).
 *   2. Load-galaxies button: POSTs the instance URL to
 *      /api/pluriverse/operators/list-galaxies, then renders the result
 *      as a checkable list with all entries checked by default. Falls
 *      back to the manual-entry textarea on failure.
 *   3. Repeatable contact rows (up to eight, with remove).
 *   4. Submission: builds the JSON body, POSTs to
 *      /api/pluriverse/operators/apply, swaps to the success panel on
 *      201 or surfaces the Problem Details detail on error.
 *
 * No dependencies. Plain vanilla browser JS.
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

  var nameInput = document.getElementById('apply-name');
  var nameStatus = document.getElementById('apply-name-status');

  var loadButton = document.getElementById('galaxies-load');
  var checkAllButton = document.getElementById('galaxies-check-all');
  var uncheckAllButton = document.getElementById('galaxies-uncheck-all');
  var galaxiesList = document.getElementById('galaxies-list');
  var urlInput = document.getElementById('apply-url');

  var contactsList = document.getElementById('contacts-rows');
  var contactRowTemplate = document.getElementById('contact-row-template');
  var contactsAddButton = document.getElementById('contacts-add');

  var MAX_CONTACTS = 8;

  // ---------------------------------------------------------------------
  // Name availability (debounced).
  // ---------------------------------------------------------------------
  var nameDebounceTimer = null;
  var nameInFlight = null;
  var lastCheckedName = null;

  function setNameStatus(state, text) {
    if (!nameStatus) return;
    nameStatus.dataset.state = state || '';
    nameStatus.textContent = text || '';
  }

  function checkNameNow() {
    var name = nameInput.value.trim();
    if (name === '') { setNameStatus('', ''); return; }
    if (name === lastCheckedName) return;
    lastCheckedName = name;

    if (nameInFlight && typeof nameInFlight.abort === 'function') {
      try { nameInFlight.abort(); } catch (e) { /* ignore */ }
    }
    setNameStatus('checking', strings.name_checking || '...');
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    nameInFlight = ctrl;

    fetch('/api/pluriverse/operators/check-name?n=' + encodeURIComponent(name), {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'omit',
      signal: ctrl ? ctrl.signal : undefined
    })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (name !== nameInput.value.trim()) return; // stale
        if (json && json.available === true) {
          setNameStatus('ok', strings.name_available || 'Available');
        } else if (json && json.reason === 'invalid') {
          setNameStatus('warn', strings.name_invalid || 'Invalid');
        } else {
          setNameStatus('bad', strings.name_taken || 'Already taken');
        }
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        setNameStatus('', '');
      });
  }

  if (nameInput) {
    nameInput.addEventListener('input', function () {
      lastCheckedName = null;
      setNameStatus('checking', strings.name_checking || '...');
      if (nameDebounceTimer) clearTimeout(nameDebounceTimer);
      nameDebounceTimer = setTimeout(checkNameNow, 300);
    });
    nameInput.addEventListener('blur', function () {
      if (nameDebounceTimer) { clearTimeout(nameDebounceTimer); nameDebounceTimer = null; }
      checkNameNow();
    });
  }

  // ---------------------------------------------------------------------
  // Galaxies loader.
  // ---------------------------------------------------------------------
  function setLoadBusy(busy) {
    loadButton.disabled = busy;
    var idle = loadButton.querySelector('.label-idle');
    var busyLabel = loadButton.querySelector('.label-busy');
    if (idle) idle.hidden = busy;
    if (busyLabel) busyLabel.hidden = !busy;
  }
  function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }
  function renderGalaxies(galaxies) {
    if (!galaxies || galaxies.length === 0) {
      galaxiesList.innerHTML = '<p class="form-help">' + escapeHtml(strings.galaxies_empty || '') + '</p>';
      galaxiesList.hidden = false;
      checkAllButton.hidden = true;
      uncheckAllButton.hidden = true;
      return;
    }
    var html = '<ul class="galaxies-checks">';
    for (var i = 0; i < galaxies.length; i++) {
      var g = galaxies[i];
      html += '<li><label class="form-check">'
        + '<input type="checkbox" name="loaded_slug" value="' + escapeHtml(g.slug) + '" checked>'
        + '<span><code>' + escapeHtml(g.slug) + '</code>'
        + (g.name && g.name !== g.slug ? ' <span class="galaxies-name">' + escapeHtml(g.name) + '</span>' : '')
        + '</span></label></li>';
    }
    html += '</ul>';
    galaxiesList.innerHTML = html;
    galaxiesList.hidden = false;
    checkAllButton.hidden = false;
    uncheckAllButton.hidden = false;
  }
  function loadGalaxies() {
    var url = (urlInput.value || '').trim();
    if (!/^https:\/\//.test(url)) {
      showError(strings.galaxies_load_failed || 'Enter the instance URL first.');
      return;
    }
    setLoadBusy(true);
    fetch('/api/pluriverse/operators/list-galaxies', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ url: url }),
      credentials: 'omit'
    })
      .then(function (resp) {
        return resp.json().then(function (json) { return { status: resp.status, body: json }; });
      })
      .then(function (r) {
        setLoadBusy(false);
        if (r.status === 200 && r.body && Array.isArray(r.body.galaxies)) {
          renderGalaxies(r.body.galaxies);
          return;
        }
        var detail = (r.body && r.body.detail) || strings.galaxies_load_failed;
        galaxiesList.hidden = true;
        showError(detail);
      })
      .catch(function () {
        setLoadBusy(false);
        showError(strings.galaxies_load_failed || strings.error_generic);
      });
  }
  if (loadButton) loadButton.addEventListener('click', loadGalaxies);
  if (checkAllButton) checkAllButton.addEventListener('click', function () {
    var boxes = galaxiesList.querySelectorAll('input[type=checkbox]');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = true;
  });
  if (uncheckAllButton) uncheckAllButton.addEventListener('click', function () {
    var boxes = galaxiesList.querySelectorAll('input[type=checkbox]');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = false;
  });

  // ---------------------------------------------------------------------
  // Contacts rows.
  // ---------------------------------------------------------------------
  function addContactRow() {
    if (contactsList.children.length >= MAX_CONTACTS) return;
    var node = contactRowTemplate.content.firstElementChild.cloneNode(true);
    var removeBtn = node.querySelector('.contact-remove');
    removeBtn.addEventListener('click', function () { contactsList.removeChild(node); });
    contactsList.appendChild(node);
  }
  if (contactsAddButton) contactsAddButton.addEventListener('click', addContactRow);

  // ---------------------------------------------------------------------
  // Submission.
  // ---------------------------------------------------------------------
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

  function collectSlugs() {
    var slugs = [];
    var seen = {};
    if (galaxiesList && !galaxiesList.hidden) {
      var boxes = galaxiesList.querySelectorAll('input[type=checkbox]:checked');
      for (var i = 0; i < boxes.length; i++) {
        var v = (boxes[i].value || '').trim();
        if (v !== '' && !seen[v]) { seen[v] = true; slugs.push(v); }
      }
    }
    return slugs;
  }

  function buildBody() {
    var fd = new FormData(form);

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
      // pluriverse_endpoint intentionally omitted; the backend derives
      // <url>/api/pluriverse/identity when absent. Operators with the
      // surface on a non-standard path can use the API endpoint
      // directly (e.g. curl) and pass it explicitly.
      operator_email: (fd.get('operator_email') || '').toString().trim(),
      label: (fd.get('label') || '').toString().trim(),
      editorial_framing: (fd.get('editorial_framing') || '').toString().trim(),
      publishable_slugs: collectSlugs(),
      other_contacts: contacts,
      locale: (fd.get('locale') || 'en').toString()
    };
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    clearError();

    var body;
    try {
      body = buildBody();
    } catch (e) {
      showError(strings.error_generic);
      return;
    }

    if (!body.publishable_slugs || body.publishable_slugs.length === 0) {
      showError(strings.galaxies_required || 'Pick at least one galaxy.');
      if (loadButton) loadButton.focus();
      return;
    }

    setBusy(true);

    fetch('/api/pluriverse/operators/apply', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'omit'
    })
      .then(function (resp) {
        return resp.json().then(function (json) { return { status: resp.status, body: json }; });
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
