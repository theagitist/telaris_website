<?php
declare(strict_types=1);

/**
 * Pluriverse federation schema (stage 2a) + seed helpers (stage 2f-ii onward).
 *
 * Twelve tables, ten idempotent db_ensure_* helpers (instance_status_log and
 * pluriverse_log each pair with a LIKE-copy archive table managed by the same
 * helper). All lazy at first call; reconcile forward without dumps.
 *
 * Spec: P2P federation plan v10 § Schema → Pluriverse-side (line 1144+).
 *
 * Foreign-key topology means several helpers chain into upstream ensures:
 *   - instance_status_log, anomaly_log, key_event_push_attempts → instances
 *   - key_event_push_attempts → key_events_signed
 *
 * 2f-ii adds first-admin seed helpers (db_registry_admins_count,
 * db_seed_registry_admin); these pull in inc/federation/pii.php for PII
 * encryption + lookup hashing.
 */

require_once __DIR__ . '/federation/pii.php';

/**
 * Materialize every federation table in one call. Invoked from
 * bin/setup-app.php and from CLI smoke; future federation endpoints can
 * also call this on first use to bring a fresh database to current state.
 */
function db_ensure_federation_schema(): void {
    db_ensure_instances_table();
    db_ensure_instance_status_log_tables();
    db_ensure_registry_admins_table();
    db_ensure_magic_link_tokens_table();
    db_ensure_sessions_table();
    db_ensure_blacklists_table();
    db_ensure_anomaly_log_table();
    db_ensure_key_events_signed_table();
    db_ensure_key_event_push_attempts_table();
    db_ensure_pluriverse_log_tables();
}

function db_ensure_instances_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        // Fresh-install shape. The two former fixed channels (telegram,
        // signal) collapsed into a single JSON-in-secretbox "other_contacts_enc"
        // column on 2026-05-24 so the application form can accept any
        // service-name / user-id pair without schema churn.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS instances (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                hostname VARCHAR(255) NOT NULL,
                url VARCHAR(512) NOT NULL,
                pluriverse_endpoint VARCHAR(512) NOT NULL,
                public_key VARBINARY(32) NOT NULL,
                previous_public_key VARBINARY(32) NULL,
                key_rotated_at TIMESTAMP NULL,
                rotation_reason ENUM('scheduled','operational','compromise') NULL,
                operator_email_enc VARBINARY(512) NOT NULL,
                operator_email_lookup_hash VARBINARY(32) NOT NULL,
                other_contacts_enc VARBINARY(2048) NULL,
                label VARCHAR(255) NOT NULL,
                editorial_framing TEXT NULL,
                publishable_slugs JSON NULL,
                bridges JSON NULL,
                is_highlighted BOOLEAN NOT NULL DEFAULT FALSE,
                admission_status ENUM('pending','verified','published','rejected','blacklisted','outdated','withdrawn','revoked') NOT NULL DEFAULT 'pending',
                verify_by_at TIMESTAMP NULL,
                last_seen_at TIMESTAMP NULL,
                health_status ENUM('up','degraded','down','unknown') NOT NULL DEFAULT 'unknown',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_hostname (hostname),
                UNIQUE KEY uniq_operator_email_lookup (operator_email_lookup_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Idempotent contact-column migration for installs that landed before
        // 2026-05-24. MySQL 8 lacks IF EXISTS / IF NOT EXISTS at the column
        // level, so probe INFORMATION_SCHEMA first.
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instances'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $present = array_flip(array_map('strval', $cols));
        if (isset($present['telegram_handle_enc'])) {
            $pdo->exec("ALTER TABLE instances DROP COLUMN telegram_handle_enc");
        }
        if (isset($present['signal_contact_enc'])) {
            $pdo->exec("ALTER TABLE instances DROP COLUMN signal_contact_enc");
        }
        if (!isset($present['other_contacts_enc'])) {
            $pdo->exec("ALTER TABLE instances ADD COLUMN other_contacts_enc VARBINARY(2048) NULL AFTER operator_email_lookup_hash");
        }
    } catch (PDOException $e) {
        error_log('db_ensure_instances_table: ' . $e->getMessage());
    }
}

function db_ensure_instance_status_log_tables(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_instances_table();
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS instance_status_log (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                instance_id INT UNSIGNED NOT NULL,
                actor VARCHAR(255) NULL,
                action VARCHAR(64) NOT NULL,
                details_summary VARCHAR(1024) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_instance (instance_id, created_at),
                FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("CREATE TABLE IF NOT EXISTS instance_status_log_archive LIKE instance_status_log");
    } catch (PDOException $e) {
        error_log('db_ensure_instance_status_log_tables: ' . $e->getMessage());
    }
}

function db_ensure_registry_admins_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS registry_admins (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                email_enc VARBINARY(512) NOT NULL,
                email_lookup_hash VARBINARY(32) NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                seeded_via ENUM('cli','web') NOT NULL DEFAULT 'web',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_email_lookup (email_lookup_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_registry_admins_table: ' . $e->getMessage());
    }
}

function db_ensure_magic_link_tokens_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS magic_link_tokens (
                token_hash VARBINARY(32) PRIMARY KEY,
                email_lookup_hash VARBINARY(32) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                consumed_at TIMESTAMP NULL,
                INDEX idx_email_expires (email_lookup_hash, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_magic_link_tokens_table: ' . $e->getMessage());
    }
}

function db_ensure_sessions_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                session_id VARBINARY(32) PRIMARY KEY,
                subject_type ENUM('operator','admin') NOT NULL,
                subject_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_activity_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                INDEX idx_subject (subject_type, subject_id),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_sessions_table: ' . $e->getMessage());
    }
}

function db_ensure_blacklists_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS blacklists (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                entry_type ENUM('hostname','ip','domain') NOT NULL,
                entry_value VARCHAR(255) NOT NULL,
                reason TEXT NULL,
                added_by VARCHAR(255) NULL,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_type_value (entry_type, entry_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_blacklists_table: ' . $e->getMessage());
    }
}

function db_ensure_anomaly_log_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_instances_table();
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS anomaly_log (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                instance_id INT UNSIGNED NULL,
                anomaly_type VARCHAR(64) NOT NULL,
                severity ENUM('info','warning','severe') NOT NULL DEFAULT 'info',
                details_summary VARCHAR(1024) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_severity_created (severity, created_at),
                FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_anomaly_log_table: ' . $e->getMessage());
    }
}

function db_ensure_key_events_signed_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS key_events_signed (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                origin_host VARCHAR(255) NOT NULL,
                event_type ENUM('scheduled_rotation','operational_rotation','compromise','revocation') NOT NULL,
                occurred_at TIMESTAMP NOT NULL,
                signed_payload MEDIUMTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_origin_occurred (origin_host, occurred_at),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_key_events_signed_table: ' . $e->getMessage());
    }
}

function db_ensure_key_event_push_attempts_table(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db_ensure_key_events_signed_table();
    db_ensure_instances_table();
    try {
        getDB()->exec("
            CREATE TABLE IF NOT EXISTS key_event_push_attempts (
                key_event_id BIGINT UNSIGNED NOT NULL,
                instance_id INT UNSIGNED NOT NULL,
                delivery_status ENUM('pending','delivered','failed','given_up') NOT NULL DEFAULT 'pending',
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_attempt_at TIMESTAMP NULL,
                next_attempt_at TIMESTAMP NULL,
                last_error VARCHAR(1024) NULL,
                PRIMARY KEY (key_event_id, instance_id),
                INDEX idx_pending_next (delivery_status, next_attempt_at),
                FOREIGN KEY (key_event_id) REFERENCES key_events_signed(id) ON DELETE CASCADE,
                FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('db_ensure_key_event_push_attempts_table: ' . $e->getMessage());
    }
}

// Creates both pluriverse_log and pluriverse_log_archive. Same shape and
// retention semantics as the instance-side table; v10 line 1327-1343.
function db_ensure_pluriverse_log_tables(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $pdo = getDB();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pluriverse_log (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                event_type VARCHAR(64) NOT NULL,
                actor VARCHAR(255) NULL,
                target VARCHAR(255) NULL,
                outcome ENUM('success','failure','warning') NOT NULL,
                details_summary VARCHAR(1024) NULL,
                ip_hash VARBINARY(32) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type, created_at),
                INDEX idx_actor (actor, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pluriverse_log_archive LIKE pluriverse_log");
    } catch (PDOException $e) {
        error_log('db_ensure_pluriverse_log_tables: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Seed helpers (stage 2f-ii onward).
// ---------------------------------------------------------------------------

/**
 * How many registry_admins rows exist. Ensures the table first so this is
 * safe to call before db_ensure_federation_schema() ran.
 */
function db_registry_admins_count(): int {
    db_ensure_registry_admins_table();
    return (int)getDB()->query("SELECT COUNT(*) FROM registry_admins")->fetchColumn();
}

/**
 * Seed a single registry_admins row with PII-encrypted email + deterministic
 * lookup hash. Idempotent at the application layer: the UNIQUE constraint on
 * email_lookup_hash means a re-seed of the same email throws (caller catches
 * if a no-op re-seed is desired).
 *
 * Row context for HKDF: hex(email_lookup_hash) — deterministic, unique per
 * row, known at insert time. Matches the v10 plan's interim stance
 * (inc/federation/pii.php docblock); aligns with the same choice that the
 * operator-application flow will use in 2f-iii.
 *
 * @param string $email        valid email; FILTER_VALIDATE_EMAIL enforced
 * @param string $displayName  1..255 chars after trim
 * @param string $seededVia    'cli' | 'web'
 * @return int                 new registry_admins.id
 * @throws InvalidArgumentException on validation failure
 * @throws PDOException on duplicate (email already an admin) or DB error
 */
function db_seed_registry_admin(string $email, string $displayName, string $seededVia = 'cli'): int {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('db_seed_registry_admin: invalid email');
    }
    $displayName = trim($displayName);
    if ($displayName === '' || mb_strlen($displayName) > 255) {
        throw new InvalidArgumentException('db_seed_registry_admin: display_name must be 1..255 chars after trim');
    }
    if (!in_array($seededVia, ['cli', 'web'], true)) {
        throw new InvalidArgumentException('db_seed_registry_admin: seeded_via must be cli or web');
    }

    db_ensure_registry_admins_table();

    $lookupHash = federation_pii_lookup_hash($email);
    $rowContext = 'registry_admin:' . bin2hex($lookupHash);
    $emailEnc = federation_pii_encrypt($email, $rowContext, 'email');

    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO registry_admins (email_enc, email_lookup_hash, display_name, seeded_via)
        VALUES (:enc, :lookup, :name, :via)
    ");
    $stmt->execute([
        ':enc' => $emailEnc,
        ':lookup' => $lookupHash,
        ':name' => $displayName,
        ':via' => $seededVia,
    ]);
    return (int)$pdo->lastInsertId();
}
