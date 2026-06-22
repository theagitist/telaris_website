<?php
declare(strict_types=1);

/**
 * Pluriverse website — DB access layer.
 *
 * Mirrors /var/www/starmaps.polivoxia.ca/inc/db.php in shape and posture:
 *   - PDO + utf8mb4 + InnoDB.
 *   - Idempotent db_ensure_*() helpers run on first use and reconcile the
 *     schema forward — no install wizard, no SQL dumps. Migration IS
 *     the boot path.
 *   - One PDO instance per process; reset only for tests.
 *
 * The website schema is small at v1: project_info (one row per locale) and
 * content_cache (markdown render cache). Federation tables (peers,
 * key_events, registry_admins, etc.) land when federation implementation
 * moves the Pluriverse beyond a marketing surface.
 */

global $_PLURIVERSE_DB_OVERRIDE;
$_PLURIVERSE_DB_OVERRIDE = null;

function pluriverse_db_reset_for_testing(?PDO $override = null): void {
    global $_PLURIVERSE_DB_OVERRIDE;
    $_PLURIVERSE_DB_OVERRIDE = $override;
}

function getDB(): PDO {
    global $_PLURIVERSE_DB_OVERRIDE;
    if ($_PLURIVERSE_DB_OVERRIDE !== null) {
        return $_PLURIVERSE_DB_OVERRIDE;
    }
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $port = defined('DB_PORT') && DB_PORT !== '' ? DB_PORT : '5432';
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, $port, DB_NAME);
    if (defined('DB_SSL_CA') && DB_SSL_CA !== '') {
        $dsn .= ';sslmode=verify-ca;sslrootcert=' . DB_SSL_CA;
    }
    $pdo = new PDO(
        $dsn,
        DB_USER,
        defined('DB_PASS') ? DB_PASS : '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    // Postgres session defaults: UTC time zone (so TIMESTAMP reads/writes are
    // unambiguous) and a 30s statement timeout (matching the instance app).
    $pdo->exec("SET TIME ZONE 'UTC'");
    $pdo->exec('SET statement_timeout = 30000');
    return $pdo;
}

// ---------------------------------------------------------------------------
// project_info — chrome strings, one row per supported locale.
// ---------------------------------------------------------------------------

/** Locales the website serves. Add to PROJECT_INFO_DEFAULTS to expand. */
const PLURIVERSE_LOCALES = ['en', 'es', 'pt', 'fr'];

/** Chrome columns on project_info, in declaration order. */
const PROJECT_INFO_COLUMNS = [
    // Page metadata.
    'html_lang', 'title_suffix', 'weaving', 'tagline_desc',
    // Navbar (6 items including the external Source code link).
    'nav_home', 'nav_documentation', 'nav_instances', 'nav_manifest', 'nav_changelog', 'nav_source_code',
    // Footer chrome.
    'footer_status', 'footer_privacy', 'footer_terms',
    // Documentation page.
    'doc_title', 'doc_lead', 'doc_download',
    // Instances page.
    'instance_title', 'instance_lead',
    // Changelog page (curated, visitor-facing highlights).
    'changelog_title', 'changelog_lead',
    // Manifest / Privacy / Terms pages.
    'manifest_title', 'manifest_lead', 'manifest_download_pdf',
    'privacy_title', 'privacy_lead', 'privacy_download_pdf',
    'terms_title', 'terms_lead', 'terms_download_pdf',
    // Operator magic-link verify page (2g-i, 2026-05-25).
    'verify_heading_verified', 'verify_body_verified',
    'verify_heading_already_verified', 'verify_body_already_verified',
    'verify_heading_expired', 'verify_body_expired',
    'verify_heading_already_used', 'verify_body_already_used',
    'verify_heading_invalid', 'verify_body_invalid',
    'verify_heading_missing', 'verify_body_missing',
    'verify_heading_instance_missing', 'verify_body_instance_missing',
    'verify_heading_not_eligible', 'verify_body_not_eligible',
    'verify_label_name', 'verify_label_hostname', 'verify_label_status',
    'verify_status_pending', 'verify_status_verified', 'verify_status_published',
    'verify_status_rejected', 'verify_status_blacklisted', 'verify_status_outdated',
    'verify_status_withdrawn', 'verify_status_revoked', 'verify_status_expired',
    'verify_back_home',
    // Operator dashboard (2h, 2026-05-25).
    'dashboard_title', 'dashboard_lead',
    'dashboard_section_summary',
    'dashboard_label_name', 'dashboard_label_url', 'dashboard_label_hostname',
    'dashboard_label_status', 'dashboard_label_locale', 'dashboard_label_created',
    'dashboard_section_contacts', 'dashboard_label_email',
    'dashboard_section_galaxies', 'dashboard_galaxies_none', 'dashboard_galaxies_count_fmt',
    'dashboard_galaxies_rescan_note',
    'dashboard_section_history', 'dashboard_history_none',
    'dashboard_login_title', 'dashboard_login_lead', 'dashboard_login_sent',
    'dashboard_login_email_label', 'dashboard_login_button',
    'dashboard_login_rate_limited', 'dashboard_login_invalid_email',
    'dashboard_logout_button',
    // Pluriverse admin (2i-i, 2026-05-25).
    'admin_title', 'admin_lead_signed_in_as_fmt',
    'admin_section_instances', 'admin_instances_none', 'admin_instances_total_fmt',
    'admin_col_name', 'admin_col_hostname', 'admin_col_status', 'admin_col_locale', 'admin_col_created',
    'admin_actions_pending',
    'admin_login_title', 'admin_login_lead', 'admin_login_sent',
    'admin_login_email_label', 'admin_login_button',
    'admin_login_rate_limited', 'admin_login_invalid_email',
    // 2i-ii admin actions (publish / reject / blacklist / unpublish / reinstate).
    'admin_col_actions', 'admin_actions_help',
    'admin_btn_publish', 'admin_btn_reject', 'admin_btn_blacklist', 'admin_btn_unpublish', 'admin_btn_reinstate',
    'admin_confirm_action_fmt',
    'admin_flash_transition_ok_fmt', 'admin_flash_csrf_err', 'admin_flash_transition_err',
    // 2m-i Dashboard withdraw action.
    'dashboard_section_withdraw', 'dashboard_withdraw_help',
    'dashboard_withdraw_button', 'dashboard_withdraw_confirm',
    'dashboard_withdraw_success', 'dashboard_withdraw_error',
    // 2m-ii Dashboard edit action.
    'dashboard_section_edit', 'dashboard_edit_help',
    'dashboard_edit_label_label', 'dashboard_edit_framing_label', 'dashboard_edit_framing_help',
    'dashboard_edit_locale_label', 'dashboard_edit_save_button',
    'dashboard_edit_success',
    'dashboard_edit_err_csrf', 'dashboard_edit_err_instance_missing',
    'dashboard_edit_err_label_invalid', 'dashboard_edit_err_framing_too_long',
    'dashboard_edit_err_locale_invalid', 'dashboard_edit_err_name_taken',
    'dashboard_edit_err_db',
    // 2o-i Dashboard other_contacts edit.
    'dashboard_other_contacts_heading', 'dashboard_other_contacts_help',
    'dashboard_contacts_service_placeholder', 'dashboard_contacts_user_id_placeholder',
    'dashboard_contacts_add_button', 'dashboard_contacts_remove_button',
    'dashboard_contacts_save_button', 'dashboard_contacts_success',
    'dashboard_contacts_err_csrf', 'dashboard_contacts_err_instance_missing',
    'dashboard_contacts_err_too_many', 'dashboard_contacts_err_service_invalid',
    'dashboard_contacts_err_user_id_invalid', 'dashboard_contacts_err_db',
    // 2o-ii Dashboard email-change flow.
    'dashboard_email_change_heading', 'dashboard_email_change_help',
    'dashboard_email_change_new_label', 'dashboard_email_change_new_placeholder',
    'dashboard_email_change_send_button', 'dashboard_email_change_sent',
    'dashboard_email_change_pending_help', 'dashboard_email_change_cancel_button',
    'dashboard_email_change_cancelled', 'dashboard_email_change_confirmed',
    'dashboard_email_change_err_csrf', 'dashboard_email_change_err_instance_missing',
    'dashboard_email_change_err_email_invalid', 'dashboard_email_change_err_email_unchanged',
    'dashboard_email_change_err_email_taken', 'dashboard_email_change_err_db',
    // 2l Contact + Governance static pages.
    'footer_contact', 'footer_governance',
    'contact_title', 'contact_lead',
    'contact_section_repos_title', 'contact_section_repos_body',
    'contact_section_admin_title', 'contact_section_admin_body',
    'contact_section_security_title', 'contact_section_security_body',
    'governance_title', 'governance_lead',
    'governance_section_admission_title', 'governance_section_admission_body',
    'governance_section_lifecycle_title', 'governance_section_lifecycle_body',
    'governance_section_fork_title', 'governance_section_fork_body',
    // Self-service instance request (Phase 3c/3d, 2026-06-22).
    'footer_request',
    'instance_request_cta_title', 'instance_request_cta_body', 'instance_request_cta_button',
    'request_title', 'request_lead', 'request_closed_notice',
    'request_name_label', 'request_name_ph',
    'request_email_label', 'request_email_ph',
    'request_label_label', 'request_label_help',
    'request_sitename_label', 'request_sitename_ph',
    'request_tagline_label', 'request_tagline_ph',
    'request_locale_label',
    'request_framing_label', 'request_framing_help',
    'request_federate_label', 'request_federate_help',
    'request_consent_html', 'request_submit',
    'request_success_heading', 'request_success_body',
    'request_err_email', 'request_err_label_invalid', 'request_err_label_taken',
    'request_err_sitename', 'request_err_consent', 'request_err_rate_limited', 'request_err_generic',
    'request_confirm_heading_ok', 'request_confirm_body_ok',
    'request_confirm_heading_already', 'request_confirm_body_already',
    'request_confirm_heading_expired', 'request_confirm_body_expired',
    'request_confirm_heading_invalid', 'request_confirm_body_invalid',
    // Admin self-service review (Phase 3e, 2026-06-22).
    'admin_ss_title',
    'admin_ss_settings_title', 'admin_ss_open_label', 'admin_ss_cap_label',
    'admin_ss_save_button', 'admin_ss_settings_saved',
    'admin_ss_requests_title', 'admin_ss_requests_none',
    'admin_ss_col_operator', 'admin_ss_col_label', 'admin_ss_col_status', 'admin_ss_col_created',
    'admin_ss_btn_approve', 'admin_ss_btn_reject', 'admin_ss_btn_ban', 'admin_ss_btn_unban',
    'admin_ss_confirm_fmt',
    'admin_ss_flash_approved', 'admin_ss_flash_rejected', 'admin_ss_flash_banned',
    'admin_ss_flash_unbanned', 'admin_ss_flash_err',
    'admin_ss_status_pending_confirmation', 'admin_ss_status_confirmed', 'admin_ss_status_approved',
    'admin_ss_status_provisioning', 'admin_ss_status_provisioned',
    'admin_ss_status_rejected', 'admin_ss_status_failed', 'admin_ss_status_banned',
];

function db_ensure_project_info(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $pdo = getDB();
    // TEXT for chrome columns: lead paragraphs are ~300 chars and TEXT is
    // unbounded with no in-row penalty in Postgres.
    $cols = [];
    foreach (PROJECT_INFO_COLUMNS as $c) {
        // NOT NULL columns added to a table with rows need a default; the
        // ALTER path below adds with '' so existing rows stay valid.
        $cols[] = "$c TEXT NOT NULL DEFAULT ''";
    }
    $colSql = implode(",\n            ", $cols);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_info (
            locale VARCHAR(8) NOT NULL PRIMARY KEY,
            {$colSql},
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    // Reconcile any added columns.
    $existing = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'project_info'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach (PROJECT_INFO_COLUMNS as $c) {
        if (!in_array($c, $existing, true)) {
            $pdo->exec("ALTER TABLE project_info ADD COLUMN $c TEXT NOT NULL DEFAULT ''");
        }
    }
    // BEFORE UPDATE trigger replaces MySQL's ON UPDATE CURRENT_TIMESTAMP.
    db_ensure_pg_runtime();
    db_pg_ensure_updated_at_trigger('project_info');
    db_seed_project_info();
}

/**
 * Insert default rows for any locale missing from the table. INSERT IGNORE
 * keeps this idempotent; existing rows are never overwritten (operators
 * may edit their content directly in the DB).
 */
function db_seed_project_info(): void {
    $pdo = getDB();
    $defaults = pluriverse_project_info_defaults();
    $columns = array_merge(['locale'], PROJECT_INFO_COLUMNS);
    $placeholders = ':' . implode(', :', $columns);
    $colList = implode(', ', $columns);
    // ON CONFLICT (locale) DO NOTHING: idempotent seed; existing rows
    // (operator-customized) are never overwritten.
    $stmt = $pdo->prepare("INSERT INTO project_info ({$colList}) VALUES ({$placeholders}) ON CONFLICT (locale) DO NOTHING");
    foreach (PLURIVERSE_LOCALES as $locale) {
        if (!isset($defaults[$locale])) continue;
        $row = ['locale' => $locale];
        foreach (PROJECT_INFO_COLUMNS as $c) {
            $row[$c] = (string)($defaults[$locale][$c] ?? '');
        }
        $params = [];
        foreach ($row as $k => $v) $params[':' . $k] = $v;
        $stmt->execute($params);
    }
    // Backfill: when PROJECT_INFO_COLUMNS grows in a release, ALTER TABLE
    // adds the new columns with empty implicit defaults on existing rows.
    // INSERT IGNORE above won't update them. This second pass fills any
    // empty cell with the canonical default so newly added chrome strings
    // become visible without an operator edit. Existing non-empty cells
    // (operator-customized) are preserved.
    foreach (PROJECT_INFO_COLUMNS as $c) {
        // Whitelisted from the const; safe to inline as an identifier.
        $upd = $pdo->prepare(
            "UPDATE project_info SET $c = :v WHERE locale = :l AND ($c IS NULL OR $c = '')"
        );
        foreach (PLURIVERSE_LOCALES as $locale) {
            $val = (string)($defaults[$locale][$c] ?? '');
            if ($val === '') continue;
            $upd->execute([':v' => $val, ':l' => $locale]);
        }
    }
}

function db_get_project_info_for_locale(string $locale): array {
    db_ensure_project_info();
    $stmt = getDB()->prepare("SELECT * FROM project_info WHERE locale = :l LIMIT 1");
    $stmt->execute([':l' => $locale]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

// ---------------------------------------------------------------------------
// content_cache — markdown rendering cache, keyed by (slug, locale, mtime).
// ---------------------------------------------------------------------------
//
// Sources are flat files on disk under PLURIVERSE_DOCS_SRC; their mtime is
// the cache-busting key. A stale row (mtime mismatch) is silently ignored
// and a fresh render replaces it.

function db_ensure_content_cache(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $pdo = getDB();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS content_cache (
            slug VARCHAR(64) NOT NULL,
            locale VARCHAR(8) NOT NULL,
            source_mtime BIGINT NOT NULL,
            rendered_html TEXT NOT NULL,
            rendered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (slug, locale)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rendered_at ON content_cache (rendered_at)");
}

function db_content_cache_get(string $slug, string $locale, int $sourceMtime): ?string {
    db_ensure_content_cache();
    $stmt = getDB()->prepare("
        SELECT rendered_html FROM content_cache
        WHERE slug = :s AND locale = :l AND source_mtime = :m
        LIMIT 1
    ");
    $stmt->execute([':s' => $slug, ':l' => $locale, ':m' => $sourceMtime]);
    $row = $stmt->fetch();
    return is_array($row) ? (string)$row['rendered_html'] : null;
}

function db_content_cache_put(string $slug, string $locale, int $sourceMtime, string $html): void {
    db_ensure_content_cache();
    $stmt = getDB()->prepare("
        INSERT INTO content_cache (slug, locale, source_mtime, rendered_html)
        VALUES (:s, :l, :m, :h)
        ON CONFLICT (slug, locale) DO UPDATE SET source_mtime = EXCLUDED.source_mtime, rendered_html = EXCLUDED.rendered_html, rendered_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([':s' => $slug, ':l' => $locale, ':m' => $sourceMtime, ':h' => $html]);
}

// ---------------------------------------------------------------------------
// Postgres runtime prerequisites.
//
// MySQL gave us ON UPDATE CURRENT_TIMESTAMP for free; Postgres has no such
// column clause, so every table with an updated_at column gets a BEFORE UPDATE
// trigger that stamps NOW() into it. The trigger function is shared.
//
// Postgres also does not auto-index foreign-key columns the way MySQL's InnoDB
// effectively did; the per-table ensure helpers add CREATE INDEX IF NOT EXISTS
// for FK columns that aren't already a leading index column.
// ---------------------------------------------------------------------------

/**
 * Create the shared set_updated_at() trigger function. Idempotent via CREATE
 * OR REPLACE. The $$-quoted body must run as its own exec() (never through a
 * ";"-splitting layer).
 */
function db_ensure_pg_runtime(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    getDB()->exec(
        "CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$\n"
        . "BEGIN\n"
        . "    NEW.updated_at := NOW();\n"
        . "    RETURN NEW;\n"
        . "END;\n"
        . "$$ LANGUAGE plpgsql"
    );
}

/**
 * Attach a BEFORE UPDATE trigger to $table that stamps updated_at = NOW().
 * Drops-then-creates so it stays idempotent (Postgres has no CREATE TRIGGER
 * IF NOT EXISTS before PG 14, and DROP ... IF EXISTS is universally safe).
 * The caller must have run db_ensure_pg_runtime() first.
 */
function db_pg_ensure_updated_at_trigger(string $table): void {
    // $table is always a hard-coded literal from these source files; never
    // user input. Guard anyway.
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
        throw new InvalidArgumentException('db_pg_ensure_updated_at_trigger: bad table name');
    }
    $trg = 'trg_' . $table . '_updated_at';
    $pdo = getDB();
    $pdo->exec("DROP TRIGGER IF EXISTS {$trg} ON {$table}");
    $pdo->exec(
        "CREATE TRIGGER {$trg} BEFORE UPDATE ON {$table} "
        . "FOR EACH ROW EXECUTE FUNCTION set_updated_at()"
    );
}

require_once __DIR__ . '/db_defaults.php';
require_once __DIR__ . '/db_federation.php';
