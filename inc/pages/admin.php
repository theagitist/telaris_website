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
// Transition table for admin actions. Each entry maps current state to
// a map of (action -> new state). Anything not in the table is refused.
// -----------------------------------------------------------------------
$ADMIN_TRANSITIONS = [
    'verified'    => ['publish' => 'published', 'reject' => 'rejected',    'blacklist' => 'blacklisted'],
    'published'   => ['unpublish' => 'verified', 'blacklist' => 'blacklisted'],
    'outdated'    => ['publish' => 'published', 'blacklist' => 'blacklisted'],
    'rejected'    => ['reinstate' => 'verified'],
    'blacklisted' => ['reinstate' => 'verified'],
    'revoked'     => ['reinstate' => 'verified'],
];

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

// -----------------------------------------------------------------------
// POST transition action (publish / reject / blacklist / unpublish /
// reinstate). Validate CSRF, admin session, current state, transition.
// Redirect back with a flash code in ?msg= regardless of outcome.
// -----------------------------------------------------------------------
$transitionActions = ['publish', 'reject', 'blacklist', 'unpublish', 'reinstate'];
if ($method === 'POST'
    && in_array((string)($_POST['action'] ?? ''), $transitionActions, true)
    && $session !== null
    && $session['subject_type'] === 'admin'
) {
    $action = (string)$_POST['action'];
    $instanceId = (int)($_POST['instance_id'] ?? 0);
    $expected = (string)($_POST['expected_state'] ?? '');
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    $msg = 'transition_err';

    if (!pluriverse_csrf_verify($_POST['csrf'] ?? null)) {
        $msg = 'csrf_err';
    } elseif ($instanceId <= 0 || $expected === '' || !isset($ADMIN_TRANSITIONS[$expected][$action])) {
        $msg = 'transition_err';
    } else {
        $newStatus = $ADMIN_TRANSITIONS[$expected][$action];
        $admin = db_get_admin_by_id($session['subject_id']);
        if ($admin === null || !$admin['is_active']) {
            $msg = 'transition_err';
        } else {
            $ok = db_transition_instance_admission(
                $instanceId,
                $expected,
                $newStatus,
                'admin:' . (int)$admin['id'],
                'via /admin (' . $action . ')'
            );
            $msg = $ok ? ('transition_ok_' . $action) : 'transition_err';
        }
    }
    header('Location: ' . $localePrefix . '/admin?msg=' . urlencode($msg));
    http_response_code(303);
    return;
}

// -----------------------------------------------------------------------
// POST self-service review actions (Phase 3e): approve / reject / ban /
// unban a request, or save the self-service settings. CSRF + admin gated;
// PRG with a flash code.
// -----------------------------------------------------------------------
$ssActions = ['approve', 'reject_req', 'ban_op', 'unban_op', 'ss_settings', 'create_instance'];
if ($method === 'POST'
    && in_array((string)($_POST['action'] ?? ''), $ssActions, true)
    && $session !== null
    && $session['subject_type'] === 'admin'
) {
    $action = (string)$_POST['action'];
    $localePrefix = ($pluriverseLocale !== 'en') ? '/' . $pluriverseLocale : '';
    $msg = 'ss_err';
    $admin = db_get_admin_by_id($session['subject_id']);
    if (pluriverse_csrf_verify($_POST['csrf'] ?? null) && $admin !== null && $admin['is_active']) {
        $actor = 'admin:' . (int)$admin['id'];
        try {
            if ($action === 'ss_settings') {
                pluriverse_setting_set('self_service_open', isset($_POST['open']) ? '1' : '0');
                $cap = (int)($_POST['cap'] ?? 3);
                if ($cap >= 1 && $cap <= 999) {
                    pluriverse_setting_set('self_service_operator_cap', (string)$cap);
                }
                $msg = 'ss_settings_saved';
            } elseif ($action === 'create_instance') {
                // Admin-initiated: same fields as the public form but no email
                // confirmation and no cap/ban gate. Insert -> confirm -> approve
                // (which seals the handoff + enqueues the provision job).
                require_once __DIR__ . '/../federation/pii.php';
                $cEmail = trim((string)($_POST['email'] ?? ''));
                $cName  = trim((string)($_POST['name'] ?? ''));
                $cLabel = strtolower(trim((string)($_POST['label'] ?? '')));
                $cSite  = trim((string)($_POST['site_name'] ?? ''));
                $cFram  = trim((string)($_POST['framing'] ?? ''));
                $cLoc   = (string)($_POST['locale'] ?? 'en');
                if (!in_array($cLoc, ['en', 'es', 'pt', 'fr'], true)) $cLoc = 'en';
                $valid = $cEmail !== '' && filter_var($cEmail, FILTER_VALIDATE_EMAIL) && strlen($cEmail) <= 254
                    && $cName !== '' && $cSite !== '' && $cFram !== ''
                    && preg_match('/^[a-z][a-z0-9-]{1,30}$/', $cLabel)
                    && !str_contains($cLabel, '--') && !str_ends_with($cLabel, '-')
                    && !db_label_in_use($cLabel);
                if ($valid) {
                    $reqId = db_insert_instance_request([
                        'label' => $cLabel, 'site_name' => $cSite, 'site_tagline' => trim((string)($_POST['tagline'] ?? '')),
                        'editorial_framing' => $cFram, 'locale' => $cLoc,
                        'federate' => isset($_POST['federate']), 'operator_name' => $cName, 'operator_email' => $cEmail,
                    ]);
                    db_transition_instance_request($reqId, 'pending_confirmation', 'confirmed', $actor, ['confirmed_at' => true]);
                    $msg = (db_approve_instance_request($reqId, $actor) !== null) ? 'ss_created' : 'ss_err';
                } else {
                    $msg = 'ss_err';
                }
            } else {
                $reqId = (int)($_POST['request_id'] ?? 0);
                $req = $reqId > 0 ? db_get_instance_request_by_id($reqId) : null;
                if ($req !== null) {
                    $cur = (string)$req['status'];
                    if ($action === 'approve') {
                        $msg = (db_approve_instance_request($reqId, $actor) !== null) ? 'ss_approved' : 'ss_err';
                    } elseif ($action === 'reject_req') {
                        $ok = in_array($cur, ['confirmed', 'pending_confirmation', 'failed'], true)
                            && db_transition_instance_request($reqId, $cur, 'rejected', $actor, ['reason' => 'rejected via admin']);
                        $msg = $ok ? 'ss_rejected' : 'ss_err';
                    } elseif ($action === 'ban_op') {
                        $lh = hex2bin((string)$req['operator_email_lookup_hash']);
                        db_add_operator_ban($lh, 'banned via admin', $actor);
                        if ($cur !== 'banned') {
                            db_transition_instance_request($reqId, $cur, 'banned', $actor, ['reason' => 'operator banned']);
                        }
                        $msg = 'ss_banned';
                    } elseif ($action === 'unban_op') {
                        $lh = hex2bin((string)$req['operator_email_lookup_hash']);
                        $msg = db_remove_operator_ban($lh, $actor) ? 'ss_unbanned' : 'ss_err';
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('admin self-service action: ' . $e->getMessage());
            $msg = 'ss_err';
        }
    }
    header('Location: ' . $localePrefix . '/admin?msg=' . urlencode($msg));
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
                array_position(
                    ARRAY['verified','pending','published','outdated','withdrawn','expired','rejected','blacklisted','revoked']::text[],
                    admission_status
                ),
                created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Bucket counts for the summary line.
        $counts = [];
        foreach ($rows as $r) {
            $s = (string)$r['admission_status'];
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }

        $flashMsg = (string)($_GET['msg'] ?? '');
        $flashOk = strpos($flashMsg, 'transition_ok_') === 0;
        $flashErrKey = ($flashMsg === 'csrf_err' || $flashMsg === 'transition_err') ? $flashMsg : '';
        $flashOkAction = $flashOk ? substr($flashMsg, strlen('transition_ok_')) : '';

        // Self-service review data + flash (Phase 3e). Two lists: the review
        // queue (pre-decision) and the Orrery instances (post-approval
        // lifecycle), so an approved/provisioned request leaves the queue.
        $ssOpen = db_self_service_is_open();
        $ssCap = db_self_service_operator_cap();
        $ssReview = db_list_requests_by_status(['confirmed', 'pending_confirmation']);
        $ssInstances = db_list_requests_by_status(['approved', 'provisioning', 'provisioned', 'failed', 'banned', 'rejected']);
        $ssAll = array_merge($ssReview, $ssInstances); // for the detail modals
        $ssFlashMap = [
            'ss_approved' => ['ok', 'admin_ss_flash_approved'],
            'ss_rejected' => ['ok', 'admin_ss_flash_rejected'],
            'ss_banned' => ['ok', 'admin_ss_flash_banned'],
            'ss_unbanned' => ['ok', 'admin_ss_flash_unbanned'],
            'ss_settings_saved' => ['ok', 'admin_ss_settings_saved'],
            'ss_created' => ['ok', 'admin_ss_flash_created'],
            'ss_err' => ['error', 'admin_ss_flash_err'],
        ];
        $ssFlash = $ssFlashMap[$flashMsg] ?? null;
        // Keep the operator on the tab they acted in after a PRG redirect.
        $activeTab = (strpos($flashMsg, 'ss_') === 0) ? 'orrery' : 'federated';

        $pageTitle = info('admin_title');
        $bodyClass = 'page-admin';
        $includeBg = false;
        $useDaisyui = true; // DaisyUI controls in the self-service section
        require __DIR__ . '/../partials/head.php';
        ?>
        <main class="page page-admin">
          <h1 class="page-title"><?= h(info('admin_title')) ?></h1>
          <p class="page-lead">
            <?= h(sprintf(info('admin_lead_signed_in_as_fmt'), (string)$admin['display_name'])) ?>
          </p>

<?php if ($flashOk): ?>
          <div class="dashboard-callout dashboard-callout-ok">
            <p><?= h(sprintf(info('admin_flash_transition_ok_fmt'), info('admin_btn_' . $flashOkAction))) ?></p>
          </div>
<?php elseif ($flashErrKey !== ''): ?>
          <div class="dashboard-callout dashboard-callout-error">
            <p><?= h(info('admin_flash_' . $flashErrKey)) ?></p>
          </div>
<?php endif; ?>

<?php if ($ssFlash !== null): ?>
          <div class="dashboard-callout dashboard-callout-<?= h($ssFlash[0]) ?>">
            <p><?= h(info($ssFlash[1])) ?></p>
          </div>
<?php endif; ?>

<?php
// Per-request action set + label/colour maps, shared by the list rows and the
// detail modals.
$ssBtnLabelKey = ['approve' => 'admin_ss_btn_approve', 'reject_req' => 'admin_ss_btn_reject', 'ban_op' => 'admin_ss_btn_ban', 'unban_op' => 'admin_ss_btn_unban'];
$ssBtnClass = ['approve' => 'btn-success', 'reject_req' => 'btn-outline btn-error', 'ban_op' => 'btn-error', 'unban_op' => 'btn-ghost'];
$ssActionsFor = static function (string $st): array {
    if ($st === 'confirmed') return ['approve', 'reject_req', 'ban_op'];
    if (in_array($st, ['pending_confirmation', 'failed'], true)) return ['reject_req', 'ban_op'];
    if ($st === 'banned') return ['unban_op'];
    return [];
};
$ssShortDate = static function (?string $ts): string {
    $t = $ts ? strtotime($ts) : false;
    return $t ? date('Y-m-d', $t) : '';
};
?>
          <div class="ss-daisy admin-tabs-wrap" data-theme="dark">
          <div role="tablist" class="tabs tabs-bordered admin-tabs">

            <input type="radio" name="admin_tabs" role="tab" class="tab" aria-label="<?= h(info('admin_tab_federated')) ?>"<?= $activeTab === 'federated' ? ' checked' : '' ?>>
            <div role="tabpanel" class="tab-content admin-tab-panel">
              <h2><?= h(info('admin_section_instances')) ?></h2>
<?php if ($rows === []): ?>
              <p class="dashboard-help"><?= h(info('admin_instances_none')) ?></p>
<?php else: ?>
              <p class="dashboard-help"><?= h(sprintf(info('admin_instances_total_fmt'), count($rows))) ?></p>
              <table class="admin-instances">
                <thead>
                  <tr>
                    <th><?= h(info('admin_col_name')) ?></th>
                    <th><?= h(info('admin_col_hostname')) ?></th>
                    <th><?= h(info('admin_col_status')) ?></th>
                    <th><?= h(info('admin_col_locale')) ?></th>
                    <th><?= h(info('admin_col_created')) ?></th>
                    <th class="admin-col-actions"><?= h(info('admin_col_actions')) ?></th>
                  </tr>
                </thead>
                <tbody>
<?php
// DaisyUI button variants for the federated-instance actions, so they match
// the Orrery review buttons (and the main app's look).
$fedBtnClass = ['publish' => 'btn-success', 'reject' => 'btn-outline btn-error', 'blacklist' => 'btn-error', 'unpublish' => 'btn-warning', 'reinstate' => 'btn-info'];
foreach ($rows as $r):
    $st = (string)$r['admission_status'];
    $actions = $ADMIN_TRANSITIONS[$st] ?? [];
?>
                  <tr class="admin-instance-row admin-instance-row-<?= h($st) ?>">
                    <td><?= h((string)$r['label']) ?></td>
                    <td><code><?= h((string)$r['hostname']) ?></code></td>
                    <td><?= h(info('verify_status_' . $st)) ?></td>
                    <td><?= h((string)$r['locale']) ?></td>
                    <td><time datetime="<?= h((string)$r['created_at']) ?>"><?= h($ssShortDate((string)$r['created_at'])) ?></time></td>
                    <td class="admin-row-actions">
<?php if ($actions === []): ?>
                      <span class="admin-no-actions">-</span>
<?php else: ?>
                      <form method="post" action="<?= h($pluriversePrefix . '/admin') ?>">
                        <?= pluriverse_csrf_field() ?>
                        <input type="hidden" name="instance_id" value="<?= h((string)$r['id']) ?>">
                        <input type="hidden" name="expected_state" value="<?= h($st) ?>">
<?php foreach ($actions as $actionKey => $newStatus): ?>
                        <button type="submit" name="action" value="<?= h($actionKey) ?>"
                                class="btn btn-xs <?= h($fedBtnClass[$actionKey] ?? '') ?>"
                                data-confirm="<?= h(sprintf(info('admin_confirm_action_fmt'), info('admin_btn_' . $actionKey), (string)$r['label'])) ?>">
                          <?= h(info('admin_btn_' . $actionKey)) ?>
                        </button>
<?php endforeach; ?>
                      </form>
<?php endif; ?>
                    </td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
<?php endif; ?>
            </div>

            <input type="radio" name="admin_tabs" role="tab" class="tab" aria-label="<?= h(info('admin_tab_orrery')) ?>"<?= $activeTab === 'orrery' ? ' checked' : '' ?>>
            <div role="tabpanel" class="tab-content admin-tab-panel">
              <h2><?= h(info('admin_ss_title')) ?></h2>

              <div class="ss-panel">
                <h3 class="ss-panel-title"><?= h(info('admin_ss_settings_title')) ?></h3>
                <form method="post" action="<?= h($pluriversePrefix . '/admin') ?>" class="ss-settings-form">
                  <?= pluriverse_csrf_field() ?>
                  <input type="hidden" name="action" value="ss_settings">
                  <div class="form-control ss-field">
                    <label class="label cursor-pointer">
                      <span class="label-text"><?= h(info('admin_ss_open_label')) ?></span>
                      <input type="checkbox" name="open" value="1" class="toggle toggle-success"<?= $ssOpen ? ' checked' : '' ?>>
                    </label>
                  </div>
                  <div class="form-control ss-field">
                    <label class="label">
                      <span class="label-text"><?= h(info('admin_ss_cap_label')) ?></span>
                      <input type="number" name="cap" min="1" max="999" value="<?= h((string)$ssCap) ?>" class="input input-bordered input-sm ss-cap">
                    </label>
                  </div>
                  <div class="ss-field">
                    <button type="submit" class="btn btn-primary btn-sm"><?= h(info('admin_ss_save_button')) ?></button>
                  </div>
                </form>
              </div>

              <details class="ss-panel ss-create-details">
                <summary class="ss-panel-title"><?= h(info('admin_ss_create_title')) ?></summary>
                <p class="ss-help"><?= h(info('admin_ss_create_help')) ?></p>
                <form method="post" action="<?= h($pluriversePrefix . '/admin') ?>" class="ss-settings-form">
                  <?= pluriverse_csrf_field() ?>
                  <input type="hidden" name="action" value="create_instance">
                  <div class="form-control ss-field">
                    <label class="label"><span class="label-text"><?= h(info('request_name_label')) ?></span></label>
                    <input type="text" name="name" required maxlength="255" class="input input-bordered input-sm">
                  </div>
                  <div class="form-control ss-field">
                    <label class="label"><span class="label-text"><?= h(info('request_email_label')) ?></span></label>
                    <input type="email" name="email" required maxlength="254" class="input input-bordered input-sm">
                  </div>
                  <div class="form-control ss-field">
                    <label class="label"><span class="label-text"><?= h(info('request_label_label')) ?></span></label>
                    <input type="text" name="label" required maxlength="31" pattern="[a-z][a-z0-9-]{1,30}" class="input input-bordered input-sm">
                  </div>
                  <div class="form-control ss-field">
                    <label class="label"><span class="label-text"><?= h(info('request_sitename_label')) ?></span></label>
                    <input type="text" name="site_name" required maxlength="255" class="input input-bordered input-sm">
                  </div>
                  <div class="form-control ss-field">
                    <label class="label"><span class="label-text"><?= h(info('request_tagline_label')) ?></span></label>
                    <input type="text" name="tagline" maxlength="512" class="input input-bordered input-sm">
                  </div>
                  <div class="form-control ss-field">
                    <label class="label"><span class="label-text"><?= h(info('request_locale_label')) ?></span></label>
                    <select name="locale" required class="select select-bordered select-sm">
<?php foreach (['en' => 'English', 'es' => 'Español', 'pt' => 'Português', 'fr' => 'Français'] as $code => $lname): ?>
                      <option value="<?= h($code) ?>"<?= $pluriverseLocale === $code ? ' selected' : '' ?>><?= h($lname) ?></option>
<?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-control ss-field">
                    <label class="label"><span class="label-text"><?= h(info('request_framing_label')) ?></span></label>
                    <textarea name="framing" rows="2" required maxlength="2000" class="textarea textarea-bordered"></textarea>
                  </div>
                  <div class="form-control ss-field">
                    <label class="ss-checkbox-row">
                      <input type="checkbox" name="federate" value="1" checked class="checkbox checkbox-sm">
                      <span><?= h(info('request_federate_label')) ?></span>
                    </label>
                  </div>
                  <div class="ss-field">
                    <button type="submit" class="btn btn-primary btn-sm"><?= h(info('admin_ss_create_button')) ?></button>
                  </div>
                </form>
              </details>

<?php
// Shared row+table renderer for the two Orrery lists (review queue + instances).
$ssRenderRows = static function (array $list) use ($ssShortDate): void { ?>
              <table class="admin-instances ss-requests">
                <thead>
                  <tr>
                    <th><?= h(info('admin_ss_col_operator')) ?></th>
                    <th><?= h(info('admin_ss_col_label')) ?></th>
                    <th><?= h(info('admin_ss_col_status')) ?></th>
                    <th><?= h(info('admin_ss_col_created')) ?></th>
                  </tr>
                </thead>
                <tbody>
<?php foreach ($list as $rq):
    $st = (string)$rq['status'];
    $opName = (string)($rq['operator_name'] ?? '');
    $opEmail = (string)($rq['operator_email'] ?? '');
?>
                  <tr class="admin-instance-row admin-ss-row-<?= h($st) ?> ss-clickable" data-modal="rq-modal-<?= h((string)$rq['id']) ?>" tabindex="0" role="button">
                    <td><?php if ($opName !== ''): ?><?= h($opName) ?><br><?php endif; ?><code><?= h($opEmail) ?></code></td>
                    <td><code><?= h((string)$rq['label']) ?>.telaris.ca</code></td>
                    <td><?= h(info('admin_ss_status_' . $st)) ?></td>
                    <td><time datetime="<?= h((string)$rq['created_at']) ?>"><?= h($ssShortDate((string)$rq['created_at'])) ?></time></td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
<?php };
?>
              <h3><?= h(info('admin_ss_requests_title')) ?></h3>
<?php if ($ssReview === []): ?>
              <p class="dashboard-help"><?= h(info('admin_ss_requests_none')) ?></p>
<?php else: $ssRenderRows($ssReview); endif; ?>

              <h3><?= h(info('admin_ss_instances_title')) ?></h3>
<?php if ($ssInstances === []): ?>
              <p class="dashboard-help"><?= h(info('admin_ss_instances_none')) ?></p>
<?php else: $ssRenderRows($ssInstances); endif; ?>

<?php foreach ($ssAll as $rq):
    $st = (string)$rq['status'];
    $rowActions = $ssActionsFor($st);
    $opName = (string)($rq['operator_name'] ?? '');
    $opEmail = (string)($rq['operator_email'] ?? '');
?>
              <dialog id="rq-modal-<?= h((string)$rq['id']) ?>" class="modal">
                <div class="modal-box ss-modal-box">
                  <h3 class="ss-modal-title"><?= h(info('admin_ss_modal_title')) ?></h3>
                  <dl class="ss-details">
                    <dt><?= h(info('request_name_label')) ?></dt><dd><?= $opName !== '' ? h($opName) : '-' ?></dd>
                    <dt><?= h(info('request_email_label')) ?></dt><dd><code><?= h($opEmail) ?></code></dd>
                    <dt><?= h(info('request_label_label')) ?></dt><dd><code><?= h((string)$rq['label']) ?>.telaris.ca</code></dd>
                    <dt><?= h(info('request_sitename_label')) ?></dt><dd><?= h((string)$rq['site_name']) ?></dd>
                    <dt><?= h(info('request_tagline_label')) ?></dt><dd><?= $rq['site_tagline'] !== null && $rq['site_tagline'] !== '' ? h((string)$rq['site_tagline']) : '-' ?></dd>
                    <dt><?= h(info('request_locale_label')) ?></dt><dd><?= h((string)$rq['locale']) ?></dd>
                    <dt><?= h(info('request_framing_label')) ?></dt><dd><?= $rq['editorial_framing'] !== null && $rq['editorial_framing'] !== '' ? h((string)$rq['editorial_framing']) : '-' ?></dd>
                    <dt><?= h(info('request_federate_label')) ?></dt><dd><?= ((bool)$rq['federate']) ? '&#10003;' : '&#10007;' ?></dd>
                    <dt><?= h(info('admin_ss_col_status')) ?></dt><dd><?= h(info('admin_ss_status_' . $st)) ?></dd>
                    <dt><?= h(info('admin_ss_col_created')) ?></dt><dd><time datetime="<?= h((string)$rq['created_at']) ?>"><?= h((string)$rq['created_at']) ?></time></dd>
                  </dl>
                  <div class="ss-modal-actions">
<?php if ($rowActions !== []): ?>
                    <form method="post" action="<?= h($pluriversePrefix . '/admin') ?>" class="ss-modal-action-form">
                      <?= pluriverse_csrf_field() ?>
                      <input type="hidden" name="request_id" value="<?= h((string)$rq['id']) ?>">
<?php foreach ($rowActions as $ak): ?>
                      <button type="submit" name="action" value="<?= h($ak) ?>"
                              class="btn btn-sm <?= h($ssBtnClass[$ak]) ?>"
                              data-confirm="<?= h(sprintf(info('admin_ss_confirm_fmt'), info($ssBtnLabelKey[$ak]), (string)$rq['label'])) ?>">
                        <?= h(info($ssBtnLabelKey[$ak])) ?>
                      </button>
<?php endforeach; ?>
                    </form>
<?php endif; ?>
                    <form method="dialog"><button class="btn btn-sm btn-ghost"><?= h(info('admin_ss_close')) ?></button></form>
                  </div>
                </div>
                <form method="dialog" class="modal-backdrop"><button aria-label="<?= h(info('admin_ss_close')) ?>">close</button></form>
              </dialog>
<?php endforeach; ?>
            </div>

          </div>
          </div>

          <p class="dashboard-footer-note"><?= h(info('admin_actions_help')) ?></p>

          <form method="post" action="<?= h($pluriversePrefix . '/admin') ?>" class="dashboard-logout-form">
            <?= pluriverse_csrf_field() ?>
            <input type="hidden" name="action" value="logout">
            <button type="submit"><?= h(info('dashboard_logout_button')) ?></button>
          </form>

          <script>
            (function () {
              var forms = document.querySelectorAll('main.page-admin form');
              forms.forEach(function (form) {
                form.addEventListener('submit', function (ev) {
                  var btn = ev.submitter;
                  if (!btn || !btn.dataset || !btn.dataset.confirm) return;
                  if (!window.confirm(btn.dataset.confirm)) {
                    ev.preventDefault();
                  }
                });
              });
              // Open a request's detail modal when its row is clicked / activated.
              document.querySelectorAll('tr.ss-clickable[data-modal]').forEach(function (row) {
                function open() {
                  var dlg = document.getElementById(row.getAttribute('data-modal'));
                  if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
                }
                row.addEventListener('click', open);
                row.addEventListener('keydown', function (ev) {
                  if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); open(); }
                });
              });
            })();
          </script>
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

                    // No per-admin locale preference yet; use the page locale
                    // (where the sign-in was requested).
                    $emailLocale = in_array($pluriverseLocale, ['en', 'es', 'pt', 'fr'], true) ? $pluriverseLocale : 'en';
                    $name = (string)$admin['display_name'];
                    $emailBodies = [
                        'en' => [
                            'subject' => 'Sign in to the Pluriverse admin',
                            'heading' => 'Sign in to the Pluriverse admin',
                            'paragraphs' => [
                                "Hello {$name},",
                                'Use the button below to sign in to the Pluriverse admin surface. The link is single-use and expires in 24 hours.',
                            ],
                            'cta_label' => 'Sign in',
                            'note' => 'If you did not request a sign-in, you can safely ignore this email.',
                        ],
                        'es' => [
                            'subject' => 'Inicia sesión en la administración de la Pluriverse',
                            'heading' => 'Inicia sesión en la administración de la Pluriverse',
                            'paragraphs' => [
                                "Hola {$name},",
                                'Usa el botón de abajo para iniciar sesión en el panel de administración de la Pluriverse. El enlace es de un solo uso y caduca en 24 horas.',
                            ],
                            'cta_label' => 'Iniciar sesión',
                            'note' => 'Si no solicitaste iniciar sesión, puedes ignorar este correo.',
                        ],
                        'pt' => [
                            'subject' => 'Entrar na administração da Pluriverse',
                            'heading' => 'Entrar na administração da Pluriverse',
                            'paragraphs' => [
                                "Olá {$name},",
                                'Use o botão abaixo para entrar no painel de administração da Pluriverse. O link é de uso único e expira em 24 horas.',
                            ],
                            'cta_label' => 'Entrar',
                            'note' => 'Se você não solicitou entrar, pode ignorar este email.',
                        ],
                        'fr' => [
                            'subject' => 'Connecte-toi à l\'administration de la Pluriverse',
                            'heading' => 'Connecte-toi à l\'administration de la Pluriverse',
                            'paragraphs' => [
                                "Bonjour {$name},",
                                "Utilise le bouton ci-dessous pour te connecter au panneau d'administration de la Pluriverse. Le lien est à usage unique et expire dans 24 heures.",
                            ],
                            'cta_label' => 'Se connecter',
                            'note' => "Si tu n'as pas demandé à te connecter, tu peux ignorer ce courriel.",
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
                    pluriverse_send_mail($emailInput, $tpl['subject'], $rendered['text'], $rendered['html']);
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
