<?php
declare(strict_types=1);

/**
 * bin/setup-app.php — application-level bootstrap for the Pluriverse website.
 *
 * The web-context complement to bin/setup-host.php. Runs as the deploying
 * user (not root). Idempotent: re-running brings the install up to current
 * canonical without prompting and without changing already-good state.
 *
 * Mirrors the Telaris instance pattern (admin/setup.php on the instance side
 * is a web wizard; here we skip the wizard because the Pluriverse plan locks
 * "idempotent db_ensure_* on boot, no setup wizard, no SQL file"). This script
 * is the CLI equivalent — useful for fresh deploys and for deploy-hook smoke
 * checks.
 *
 * Modes:
 *   php bin/setup-app.php           # rewrite-to-canonical
 *   php bin/setup-app.php --check   # report only; exit 1 on any gap
 *
 * What it covers:
 *   - PHP version + required extensions
 *   - composer binary on PATH
 *   - composer install --no-dev produced a vendor/ tree
 *   - config.php exists at the docroot (operator must create from sample)
 *   - DB connectivity
 *   - Schema materialized via db_ensure_*
 *   - 4 locale rows present in project_info
 *   - First registry_admins row (bootstrap admin of the Pluriverse), prompted
 *     interactively on a TTY when none exists
 *
 * What it does NOT cover:
 *   - Host-level concerns: nginx vhost, perms, ACLs. See bin/setup-host.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "bin/setup-app.php must be run from the command line, not the web.\n";
    exit(1);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "bin/setup-app.php should NOT be run as root.\n");
    fwrite(STDERR, "  Run as the user that owns the docroot (so composer install + vendor/ ownership is right).\n");
    fwrite(STDERR, "  For host-level perms / nginx / ACLs, run bin/setup-host.php as root.\n");
    exit(1);
}

$opts = getopt('', ['check', 'verbose', 'help']);
$checkOnly = isset($opts['check']);
$verbose = isset($opts['verbose']);

if (isset($opts['help'])) {
    echo "Usage: php bin/setup-app.php [--check] [--verbose]\n";
    echo "\n";
    echo "  --check    Report what's in place and what's missing; exit 1 on any gap.\n";
    echo "  --verbose  Print success-line details too.\n";
    echo "  (no flag)  Run fixes (composer install, db_ensure_*) and report.\n";
    exit(0);
}

$root = realpath(dirname(__DIR__));
if ($root === false) {
    fwrite(STDERR, "Could not realpath the docroot.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Task accumulator. Same shape as bin/setup-host.php so the two scripts read
// the same.
// ---------------------------------------------------------------------------

$tasks = [];

// 1. PHP 8.3+.
$tasks[] = (function() {
    $v = PHP_VERSION;
    if (version_compare($v, '8.3.0', '<')) {
        return ['name' => 'PHP >= 8.3', 'status' => 'error', 'detail' => "running on {$v}; need 8.3+", 'fix' => null];
    }
    return ['name' => 'PHP >= 8.3', 'status' => 'ok', 'detail' => $v, 'fix' => null];
})();

// 2. Required extensions.
$tasks[] = (function() {
    $required = ['pdo_mysql', 'mbstring', 'json', 'ctype'];
    $missing = array_filter($required, fn($e) => !extension_loaded($e));
    if ($missing !== []) {
        return ['name' => 'PHP extensions', 'status' => 'error', 'detail' => 'missing: ' . implode(', ', $missing), 'fix' => null];
    }
    return ['name' => 'PHP extensions', 'status' => 'ok', 'detail' => implode(', ', $required), 'fix' => null];
})();

// 3. composer binary on PATH (or at well-known paths).
$tasks[] = (function() {
    foreach (['/usr/local/bin/composer', '/usr/bin/composer'] as $bin) {
        if (is_executable($bin)) {
            return ['name' => 'composer binary', 'status' => 'ok', 'detail' => $bin, 'fix' => null];
        }
    }
    $rc = 1; $out = [];
    @exec('command -v composer 2>/dev/null', $out, $rc);
    if ($rc === 0 && isset($out[0]) && $out[0] !== '') {
        return ['name' => 'composer binary', 'status' => 'ok', 'detail' => $out[0], 'fix' => null];
    }
    return ['name' => 'composer binary', 'status' => 'missing', 'detail' => 'composer not on PATH', 'fix' => null];
})();

// 4. vendor/ tree from composer install --no-dev.
$tasks[] = (function() use ($root) {
    $autoload = $root . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        return ['name' => 'composer install ran', 'status' => 'ok', 'detail' => 'vendor/autoload.php present', 'fix' => null];
    }
    $fix = function() use ($root) {
        chdir($root);
        $rc = 1; $out = [];
        @exec('composer install --no-dev --no-interaction --no-progress 2>&1', $out, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'detail' => 'composer install rc=' . $rc . ":\n" . implode("\n", $out)];
        }
        return ['ok' => true, 'detail' => 'ran composer install --no-dev'];
    };
    return ['name' => 'composer install ran', 'status' => 'missing', 'detail' => 'no vendor/autoload.php', 'fix' => $fix];
})();

// 5. config.php exists at the docroot.
$tasks[] = (function() use ($root) {
    $cfg = $root . '/config.php';
    if (!file_exists($cfg)) {
        return [
            'name' => 'config.php present',
            'status' => 'missing',
            'detail' => "{$cfg} not found; copy config.php.sample → config.php and fill in DB credentials",
            'fix' => null,
        ];
    }
    return ['name' => 'config.php present', 'status' => 'ok', 'detail' => $cfg, 'fix' => null];
})();

// 6. DB reachable + 7. schema materialized + 8. locale rows seeded. These
// three depend on config.php + vendor/ — group them so a single config-missing
// failure doesn't cascade into noisy downstream errors. They run only if both
// prior tasks were ok.
$canConnect = file_exists($root . '/config.php') && file_exists($root . '/vendor/autoload.php');

if (!$canConnect) {
    $tasks[] = ['name' => 'DB reachable', 'status' => 'missing', 'detail' => 'prerequisite tasks above must pass first', 'fix' => null];
    $tasks[] = ['name' => 'schema materialized', 'status' => 'missing', 'detail' => 'prerequisite tasks above must pass first', 'fix' => null];
    $tasks[] = ['name' => 'locale rows seeded', 'status' => 'missing', 'detail' => 'prerequisite tasks above must pass first', 'fix' => null];
} else {
    try {
        require_once $root . '/config.php';
        $pdo = getDB();
        $version = (string)$pdo->query('SELECT version()')->fetchColumn();
        $tasks[] = ['name' => 'DB reachable', 'status' => 'ok', 'detail' => $version, 'fix' => null];

        // Schema materialize covers website tables (project_info,
        // content_cache) AND federation tables (12 of them; see
        // inc/db_federation.php). Either way idempotent.
        $expectedTables = ['project_info', 'content_cache'];
        $expectedFederation = ['instances', 'instance_status_log', 'instance_status_log_archive', 'registry_admins', 'magic_link_tokens', 'sessions', 'blacklists', 'anomaly_log', 'key_events_signed', 'key_event_push_attempts', 'pluriverse_log', 'pluriverse_log_archive'];
        $allExpected = array_merge($expectedTables, $expectedFederation);

        $missingTables = function() use ($pdo, $allExpected): array {
            $present = array_map('strval', $pdo->query(
                "SELECT tablename FROM pg_tables WHERE schemaname = current_schema()"
            )->fetchAll(PDO::FETCH_COLUMN));
            return array_values(array_diff($allExpected, $present));
        };
        $missing = $missingTables();
        if ($missing === []) {
            $tasks[] = ['name' => 'schema materialized', 'status' => 'ok', 'detail' => count($allExpected) . ' tables present (website + federation)', 'fix' => null];
        } else {
            $fix = function() use ($missingTables) {
                db_ensure_project_info();
                db_ensure_content_cache();
                db_ensure_federation_schema();
                $still = $missingTables();
                if ($still !== []) {
                    return ['ok' => false, 'detail' => 'db_ensure_* ran but still missing: ' . implode(', ', $still)];
                }
                return ['ok' => true, 'detail' => 'ran db_ensure_* (website + federation schema)'];
            };
            $tasks[] = ['name' => 'schema materialized', 'status' => 'missing', 'detail' => 'missing tables: ' . implode(', ', $missing), 'fix' => $fix];
        }

        // Locale row count check. After --check, if project_info was missing
        // this is skipped (no table to query); after fix it should pass.
        $projectInfoPresent = $pdo->query("SELECT to_regclass('project_info')")->fetchColumn() !== null;
        if ($projectInfoPresent) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM project_info")->fetchColumn();
            $expected = count(PLURIVERSE_LOCALES);
            if ($count >= $expected) {
                $tasks[] = ['name' => 'locale rows seeded', 'status' => 'ok', 'detail' => "{$count} rows (expected >= {$expected})", 'fix' => null];
            } else {
                $fix = function() use ($pdo, $expected) {
                    db_seed_project_info();
                    $newCount = (int)$pdo->query("SELECT COUNT(*) FROM project_info")->fetchColumn();
                    if ($newCount < $expected) {
                        return ['ok' => false, 'detail' => "seed ran but only {$newCount} rows (expected >= {$expected})"];
                    }
                    return ['ok' => true, 'detail' => "seeded to {$newCount} rows"];
                };
                $tasks[] = ['name' => 'locale rows seeded', 'status' => 'missing', 'detail' => "only {$count} rows (expected >= {$expected})", 'fix' => $fix];
            }
        } else {
            $tasks[] = ['name' => 'locale rows seeded', 'status' => 'missing', 'detail' => 'schema not materialized yet', 'fix' => null];
        }
    } catch (Throwable $e) {
        $tasks[] = ['name' => 'DB reachable', 'status' => 'error', 'detail' => $e->getMessage(), 'fix' => null];
    }
}

// 9. First registry_admins row seeded (bootstrap admin of the Pluriverse).
// Requires the federation schema (registry_admins table) to be in place.
if (!$canConnect) {
    $tasks[] = ['name' => 'first admin seeded', 'status' => 'missing', 'detail' => 'prerequisite tasks above must pass first', 'fix' => null];
} else {
    try {
        $registryAdminsPresent = $pdo->query("SELECT to_regclass('registry_admins')")->fetchColumn() !== null;
        if (!$registryAdminsPresent) {
            $tasks[] = ['name' => 'first admin seeded', 'status' => 'missing', 'detail' => 'registry_admins table not yet materialized', 'fix' => null];
        } else {
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM registry_admins")->fetchColumn();
            if ($adminCount > 0) {
                $tasks[] = ['name' => 'first admin seeded', 'status' => 'ok', 'detail' => "{$adminCount} admin row(s) present", 'fix' => null];
            } else {
                $fix = function() use ($root) {
                    if (!defined('STDIN') || !stream_isatty(STDIN)) {
                        return ['ok' => false, 'detail' => 'no admin rows and stdin is not a TTY; seed manually via db_seed_registry_admin($email, $displayName, "cli")'];
                    }
                    echo "\n";
                    echo "  Seeding first Pluriverse admin (registry_admins.seeded_via=cli).\n";
                    echo "  This account will have authority over operator applications,\n";
                    echo "  the blacklist, and the audit log on day one.\n\n";
                    $email = '';
                    while ($email === '') {
                        echo "  Email: ";
                        $line = trim((string)fgets(STDIN));
                        if ($line === '') { echo "    (required)\n"; continue; }
                        if (!filter_var($line, FILTER_VALIDATE_EMAIL)) { echo "    (not a valid email; try again)\n"; continue; }
                        $email = $line;
                    }
                    $displayName = '';
                    while ($displayName === '') {
                        echo "  Display name: ";
                        $line = trim((string)fgets(STDIN));
                        if ($line === '') { echo "    (required)\n"; continue; }
                        if (mb_strlen($line) > 255) { echo "    (too long; max 255 chars)\n"; continue; }
                        $displayName = $line;
                    }
                    try {
                        $id = db_seed_registry_admin($email, $displayName, 'cli');
                        return ['ok' => true, 'detail' => "registry_admins.id={$id} ({$displayName})"];
                    } catch (Throwable $e) {
                        return ['ok' => false, 'detail' => 'seed failed: ' . $e->getMessage()];
                    }
                };
                $tasks[] = ['name' => 'first admin seeded', 'status' => 'missing', 'detail' => 'no registry_admins rows yet (interactive prompt on TTY)', 'fix' => $fix];
            }
        }
    } catch (Throwable $e) {
        $tasks[] = ['name' => 'first admin seeded', 'status' => 'error', 'detail' => $e->getMessage(), 'fix' => null];
    }
}

// ---------------------------------------------------------------------------
// Execute.
// ---------------------------------------------------------------------------

$header = $checkOnly
    ? sprintf("Pluriverse app check — root=%s\n", $root)
    : sprintf("Pluriverse app setup — root=%s\n", $root);
echo $header;
echo str_repeat('=', strlen(trim($header))) . "\n";

$exitCode = 0;
foreach ($tasks as $task) {
    $status = $task['status'];
    $name = $task['name'];
    $detail = $task['detail'];

    if ($status === 'ok') {
        printf("  [ok]      %s%s\n", $name, $verbose ? " — {$detail}" : '');
        continue;
    }

    printf("  [%s] %s — %s\n", $status, $name, $detail);

    if ($checkOnly) {
        $exitCode = 1;
        continue;
    }

    if (!isset($task['fix']) || $task['fix'] === null) {
        printf("           (no automatic fix — operator intervention required)\n");
        $exitCode = 1;
        continue;
    }

    $result = ($task['fix'])();
    if ($result['ok']) {
        printf("           → fixed: %s\n", $result['detail']);
    } else {
        printf("           → fix FAILED: %s\n", $result['detail']);
        $exitCode = 1;
    }
}

echo "\n";
if ($exitCode === 0) {
    echo $checkOnly ? "All checks passed.\n" : "App setup complete.\n";
} else {
    echo $checkOnly
        ? "One or more checks failed; re-run without --check to apply fixes, or address operator-intervention items first.\n"
        : "Setup completed with errors; re-run after addressing the messages above.\n";
}
exit($exitCode);
