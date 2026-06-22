<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Pluriverse server suite.
 *
 *  1. Force mail into dry-run so no test ever contacts an SMTP relay.
 *  2. Load app config (DB_* constants + inc/db.php). No DB connection happens
 *     at require time; the first getDB() does.
 *  3. Route EVERY getDB() call to the isolated `telaris_pluriverse_test`
 *     database via the pluriverse_db_reset_for_testing() seam, so the suite can
 *     never touch the live `telaris_pluriverse` data (the lesson from the instance
 *     suite deleting a real peer row). The connection follows the app config.php
 *     DB endpoint (DB_HOST/DB_PORT/DB_SSL_CA, the managed cluster since the Phase 0
 *     migration), reusing DB_USER/DB_PASS but swapping in the `_test` database, so
 *     no host or secret is hardcoded here. The schema is materialized in the test
 *     DB via the umbrella ensure + db_ensure_pg_runtime.
 */

define('MAIL_DRY_RUN', true);

require dirname(__DIR__) . '/config.php';

// Test connection follows the app's DB endpoint (managed cluster post Phase 0),
// only swapping the database name to the isolated `_test` DB. Reuses DB_USER /
// DB_PASS / DB_SSL_CA from config.php, so nothing is hardcoded or committed.
$__telaris_test_port = defined('DB_PORT') && DB_PORT !== '' ? DB_PORT : '5432';
$__telaris_test_dsn  = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, $__telaris_test_port, 'telaris_pluriverse_test');
if (defined('DB_SSL_CA') && DB_SSL_CA !== '') {
    $__telaris_test_dsn .= sprintf(';sslmode=verify-ca;sslrootcert=%s', DB_SSL_CA);
}
$__telaris_test_pdo = new PDO(
    $__telaris_test_dsn,
    DB_USER,
    defined('DB_PASS') ? DB_PASS : '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$__telaris_test_pdo->exec("SET TIME ZONE 'UTC'");
pluriverse_db_reset_for_testing($__telaris_test_pdo);

// Materialize the full schema in the isolated test DB (idempotent).
require_once dirname(__DIR__) . '/inc/db_federation.php';
db_ensure_pg_runtime();
db_ensure_federation_schema();

// Shared base class for HTTP endpoint-handler tests (lives in a subdir, so
// PHPUnit's test-file discovery does not load it on its own).
require __DIR__ . '/Support/PluriverseHandlerTestCase.php';
