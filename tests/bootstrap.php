<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Pluriverse server suite.
 *
 *  1. Force mail into dry-run so no test ever contacts an SMTP relay.
 *  2. Load app config (DB_* constants + inc/db.php). No DB connection happens
 *     at require time; the first getDB() does.
 *  3. Route EVERY getDB() call to the isolated LOCAL Postgres `pluriverse_test`
 *     database via the pluriverse_db_reset_for_testing() seam, so the suite can
 *     never touch the live `pluriverse` data (the lesson from the instance suite
 *     deleting a real peer row). The local dev role + password come from
 *     ~/apps/keys/pluriverse-postgres-dev (never hardcoded). The schema is
 *     materialized in the test DB via the umbrella ensure + db_ensure_pg_runtime.
 */

define('MAIL_DRY_RUN', true);

require dirname(__DIR__) . '/config.php';

// Local Postgres test connection. Role + password from the dev keyfile so no
// secret is committed; if the keyfile is unreadable the suite cannot run
// (better than silently falling back to anything live).
$__telaris_keyfile = getenv('HOME') . '/apps/keys/pluriverse-postgres-dev';
if (!is_readable($__telaris_keyfile)) {
    fwrite(STDERR, "tests/bootstrap.php: cannot read $__telaris_keyfile (local Postgres dev password)\n");
    exit(1);
}
$__telaris_test_pass = trim((string)file_get_contents($__telaris_keyfile));
$__telaris_test_pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=pluriverse_test',
    'pluriverse',
    $__telaris_test_pass,
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
