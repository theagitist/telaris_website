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
        // Fresh-install shape. Inline ALTER probes carry forward earlier
        // installs:
        //   - 2026-05-24: collapse telegram_handle_enc + signal_contact_enc
        //     into a single JSON-in-secretbox other_contacts_enc column.
        //   - 2026-05-24: UNIQUE constraint on instances.label so the
        //     operator-application form's "Name" field is reservable per
        //     instance and stays stable on /api/pluriverse/peers.json. The
        //     UNIQUE is case-insensitive by table collation.
        //   - 2026-05-25: locale column carrying the language the operator
        //     applied in. Used to render the verify-magic-link page and any
        //     future status emails in the operator's preferred language.
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
                locale CHAR(2) NOT NULL DEFAULT 'en',
                is_highlighted BOOLEAN NOT NULL DEFAULT FALSE,
                admission_status ENUM('pending','verified','published','rejected','blacklisted','outdated','withdrawn','revoked') NOT NULL DEFAULT 'pending',
                verify_by_at TIMESTAMP NULL,
                last_seen_at TIMESTAMP NULL,
                health_status ENUM('up','degraded','down','unknown') NOT NULL DEFAULT 'unknown',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_hostname (hostname),
                UNIQUE KEY uniq_operator_email_lookup (operator_email_lookup_hash),
                UNIQUE KEY uniq_label (label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // MySQL 8 lacks IF EXISTS / IF NOT EXISTS at the column or index
        // level, so probe INFORMATION_SCHEMA first.
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instances'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $colsPresent = array_flip(array_map('strval', $cols));
        if (isset($colsPresent['telegram_handle_enc'])) {
            $pdo->exec("ALTER TABLE instances DROP COLUMN telegram_handle_enc");
        }
        if (isset($colsPresent['signal_contact_enc'])) {
            $pdo->exec("ALTER TABLE instances DROP COLUMN signal_contact_enc");
        }
        if (!isset($colsPresent['other_contacts_enc'])) {
            $pdo->exec("ALTER TABLE instances ADD COLUMN other_contacts_enc VARBINARY(2048) NULL AFTER operator_email_lookup_hash");
        }
        if (!isset($colsPresent['locale'])) {
            $pdo->exec("ALTER TABLE instances ADD COLUMN locale CHAR(2) NOT NULL DEFAULT 'en' AFTER bridges");
        }
        $indexes = $pdo->query("
            SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instances'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $indexNames = array_flip(array_map('strval', $indexes));
        if (!isset($indexNames['uniq_label'])) {
            $pdo->exec("ALTER TABLE instances ADD UNIQUE KEY uniq_label (label)");
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

/**
 * Row-context format for instances and registry_admins PII columns.
 *
 * info string = hex(email_lookup_hash) . ':' . column_name. Chosen because
 * the lookup hash is deterministic per email, known at INSERT time (no two
 * stage write needed), differs across rows, and survives auto-increment
 * rebuilds. Documented in inc/federation/pii.php's docblock.
 */
function federation_row_context_for_instance(string $emailLookupHash): string {
    return 'instance:' . bin2hex($emailLookupHash);
}

/**
 * Insert a pending operator application into instances. Caller has already
 * validated input and fetched the identity envelope; this helper handles
 * PII encryption, lookup-hash computation, and the INSERT itself.
 *
 * Returns the new row id.
 *
 * Encryption choices:
 * - operator_email_enc: per-row HKDF-derived key (rowContext = "instance:" .
 *   hex(lookup_hash)); column_name "email".
 * - other_contacts_enc: same rowContext, column_name "other_contacts". The
 *   plaintext is the JSON-encoded contacts array (canonical UTF-8 JSON; no
 *   pretty-printing).
 *
 * Throws PDOException on UNIQUE constraint violation (hostname or
 * operator_email_lookup_hash already present); caller should map to 409.
 */
function db_insert_instance_application(array $application, array $identity): int {
    db_ensure_instances_table();

    $email = (string)$application['operator_email'];
    $lookupHash = federation_pii_lookup_hash($email);
    $rowContext = federation_row_context_for_instance($lookupHash);

    $emailEnc = federation_pii_encrypt($email, $rowContext, 'email');

    $otherContactsEnc = null;
    if (!empty($application['other_contacts']) && is_array($application['other_contacts'])) {
        $json = json_encode(array_values($application['other_contacts']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 1024) {
            throw new InvalidArgumentException('other_contacts JSON exceeds 1024 bytes after encoding');
        }
        $otherContactsEnc = federation_pii_encrypt($json, $rowContext, 'other_contacts');
    }

    $publishableSlugs = (!empty($application['publishable_slugs']) && is_array($application['publishable_slugs']))
        ? json_encode(array_values($application['publishable_slugs']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        : null;
    $bridges = (!empty($application['bridges']) && is_array($application['bridges']))
        ? json_encode(array_values($application['bridges']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        : null;

    $locale = (string)($application['locale'] ?? 'en');
    if (!in_array($locale, ['en', 'es', 'pt', 'fr'], true)) {
        $locale = 'en';
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO instances (
            hostname, url, pluriverse_endpoint, public_key,
            operator_email_enc, operator_email_lookup_hash, other_contacts_enc,
            label, editorial_framing, publishable_slugs, bridges, locale,
            admission_status, verify_by_at
        ) VALUES (
            :hostname, :url, :endpoint, :pk,
            :email_enc, :lookup, :contacts,
            :label, :framing, :slugs, :bridges, :locale,
            'pending', DATE_ADD(NOW(), INTERVAL 48 HOUR)
        )
    ");
    $stmt->execute([
        ':hostname' => (string)$application['hostname'],
        ':url' => (string)$application['url'],
        ':endpoint' => (string)$application['pluriverse_endpoint'],
        ':pk' => (string)$identity['public_key'],
        ':email_enc' => $emailEnc,
        ':lookup' => $lookupHash,
        ':contacts' => $otherContactsEnc,
        ':label' => (string)$application['label'],
        ':framing' => isset($application['editorial_framing']) && $application['editorial_framing'] !== ''
            ? (string)$application['editorial_framing']
            : null,
        ':slugs' => $publishableSlugs,
        ':bridges' => $bridges,
        ':locale' => $locale,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Mint a one-hour single-use magic-link token bound to an email lookup hash.
 *
 * Persists SHA-256(raw_bytes) as token_hash; returns the raw 32 bytes so
 * the caller can base64url-encode them for the verification URL.
 *
 * The verify endpoint (2g-i) hashes the received URL parameter the same way
 * and matches against token_hash, then sets consumed_at on first success.
 */
function db_create_magic_link_token(string $emailLookupHash, int $ttlSeconds = 3600): string {
    if (strlen($emailLookupHash) !== 32) {
        throw new InvalidArgumentException('db_create_magic_link_token: lookup hash must be 32 bytes');
    }
    db_ensure_magic_link_tokens_table();
    $raw = random_bytes(32);
    $tokenHash = hash('sha256', $raw, true);
    $stmt = getDB()->prepare("
        INSERT INTO magic_link_tokens (token_hash, email_lookup_hash, expires_at)
        VALUES (:th, :lh, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
    ");
    $stmt->bindValue(':th', $tokenHash, PDO::PARAM_LOB);
    $stmt->bindValue(':lh', $emailLookupHash, PDO::PARAM_LOB);
    $stmt->bindValue(':ttl', $ttlSeconds, PDO::PARAM_INT);
    $stmt->execute();
    return $raw;
}

/**
 * Base64url-encode raw token bytes (no padding) for use in a magic-link URL.
 */
function federation_token_url_encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * Base64url-decode a magic-link URL parameter. Returns the raw bytes or '' on
 * malformed input. The verify endpoint validates the resulting length (must
 * be 32) before using it for a hash lookup.
 */
function federation_token_url_decode(string $encoded): string {
    if ($encoded === '' || strlen($encoded) > 64) return '';
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $encoded)) return '';
    $b64 = strtr($encoded, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad !== 0) $b64 .= str_repeat('=', 4 - $pad);
    $raw = base64_decode($b64, true);
    return is_string($raw) ? $raw : '';
}

/**
 * Atomically consume a magic-link token by raw bytes.
 *
 * On success: marks consumed_at = NOW(), returns
 * ['status' => 'consumed', 'email_lookup_hash' => <32 bytes>].
 *
 * On a row-found-but-unusable case (expired, or already consumed), returns
 * ['status' => 'expired' | 'already_consumed', 'email_lookup_hash' => <bytes>]
 * so the caller can distinguish those from "unknown token".
 *
 * Returns null on unknown token, malformed input, or unrecoverable DB error.
 *
 * The SELECT ... FOR UPDATE + UPDATE pair runs in a transaction, so two
 * concurrent verify clicks for the same token cannot both succeed.
 */
function db_consume_magic_link_token(string $rawToken): ?array {
    if (strlen($rawToken) !== 32) return null;
    db_ensure_magic_link_tokens_table();
    $tokenHash = hash('sha256', $rawToken, true);
    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        $sel = $pdo->prepare("
            SELECT email_lookup_hash, expires_at, consumed_at
            FROM magic_link_tokens
            WHERE token_hash = :th
            FOR UPDATE
        ");
        $sel->bindValue(':th', $tokenHash, PDO::PARAM_LOB);
        $sel->execute();
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $pdo->rollBack();
            return null;
        }
        $emailLookupHash = (string)$row['email_lookup_hash'];
        if ($row['consumed_at'] !== null) {
            $pdo->rollBack();
            return ['status' => 'already_consumed', 'email_lookup_hash' => $emailLookupHash];
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            $pdo->rollBack();
            return ['status' => 'expired', 'email_lookup_hash' => $emailLookupHash];
        }
        $upd = $pdo->prepare("
            UPDATE magic_link_tokens SET consumed_at = NOW()
            WHERE token_hash = :th AND consumed_at IS NULL
        ");
        $upd->bindValue(':th', $tokenHash, PDO::PARAM_LOB);
        $upd->execute();
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return ['status' => 'already_consumed', 'email_lookup_hash' => $emailLookupHash];
        }
        $pdo->commit();
        return ['status' => 'consumed', 'email_lookup_hash' => $emailLookupHash];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('db_consume_magic_link_token: ' . $e->getMessage());
        return null;
    }
}

/**
 * Fetch the instance row keyed by operator_email_lookup_hash (the same 32-byte
 * value `magic_link_tokens.email_lookup_hash` carries).
 *
 * Returns the row as an associative array, or null if no such instance.
 * The returned shape includes locale + admission_status; PII columns are
 * NOT returned (verify never needs the plaintext email).
 */
function db_get_instance_by_email_lookup_hash(string $lookupHash): ?array {
    if (strlen($lookupHash) !== 32) return null;
    db_ensure_instances_table();
    $stmt = getDB()->prepare("
        SELECT id, hostname, url, label, locale, admission_status
        FROM instances
        WHERE operator_email_lookup_hash = :lh
        LIMIT 1
    ");
    $stmt->bindValue(':lh', $lookupHash, PDO::PARAM_LOB);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Transition `instances.admission_status` from $expected → $next, atomically.
 *
 * Returns true iff a row was updated (i.e. the instance was in $expected at
 * the moment of the UPDATE). Returns false if the instance is in some other
 * state (idempotent: a second verify click on an already-verified instance
 * returns false without changing anything).
 *
 * Also appends to instance_status_log so the admin surface can replay
 * lifecycle events. The log write happens in the same transaction as the
 * status flip so they cannot diverge.
 */
function db_transition_instance_admission(
    int $instanceId,
    string $expected,
    string $next,
    string $actor,
    ?string $details = null
): bool {
    db_ensure_instances_table();
    db_ensure_instance_status_log_tables();
    $pdo = getDB();
    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("
            UPDATE instances SET admission_status = :next
            WHERE id = :id AND admission_status = :expected
        ");
        $upd->execute([
            ':next' => $next,
            ':expected' => $expected,
            ':id' => $instanceId,
        ]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $log = $pdo->prepare("
            INSERT INTO instance_status_log (instance_id, actor, action, details_summary)
            VALUES (:id, :actor, :action, :details)
        ");
        $log->execute([
            ':id' => $instanceId,
            ':actor' => $actor,
            ':action' => 'transition:' . $expected . '->' . $next,
            ':details' => $details,
        ]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('db_transition_instance_admission: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check whether an instance Name (DB column `label`) is available.
 *
 * Single indexed lookup against the UNIQUE uniq_label index. Returns true
 * if no instance currently claims this label. Case-insensitive via the
 * table collation (utf8mb4_unicode_ci).
 *
 * Used by the apply-form's async availability check and by the pre-INSERT
 * conflict probe in apply_handler.php.
 */
function db_label_available(string $label): bool {
    db_ensure_instances_table();
    $label = trim($label);
    if ($label === '' || mb_strlen($label) > 255) {
        return false;
    }
    $stmt = getDB()->prepare("SELECT 1 FROM instances WHERE label = :l LIMIT 1");
    $stmt->execute([':l' => $label]);
    return $stmt->fetchColumn() === false;
}
