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
 * operators_verify.php).
 *
 * Rate limit: 5 login email requests per IP per hour. APCu-backed.
 *
 * State-changing actions (logout shipped 2g-ii; edit + withdraw to follow)
 * arrive as POST /dashboard with an `action` field plus a CSRF token in
 * `csrf`. CSRF is checked via pluriverse_csrf_verify before any state
 * mutates.
 */

require_once __DIR__ . '/../db_federation.php';
require_once __DIR__ . '/../federation/session.php';
require_once __DIR__ . '/../federation/csrf.php';
require_once __DIR__ . '/../federation/pii.php';

global $pluriverseLocale, $pluriverseInfo, $pluriversePrefix;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$session = pluriverse_current_session();

// -----------------------------------------------------------------------
// POST action=logout: process before any rendering so we can redirect
// cleanly. CSRF protects against off-site forms forcing a logout.
// -----------------------------------------------------------------------
if ($method === 'POST'
    && (string)($_POST['action'] ?? '') === 'logout'
    && $session !== null
) {
    if (pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        db_destroy_session($session['session_id']);
        pluriverse_session_clear_cookie();
        pluriverse_current_session_invalidate();
    }
    // Always redirect back to the dashboard, whether the CSRF check
    // succeeded (logged out, login form) or failed (still logged in or
    // already logged out). Avoids exposing a different surface on attempt.
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    header('Location: ' . $localePrefix . '/dashboard');
    http_response_code(303);
    return;
}

// -----------------------------------------------------------------------
// POST action=request_email_change: operator requests a new email. Validates,
// encrypts the new email into pending_email_enc, stores pending_email_*
// columns, mints a purpose='email-change' magic-link token tied to the new
// email's lookup hash, sends the link to the NEW mailbox.
// -----------------------------------------------------------------------
if ($method === 'POST'
    && (string)($_POST['action'] ?? '') === 'request_email_change'
    && $session !== null
    && $session['subject_type'] === 'operator'
) {
    $emailChangeErrorKey = '';
    if (!pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        $emailChangeErrorKey = 'csrf';
    } else {
        $instance = db_get_instance_by_id($session['subject_id']);
        if ($instance === null) {
            $emailChangeErrorKey = 'instance_missing';
        } else {
            $newEmail = trim((string)($_POST['new_email'] ?? ''));
            if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 254) {
                $emailChangeErrorKey = 'email_invalid';
            } else {
                $newLookupHash = federation_pii_lookup_hash($newEmail);
                if (hash_equals((string)$instance['operator_email_lookup_hash'], $newLookupHash)) {
                    $emailChangeErrorKey = 'email_unchanged';
                } else {
                    // Reject if the new email collides with another instance's
                    // canonical operator_email_lookup_hash or another instance's
                    // pending_email_lookup_hash. Self-pending is fine (operator
                    // is replacing their own prior pending change).
                    $pdo = getDB();
                    $conflict = $pdo->prepare("
                        SELECT id FROM instances
                        WHERE id <> :self AND (
                            operator_email_lookup_hash = :h
                            OR pending_email_lookup_hash = :h
                        ) LIMIT 1
                    ");
                    $conflict->bindValue(':self', (int)$instance['id'], PDO::PARAM_INT);
                    $conflict->bindValue(':h', $newLookupHash, PDO::PARAM_LOB);
                    $conflict->execute();
                    if ($conflict->fetchColumn() !== false) {
                        $emailChangeErrorKey = 'email_taken';
                    } else {
                        try {
                            $oldContext = federation_row_context_for_instance((string)$instance['operator_email_lookup_hash']);
                            $pendingEnc = federation_pii_encrypt($newEmail, $oldContext, 'pending_email');
                            $pdo->beginTransaction();
                            $upd = $pdo->prepare("
                                UPDATE instances
                                SET pending_email_enc = :enc,
                                    pending_email_lookup_hash = :hash,
                                    pending_email_requested_at = NOW()
                                WHERE id = :id
                            ");
                            $upd->bindValue(':enc', $pendingEnc, PDO::PARAM_LOB);
                            $upd->bindValue(':hash', $newLookupHash, PDO::PARAM_LOB);
                            $upd->bindValue(':id', (int)$instance['id'], PDO::PARAM_INT);
                            $upd->execute();
                            $log = $pdo->prepare("
                                INSERT INTO instance_status_log (instance_id, actor, action, details_summary)
                                VALUES (:id, 'operator', 'email_change_requested', 'operator requested email change via dashboard; confirmation pending in new mailbox')
                            ");
                            $log->execute([':id' => (int)$instance['id']]);
                            $tokenRaw = db_create_magic_link_token($newLookupHash, 86400, 'email-change', (int)$instance['id']);
                            $pdo->commit();

                            $tokenUrl = 'https://www.telaris.ca/operators/verify-magic-link?t=' . federation_token_url_encode($tokenRaw);
                            $emailLocale = in_array((string)$instance['locale'], ['en', 'es', 'pt', 'fr'], true) ? (string)$instance['locale'] : 'en';
                            $instanceLabel = (string)$instance['label'];
                            $emailBodies = [
                                'en' => [
                                    'subject' => 'Confirm your new Pluriverse email',
                                    'heading' => 'Confirm your new Pluriverse email',
                                    'paragraphs' => [
                                        "Someone signed in to the Pluriverse dashboard for the instance \"{$instanceLabel}\" and requested to change the operator email to this address. Use the button below within 24 hours to confirm.",
                                    ],
                                    'cta_label' => 'Confirm email',
                                    'note' => 'If you did not request this change, you can safely ignore this email; the change will not take effect without a click.',
                                ],
                                'es' => [
                                    'subject' => 'Confirma tu nuevo correo de la Pluriverse',
                                    'heading' => 'Confirma tu nuevo correo de la Pluriverse',
                                    'paragraphs' => [
                                        "Alguien inició sesión en el panel de la Pluriverse de la instancia \"{$instanceLabel}\" y solicitó cambiar el correo de operación a esta dirección. Usa el botón de abajo dentro de las próximas 24 horas para confirmar.",
                                    ],
                                    'cta_label' => 'Confirmar correo',
                                    'note' => 'Si no solicitaste este cambio, puedes ignorar este correo; el cambio no se aplicará sin un clic.',
                                ],
                                'pt' => [
                                    'subject' => 'Confirme seu novo email da Pluriverse',
                                    'heading' => 'Confirme seu novo email da Pluriverse',
                                    'paragraphs' => [
                                        "Alguém entrou no painel da Pluriverse da instância \"{$instanceLabel}\" e solicitou mudar o email de operação para este endereço. Use o botão abaixo nas próximas 24 horas para confirmar.",
                                    ],
                                    'cta_label' => 'Confirmar email',
                                    'note' => 'Se você não solicitou esta mudança, pode ignorar este email; a mudança não terá efeito sem um clique.',
                                ],
                                'fr' => [
                                    'subject' => 'Confirme ton nouveau courriel Pluriverse',
                                    'heading' => 'Confirme ton nouveau courriel Pluriverse',
                                    'paragraphs' => [
                                        "Quelqu'un s'est connecté au tableau de bord Pluriverse de l'instance \"{$instanceLabel}\" et a demandé à changer le courriel d'opération pour cette adresse. Utilise le bouton ci-dessous dans les 24 heures qui viennent pour confirmer.",
                                    ],
                                    'cta_label' => 'Confirmer le courriel',
                                    'note' => "Si tu n'as pas demandé ce changement, tu peux ignorer ce courriel ; le changement ne prendra pas effet sans un clic.",
                                ],
                            ];
                            $tpl = $emailBodies[$emailLocale];
                            require_once __DIR__ . '/../mail.php';
                            require_once __DIR__ . '/../email-template.php';
                            $rendered = pluriverse_email_render([
                                'heading' => $tpl['heading'],
                                'paragraphs' => $tpl['paragraphs'],
                                'cta' => ['label' => $tpl['cta_label'], 'url' => $tokenUrl],
                                'note' => $tpl['note'],
                                'locale' => $emailLocale,
                            ]);
                            pluriverse_send_mail($newEmail, $tpl['subject'], $rendered['text'], $rendered['html']);
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            error_log('dashboard request_email_change: ' . $e->getMessage());
                            $emailChangeErrorKey = 'db_error';
                        }
                    }
                }
            }
        }
    }
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    $qs = $emailChangeErrorKey === '' ? '?email_change_requested=1' : '?email_change_error=' . urlencode($emailChangeErrorKey);
    header('Location: ' . $localePrefix . '/dashboard' . $qs);
    http_response_code(303);
    return;
}

// -----------------------------------------------------------------------
// POST action=cancel_email_change: operator cancels a pending email change.
// Clears the pending_* columns. Doesn't invalidate the token (it will just
// fail to find a row on consume); the operator can also let it 24h-expire.
// -----------------------------------------------------------------------
if ($method === 'POST'
    && (string)($_POST['action'] ?? '') === 'cancel_email_change'
    && $session !== null
    && $session['subject_type'] === 'operator'
) {
    if (pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        $instance = db_get_instance_by_id($session['subject_id']);
        if ($instance !== null && !empty($instance['pending_email_lookup_hash'])) {
            $pdo = getDB();
            try {
                $pdo->beginTransaction();
                $upd = $pdo->prepare("
                    UPDATE instances
                    SET pending_email_enc = NULL,
                        pending_email_lookup_hash = NULL,
                        pending_email_requested_at = NULL
                    WHERE id = :id
                ");
                $upd->execute([':id' => (int)$instance['id']]);
                $log = $pdo->prepare("
                    INSERT INTO instance_status_log (instance_id, actor, action, details_summary)
                    VALUES (:id, 'operator', 'email_change_cancelled', 'operator cancelled the pending email change via dashboard')
                ");
                $log->execute([':id' => (int)$instance['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('dashboard cancel_email_change: ' . $e->getMessage());
            }
        }
    }
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    header('Location: ' . $localePrefix . '/dashboard?email_change_cancelled=1');
    http_response_code(303);
    return;
}

// -----------------------------------------------------------------------
// POST action=edit_contacts: operator edits other_contacts (the array of
// {service, user_id} entries on the encrypted side). No identity ceremony
// — service handles are pure editorial. Re-encrypt with the existing
// per-row PII key; UPDATE + status_log INSERT in one transaction.
// -----------------------------------------------------------------------
if ($method === 'POST'
    && (string)($_POST['action'] ?? '') === 'edit_contacts'
    && $session !== null
    && $session['subject_type'] === 'operator'
) {
    $contactsErrorKey = '';
    if (!pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        $contactsErrorKey = 'csrf';
    } else {
        $instance = db_get_instance_by_id($session['subject_id']);
        if ($instance === null) {
            $contactsErrorKey = 'instance_missing';
        } else {
            $rawContacts = $_POST['contacts'] ?? [];
            if (!is_array($rawContacts)) $rawContacts = [];
            $normalized = [];
            foreach ($rawContacts as $entry) {
                if (!is_array($entry)) continue;
                $service = trim((string)($entry['service'] ?? ''));
                $userId = trim((string)($entry['user_id'] ?? ''));
                // Skip wholly-empty rows (operator added a row then left it
                // blank); validation kicks in for any partially-filled row.
                if ($service === '' && $userId === '') continue;
                $normalized[] = ['service' => $service, 'user_id' => $userId];
            }
            if (count($normalized) > 8) {
                $contactsErrorKey = 'too_many';
            } else {
                foreach ($normalized as $i => $entry) {
                    if ($entry['service'] === '' || mb_strlen($entry['service']) > 64) {
                        $contactsErrorKey = 'service_invalid';
                        break;
                    }
                    if ($entry['user_id'] === '' || mb_strlen($entry['user_id']) > 256) {
                        $contactsErrorKey = 'user_id_invalid';
                        break;
                    }
                }
            }
            if ($contactsErrorKey === '') {
                $pdo = getDB();
                try {
                    $rowContext = federation_row_context_for_instance((string)$instance['operator_email_lookup_hash']);
                    $newEnc = federation_pii_encrypt(
                        json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        $rowContext,
                        'other_contacts'
                    );
                    $pdo->beginTransaction();
                    $upd = $pdo->prepare("
                        UPDATE instances
                        SET other_contacts_enc = :enc
                        WHERE id = :id
                    ");
                    $upd->bindValue(':enc', $newEnc, PDO::PARAM_LOB);
                    $upd->bindValue(':id', (int)$instance['id'], PDO::PARAM_INT);
                    $upd->execute();
                    $log = $pdo->prepare("
                        INSERT INTO instance_status_log (instance_id, actor, action, details_summary)
                        VALUES (:id, 'operator', 'edit', :summary)
                    ");
                    $log->execute([
                        ':id' => (int)$instance['id'],
                        ':summary' => 'operator edited other_contacts via dashboard: ' . count($normalized) . ' entr' . (count($normalized) === 1 ? 'y' : 'ies'),
                    ]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('dashboard edit_contacts: ' . $e->getMessage());
                    $contactsErrorKey = 'db_error';
                }
            }
        }
    }
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    $qs = $contactsErrorKey === '' ? '?contacts_edited=1' : '?contacts_error=' . urlencode($contactsErrorKey);
    header('Location: ' . $localePrefix . '/dashboard' . $qs);
    http_response_code(303);
    return;
}

// -----------------------------------------------------------------------
// POST action=edit: operator edits label / editorial_framing / locale.
// Non-PII fields only; email changes require a re-verify flow (deferred).
// Validates inputs, UPDATEs atomically, logs to instance_status_log.
// -----------------------------------------------------------------------
if ($method === 'POST'
    && (string)($_POST['action'] ?? '') === 'edit'
    && $session !== null
    && $session['subject_type'] === 'operator'
) {
    $editErrorKey = '';
    if (!pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        $editErrorKey = 'csrf';
    } else {
        $instance = db_get_instance_by_id($session['subject_id']);
        if ($instance === null) {
            $editErrorKey = 'instance_missing';
        } else {
            $newLabel = trim((string)($_POST['label'] ?? ''));
            $newFraming = trim((string)($_POST['editorial_framing'] ?? ''));
            $newLocale = (string)($_POST['locale'] ?? '');
            $curLabel = (string)$instance['label'];
            $curFraming = (string)($instance['editorial_framing'] ?? '');
            $curLocale = (string)$instance['locale'];

            if ($newLabel === '' || mb_strlen($newLabel) > 255) {
                $editErrorKey = 'label_invalid';
            } elseif (mb_strlen($newFraming) > 2000) {
                $editErrorKey = 'framing_too_long';
            } elseif (!in_array($newLocale, ['en', 'es', 'pt', 'fr'], true)) {
                $editErrorKey = 'locale_invalid';
            } elseif (strcasecmp($newLabel, $curLabel) !== 0 && !db_label_available($newLabel)) {
                $editErrorKey = 'name_taken';
            } else {
                $unchanged = ($newLabel === $curLabel)
                    && ($newFraming === $curFraming)
                    && ($newLocale === $curLocale);
                if (!$unchanged) {
                    $pdo = getDB();
                    try {
                        $pdo->beginTransaction();
                        $upd = $pdo->prepare("
                            UPDATE instances
                            SET label = :label,
                                editorial_framing = :framing,
                                locale = :locale
                            WHERE id = :id
                        ");
                        $upd->execute([
                            ':label' => $newLabel,
                            ':framing' => $newFraming,
                            ':locale' => $newLocale,
                            ':id' => (int)$instance['id'],
                        ]);
                        $changed = [];
                        if ($newLabel !== $curLabel) $changed[] = 'label';
                        if ($newFraming !== $curFraming) $changed[] = 'editorial_framing';
                        if ($newLocale !== $curLocale) $changed[] = 'locale';
                        $log = $pdo->prepare("
                            INSERT INTO instance_status_log (instance_id, actor, action, details_summary)
                            VALUES (:id, 'operator', 'edit', :summary)
                        ");
                        $log->execute([
                            ':id' => (int)$instance['id'],
                            ':summary' => 'operator edited via dashboard: ' . implode(', ', $changed),
                        ]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('dashboard edit: ' . $e->getMessage());
                        $editErrorKey = 'db_error';
                    }
                }
            }
        }
    }
    // Redirect to a URL whose locale prefix matches the operator's saved
    // locale (which may have just changed). If the edit failed early
    // (CSRF, missing instance), fall back to the URL-derived locale so
    // we don't try to read a locale that wasn't validated.
    $redirectLocale = ($editErrorKey === '' && isset($newLocale) && in_array($newLocale, ['en', 'es', 'pt', 'fr'], true))
        ? $newLocale
        : $pluriverseLocale;
    $localePrefix = ($redirectLocale !== 'en') ? '/' . $redirectLocale : '';
    $qs = $editErrorKey === '' ? '?edited=1' : '?edit_error=' . urlencode($editErrorKey);
    header('Location: ' . $localePrefix . '/dashboard' . $qs);
    http_response_code(303);
    return;
}

// -----------------------------------------------------------------------
// POST action=withdraw: operator-initiated withdrawal. Transitions the
// instance to 'withdrawn' (one atomic step), logs it, destroys the session.
// Reversible: re-applying from the instance admin panel will drop the
// withdrawn row inline (same-operator semantics, like 'expired').
// -----------------------------------------------------------------------
if ($method === 'POST'
    && (string)($_POST['action'] ?? '') === 'withdraw'
    && $session !== null
    && $session['subject_type'] === 'operator'
) {
    $withdrawOk = false;
    $withdrawTried = false;
    if (pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        $instance = db_get_instance_by_id($session['subject_id']);
        if ($instance !== null) {
            $withdrawableFrom = ['pending', 'verified', 'published', 'outdated'];
            $current = (string)$instance['admission_status'];
            if (in_array($current, $withdrawableFrom, true)) {
                $withdrawTried = true;
                $withdrawOk = db_transition_instance_admission(
                    (int)$instance['id'],
                    $current,
                    'withdrawn',
                    'operator',
                    'operator withdrew via dashboard'
                );
            }
        }
        if ($withdrawOk) {
            db_destroy_session($session['session_id']);
            pluriverse_session_clear_cookie();
            pluriverse_current_session_invalidate();
        }
    }
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    $qs = $withdrawOk ? '?withdrawn=1' : ($withdrawTried ? '?withdraw_error=1' : '');
    header('Location: ' . $localePrefix . '/dashboard' . $qs);
    http_response_code(303);
    return;
}

// Re-read session after a possible logout above (the static cache was
// invalidated). $session and the rest of the handler need the fresh state.
$session = pluriverse_current_session();

// -----------------------------------------------------------------------
// 2p: POST action=choose_instance from the instance chooser. Binds the
// operator-chooser session to the picked instance (which must belong to
// the session's chooser_email_hash). CSRF-protected.
// -----------------------------------------------------------------------
if ($method === 'POST'
    && (string)($_POST['action'] ?? '') === 'choose_instance'
    && $session !== null
    && $session['subject_type'] === 'operator-chooser'
) {
    if (pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        $chosenId = (int)($_POST['instance_id'] ?? 0);
        $emailHash = (string)($session['chooser_email_hash'] ?? '');
        $eligible = $emailHash !== '' ? db_get_instances_by_email_lookup_hash($emailHash) : [];
        $ok = false;
        foreach ($eligible as $inst) {
            if ((int)$inst['id'] === $chosenId) { $ok = true; break; }
        }
        if ($ok && db_bind_chooser_session($session['session_id'], $chosenId)) {
            pluriverse_current_session_invalidate();
        }
    }
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    header('Location: ' . $localePrefix . '/dashboard');
    http_response_code(303);
    return;
}

// -----------------------------------------------------------------------
// 2p: render the instance chooser for an operator-chooser session. Lists
// every instance the verified email operates; picking one binds the
// session and reloads into that instance's dashboard.
// -----------------------------------------------------------------------
if ($session !== null && $session['subject_type'] === 'operator-chooser') {
    $emailHash = (string)($session['chooser_email_hash'] ?? '');
    $chooserInstances = $emailHash !== '' ? db_get_instances_by_email_lookup_hash($emailHash) : [];
    if (count($chooserInstances) <= 1) {
        // Degenerate: 0 or 1 instance (state changed since the link was
        // minted). Bind to the single one if present, else drop the
        // chooser session back to the login form.
        if (count($chooserInstances) === 1) {
            db_bind_chooser_session($session['session_id'], (int)$chooserInstances[0]['id']);
        } else {
            db_destroy_session($session['session_id']);
            pluriverse_session_clear_cookie();
        }
        pluriverse_current_session_invalidate();
        $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
        header('Location: ' . $localePrefix . '/dashboard');
        http_response_code(303);
        return;
    }
    $pageTitle = info('dashboard_chooser_title');
    $bodyClass = 'page-dashboard page-dashboard-chooser';
    require __DIR__ . '/../partials/head.php';
    ?>
<main class="page page-dashboard-chooser">
  <h1 class="page-title"><?= h(info('dashboard_chooser_title')) ?></h1>
  <p class="page-lead"><?= h(info('dashboard_chooser_lead')) ?></p>
  <ul class="dashboard-chooser-list">
<?php foreach ($chooserInstances as $inst): ?>
    <li class="dashboard-chooser-item">
      <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>" class="dashboard-chooser-form">
        <?= pluriverse_csrf_field() ?>
        <input type="hidden" name="action" value="choose_instance">
        <input type="hidden" name="instance_id" value="<?= (int)$inst['id'] ?>">
        <button type="submit" class="dashboard-chooser-button">
          <span class="dashboard-chooser-label"><?= h((string)$inst['label']) ?></span>
          <span class="dashboard-chooser-host"><code><?= h((string)$inst['hostname']) ?></code></span>
          <span class="dashboard-chooser-status"><?= h(info('verify_status_' . $inst['admission_status'])) ?></span>
        </button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
  <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>" class="dashboard-logout-form">
    <?= pluriverse_csrf_field() ?>
    <input type="hidden" name="action" value="logout">
    <button type="submit"><?= h(info('dashboard_chooser_signout')) ?></button>
  </form>
</main>
<?php
    require __DIR__ . '/../partials/footer.php';
    return;
}

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

            <?php
            $emailChangeRequested = isset($_GET['email_change_requested']);
            $emailChangeCancelled = isset($_GET['email_change_cancelled']);
            $emailChangeErrorKey = isset($_GET['email_change_error']) ? (string)$_GET['email_change_error'] : '';
            $emailChangeErrorMap = [
                'csrf' => 'dashboard_email_change_err_csrf',
                'instance_missing' => 'dashboard_email_change_err_instance_missing',
                'email_invalid' => 'dashboard_email_change_err_email_invalid',
                'email_unchanged' => 'dashboard_email_change_err_email_unchanged',
                'email_taken' => 'dashboard_email_change_err_email_taken',
                'db_error' => 'dashboard_email_change_err_db',
            ];
            $hasPendingEmail = !empty($instance['pending_email_lookup_hash']);
            ?>
            <h3 class="dashboard-section-h3"><?= h(info('dashboard_email_change_heading')) ?></h3>
            <?php if (isset($_GET['email_changed'])): ?>
              <div class="dashboard-callout dashboard-callout-ok">
                <p><?= h(info('dashboard_email_change_confirmed')) ?></p>
              </div>
            <?php endif; ?>
            <?php if ($emailChangeRequested): ?>
              <div class="dashboard-callout dashboard-callout-ok">
                <p><?= h(info('dashboard_email_change_sent')) ?></p>
              </div>
            <?php endif; ?>
            <?php if ($emailChangeCancelled): ?>
              <div class="dashboard-callout dashboard-callout-ok">
                <p><?= h(info('dashboard_email_change_cancelled')) ?></p>
              </div>
            <?php endif; ?>
            <?php if ($emailChangeErrorKey !== '' && isset($emailChangeErrorMap[$emailChangeErrorKey])): ?>
              <div class="dashboard-callout dashboard-callout-error">
                <p><?= h(info($emailChangeErrorMap[$emailChangeErrorKey])) ?></p>
              </div>
            <?php endif; ?>
            <?php if ($hasPendingEmail): ?>
              <p class="dashboard-help"><?= h(info('dashboard_email_change_pending_help')) ?>
                <?php if (!empty($instance['pending_email_requested_at'])): ?>
                  <time datetime="<?= h((string)$instance['pending_email_requested_at']) ?>"><?= h((string)$instance['pending_email_requested_at']) ?></time>
                <?php endif; ?>
              </p>
              <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>" class="dashboard-email-cancel-form">
                <?= pluriverse_csrf_field() ?>
                <input type="hidden" name="action" value="cancel_email_change">
                <button type="submit"><?= h(info('dashboard_email_change_cancel_button')) ?></button>
              </form>
            <?php else: ?>
              <p class="dashboard-help"><?= h(info('dashboard_email_change_help')) ?></p>
              <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>" class="dashboard-email-change-form">
                <?= pluriverse_csrf_field() ?>
                <input type="hidden" name="action" value="request_email_change">
                <label for="dashboard-new-email"><?= h(info('dashboard_email_change_new_label')) ?></label>
                <input type="email" id="dashboard-new-email" name="new_email" required maxlength="254"
                       autocomplete="email" inputmode="email"
                       placeholder="<?= h(info('dashboard_email_change_new_placeholder')) ?>">
                <button type="submit"><?= h(info('dashboard_email_change_send_button')) ?></button>
              </form>
            <?php endif; ?>

            <?php
            $contactsEdited = isset($_GET['contacts_edited']);
            $contactsErrorKey = isset($_GET['contacts_error']) ? (string)$_GET['contacts_error'] : '';
            $contactsErrorMap = [
                'csrf' => 'dashboard_contacts_err_csrf',
                'instance_missing' => 'dashboard_contacts_err_instance_missing',
                'too_many' => 'dashboard_contacts_err_too_many',
                'service_invalid' => 'dashboard_contacts_err_service_invalid',
                'user_id_invalid' => 'dashboard_contacts_err_user_id_invalid',
                'db_error' => 'dashboard_contacts_err_db',
            ];
            ?>
            <h3 class="dashboard-section-h3"><?= h(info('dashboard_other_contacts_heading')) ?></h3>
            <p class="dashboard-help"><?= h(info('dashboard_other_contacts_help')) ?></p>
            <?php if ($contactsEdited): ?>
              <div class="dashboard-callout dashboard-callout-ok">
                <p><?= h(info('dashboard_contacts_success')) ?></p>
              </div>
            <?php endif; ?>
            <?php if ($contactsErrorKey !== '' && isset($contactsErrorMap[$contactsErrorKey])): ?>
              <div class="dashboard-callout dashboard-callout-error">
                <p><?= h(info($contactsErrorMap[$contactsErrorKey])) ?></p>
              </div>
            <?php endif; ?>
            <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>"
                  class="dashboard-contacts-form"
                  data-add-label="<?= h(info('dashboard_contacts_add_button')) ?>"
                  data-remove-label="<?= h(info('dashboard_contacts_remove_button')) ?>"
                  data-service-placeholder="<?= h(info('dashboard_contacts_service_placeholder')) ?>"
                  data-user-id-placeholder="<?= h(info('dashboard_contacts_user_id_placeholder')) ?>">
              <?= pluriverse_csrf_field() ?>
              <input type="hidden" name="action" value="edit_contacts">
              <div class="dashboard-contacts-rows">
                <?php $contactIdx = 0; foreach ($otherContacts as $c):
                    if (!is_array($c) || !isset($c['service'], $c['user_id'])) continue; ?>
                  <div class="dashboard-contacts-row">
                    <input type="text" name="contacts[<?= $contactIdx ?>][service]"
                           value="<?= h((string)$c['service']) ?>" maxlength="64"
                           placeholder="<?= h(info('dashboard_contacts_service_placeholder')) ?>">
                    <input type="text" name="contacts[<?= $contactIdx ?>][user_id]"
                           value="<?= h((string)$c['user_id']) ?>" maxlength="256"
                           placeholder="<?= h(info('dashboard_contacts_user_id_placeholder')) ?>">
                    <button type="button" class="dashboard-contacts-remove"><?= h(info('dashboard_contacts_remove_button')) ?></button>
                  </div>
                  <?php $contactIdx++; endforeach; ?>
              </div>
              <button type="button" class="dashboard-contacts-add"><?= h(info('dashboard_contacts_add_button')) ?></button>
              <button type="submit"><?= h(info('dashboard_contacts_save_button')) ?></button>
            </form>
            <script>
            (function () {
                var forms = document.querySelectorAll('form.dashboard-contacts-form');
                forms.forEach(function (form) {
                    var rows = form.querySelector('.dashboard-contacts-rows');
                    var addBtn = form.querySelector('.dashboard-contacts-add');
                    var addLabel = form.dataset.addLabel;
                    var removeLabel = form.dataset.removeLabel;
                    var servicePh = form.dataset.servicePlaceholder;
                    var userIdPh = form.dataset.userIdPlaceholder;
                    var MAX = 8;
                    function attachRemove(row) {
                        var btn = row.querySelector('.dashboard-contacts-remove');
                        if (btn) btn.addEventListener('click', function () { row.remove(); });
                    }
                    rows.querySelectorAll('.dashboard-contacts-row').forEach(attachRemove);
                    addBtn.addEventListener('click', function () {
                        if (rows.children.length >= MAX) return;
                        var i = rows.children.length;
                        var row = document.createElement('div');
                        row.className = 'dashboard-contacts-row';
                        var s = document.createElement('input');
                        s.type = 'text';
                        s.name = 'contacts[' + i + '][service]';
                        s.maxLength = 64;
                        s.placeholder = servicePh;
                        var u = document.createElement('input');
                        u.type = 'text';
                        u.name = 'contacts[' + i + '][user_id]';
                        u.maxLength = 256;
                        u.placeholder = userIdPh;
                        var r = document.createElement('button');
                        r.type = 'button';
                        r.className = 'dashboard-contacts-remove';
                        r.textContent = removeLabel;
                        row.appendChild(s);
                        row.appendChild(u);
                        row.appendChild(r);
                        rows.appendChild(row);
                        attachRemove(row);
                    });
                });
            })();
            </script>
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

          <?php
          // Edit-flash from the POST action=edit redirect.
          $edited = isset($_GET['edited']);
          $editErrorKey = isset($_GET['edit_error']) ? (string)$_GET['edit_error'] : '';
          $editErrorMap = [
              'csrf' => 'dashboard_edit_err_csrf',
              'instance_missing' => 'dashboard_edit_err_instance_missing',
              'label_invalid' => 'dashboard_edit_err_label_invalid',
              'framing_too_long' => 'dashboard_edit_err_framing_too_long',
              'locale_invalid' => 'dashboard_edit_err_locale_invalid',
              'name_taken' => 'dashboard_edit_err_name_taken',
              'db_error' => 'dashboard_edit_err_db',
          ];
          ?>
          <section class="dashboard-section dashboard-section-edit">
            <h2><?= h(info('dashboard_section_edit')) ?></h2>
            <p class="dashboard-help"><?= h(info('dashboard_edit_help')) ?></p>
            <?php if ($edited): ?>
              <div class="dashboard-callout dashboard-callout-ok">
                <p><?= h(info('dashboard_edit_success')) ?></p>
              </div>
            <?php endif; ?>
            <?php if ($editErrorKey !== '' && isset($editErrorMap[$editErrorKey])): ?>
              <div class="dashboard-callout dashboard-callout-error">
                <p><?= h(info($editErrorMap[$editErrorKey])) ?></p>
              </div>
            <?php endif; ?>
            <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>" class="dashboard-edit-form">
              <?= pluriverse_csrf_field() ?>
              <input type="hidden" name="action" value="edit">

              <label for="dashboard-edit-label"><?= h(info('dashboard_edit_label_label')) ?></label>
              <input type="text" id="dashboard-edit-label" name="label" required maxlength="255"
                     value="<?= h((string)$instance['label']) ?>">

              <label for="dashboard-edit-framing"><?= h(info('dashboard_edit_framing_label')) ?></label>
              <textarea id="dashboard-edit-framing" name="editorial_framing" rows="4" maxlength="2000"><?= h((string)($instance['editorial_framing'] ?? '')) ?></textarea>
              <p class="dashboard-help dashboard-help-quiet"><?= h(info('dashboard_edit_framing_help')) ?></p>

              <label for="dashboard-edit-locale"><?= h(info('dashboard_edit_locale_label')) ?></label>
              <select id="dashboard-edit-locale" name="locale">
                <?php foreach (['en' => 'English', 'es' => 'Español', 'pt' => 'Português', 'fr' => 'Français'] as $code => $name): ?>
                  <option value="<?= h($code) ?>"<?= ((string)$instance['locale'] === $code) ? ' selected' : '' ?>><?= h($name) ?></option>
                <?php endforeach; ?>
              </select>

              <button type="submit"><?= h(info('dashboard_edit_save_button')) ?></button>
            </form>
          </section>

          <?php if (in_array((string)$instance['admission_status'], ['pending', 'verified', 'published', 'outdated'], true)): ?>
          <section class="dashboard-section dashboard-section-withdraw">
            <h2><?= h(info('dashboard_section_withdraw')) ?></h2>
            <p class="dashboard-help"><?= h(info('dashboard_withdraw_help')) ?></p>
            <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>" class="dashboard-withdraw-form" onsubmit="return confirm(<?= h(json_encode(info('dashboard_withdraw_confirm'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>);">
              <?= pluriverse_csrf_field() ?>
              <input type="hidden" name="action" value="withdraw">
              <button type="submit" class="dashboard-btn-destructive"><?= h(info('dashboard_withdraw_button')) ?></button>
            </form>
          </section>
          <?php endif; ?>

          <form method="post" action="<?= h($pluriversePrefix . '/dashboard') ?>" class="dashboard-logout-form">
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
                // 2p: an email may operate several instances. A single match
                // mints an instance-scoped link (lands directly); multiple
                // matches mint an operator-scoped link (NULL instance_id) that
                // lands on the chooser.
                $instances = db_get_instances_by_email_lookup_hash($lookupHash);
                if (count($instances) >= 1) {
                    $single = count($instances) === 1;
                    $boundInstanceId = $single ? (int)$instances[0]['id'] : null;
                    $tokenRaw = db_create_magic_link_token($lookupHash, 86400, 'operator', $boundInstanceId);
                    $tokenUrl = 'https://www.telaris.ca/operators/verify-magic-link?t=' . federation_token_url_encode($tokenRaw);

                    // Locale: the single instance's, or the first match's.
                    $emailLocale = (string)$instances[0]['locale'];
                    if (!in_array($emailLocale, ['en', 'es', 'pt', 'fr'], true)) $emailLocale = 'en';
                    $label = (string)$instances[0]['label'];

                    $singleBodies = [
                        'en' => [
                            'subject' => 'Sign in to your Pluriverse dashboard',
                            'heading' => 'Sign in to your Pluriverse dashboard',
                            'paragraphs' => [
                                "Use the button below to sign in to your Pluriverse dashboard for the instance \"{$label}\". The link is single-use and expires in 24 hours.",
                            ],
                            'cta_label' => 'Sign in',
                            'note' => 'If you did not request a sign-in, you can safely ignore this email.',
                        ],
                        'es' => [
                            'subject' => 'Inicia sesión en tu panel de la Pluriverse',
                            'heading' => 'Inicia sesión en tu panel de la Pluriverse',
                            'paragraphs' => [
                                "Usa el botón de abajo para iniciar sesión en tu panel de la Pluriverse para la instancia \"{$label}\". El enlace es de un solo uso y caduca en 24 horas.",
                            ],
                            'cta_label' => 'Iniciar sesión',
                            'note' => 'Si no solicitaste iniciar sesión, puedes ignorar este correo.',
                        ],
                        'pt' => [
                            'subject' => 'Entrar no seu painel da Pluriverse',
                            'heading' => 'Entrar no seu painel da Pluriverse',
                            'paragraphs' => [
                                "Use o botão abaixo para entrar no seu painel da Pluriverse para a instância \"{$label}\". O link é de uso único e expira em 24 horas.",
                            ],
                            'cta_label' => 'Entrar',
                            'note' => 'Se você não solicitou entrar, pode ignorar este email.',
                        ],
                        'fr' => [
                            'subject' => 'Connecte-toi à ton tableau de bord Pluriverse',
                            'heading' => 'Connecte-toi à ton tableau de bord Pluriverse',
                            'paragraphs' => [
                                "Utilise le bouton ci-dessous pour te connecter à ton tableau de bord Pluriverse pour l'instance \"{$label}\". Le lien est à usage unique et expire dans 24 heures.",
                            ],
                            'cta_label' => 'Se connecter',
                            'note' => "Si tu n'as pas demandé à te connecter, tu peux ignorer ce courriel.",
                        ],
                    ];
                    $multiBodies = [
                        'en' => [
                            'subject' => 'Sign in to your Pluriverse dashboard',
                            'heading' => 'Sign in to your Pluriverse dashboard',
                            'paragraphs' => [
                                "Use the button below to sign in to your Pluriverse dashboard. You operate more than one instance under this email, so you'll choose which one to manage after signing in. The link is single-use and expires in 24 hours.",
                            ],
                            'cta_label' => 'Sign in',
                            'note' => 'If you did not request a sign-in, you can safely ignore this email.',
                        ],
                        'es' => [
                            'subject' => 'Inicia sesión en tu panel de la Pluriverse',
                            'heading' => 'Inicia sesión en tu panel de la Pluriverse',
                            'paragraphs' => [
                                "Usa el botón de abajo para iniciar sesión en tu panel de la Pluriverse. Operas más de una instancia con este correo, así que elegirás cuál gestionar después de iniciar sesión. El enlace es de un solo uso y caduca en 24 horas.",
                            ],
                            'cta_label' => 'Iniciar sesión',
                            'note' => 'Si no solicitaste iniciar sesión, puedes ignorar este correo.',
                        ],
                        'pt' => [
                            'subject' => 'Entrar no seu painel da Pluriverse',
                            'heading' => 'Entrar no seu painel da Pluriverse',
                            'paragraphs' => [
                                "Use o botão abaixo para entrar no seu painel da Pluriverse. Você opera mais de uma instância com este email, então vai escolher qual gerenciar depois de entrar. O link é de uso único e expira em 24 horas.",
                            ],
                            'cta_label' => 'Entrar',
                            'note' => 'Se você não solicitou entrar, pode ignorar este email.',
                        ],
                        'fr' => [
                            'subject' => 'Connecte-toi à ton tableau de bord Pluriverse',
                            'heading' => 'Connecte-toi à ton tableau de bord Pluriverse',
                            'paragraphs' => [
                                "Utilise le bouton ci-dessous pour te connecter à ton tableau de bord Pluriverse. Tu gères plus d'une instance sous ce courriel ; tu choisiras laquelle administrer après la connexion. Le lien est à usage unique et expire dans 24 heures.",
                            ],
                            'cta_label' => 'Se connecter',
                            'note' => "Si tu n'as pas demandé à te connecter, tu peux ignorer ce courriel.",
                        ],
                    ];
                    $tpl = $single ? $singleBodies[$emailLocale] : $multiBodies[$emailLocale];
                    require_once __DIR__ . '/../mail.php';
                    require_once __DIR__ . '/../email-template.php';
                    $rendered = pluriverse_email_render([
                        'heading' => $tpl['heading'],
                        'paragraphs' => $tpl['paragraphs'],
                        'cta' => ['label' => $tpl['cta_label'], 'url' => $tokenUrl],
                        'note' => $tpl['note'],
                        'locale' => $emailLocale,
                    ]);
                    pluriverse_send_mail($emailInput, $tpl['subject'], $rendered['text'], $rendered['html']);
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

<?php if (isset($_GET['withdrawn'])): ?>
  <div class="dashboard-callout dashboard-callout-ok">
    <p><?= h(info('dashboard_withdraw_success')) ?></p>
  </div>
<?php endif; ?>
<?php if (isset($_GET['withdraw_error'])): ?>
  <div class="dashboard-callout dashboard-callout-error">
    <p><?= h(info('dashboard_withdraw_error')) ?></p>
  </div>
<?php endif; ?>

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
