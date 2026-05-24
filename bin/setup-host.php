<?php
declare(strict_types=1);

/**
 * bin/setup-host.php — host-level provisioning for the Pluriverse website.
 *
 * Companion to bin/setup-app.php (which runs as the deploying user and
 * handles composer + schema). This script runs as root and idempotently
 * installs the bits the app-side script cannot reach:
 *
 *   - nginx vhost at /etc/nginx/sites-available/www.telaris.ca.conf
 *     (installed from the repo's etc/nginx/www.telaris.ca.conf.sample
 *     if no vhost exists; otherwise left alone, since Certbot edits in
 *     place after the initial install and a literal drift check would
 *     break on every cert renewal)
 *   - chmod 0640 + chgrp www-data on config.php
 *   - filesystem ACLs on the docs source tree so PHP-FPM (www-data) can
 *     read the markdown sources the Manifest / Privacy / Terms pages
 *     render (path inferred from PLURIVERSE_DOCS_SRC in config.php)
 *
 * Scope: Ubuntu / Debian only. Other distros need their own bridge.
 *
 * Modes:
 *   sudo php bin/setup-host.php           # rewrite to canonical, reload nginx
 *   sudo php bin/setup-host.php --check   # report only; exit 1 on any gap
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "bin/setup-host.php must be run from the command line, not the web.\n";
    exit(1);
}

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "bin/setup-host.php must be run as root.\n");
    fwrite(STDERR, "  Try: sudo php bin/setup-host.php" . (isset($argv[1]) ? ' ' . $argv[1] : '') . "\n");
    exit(1);
}

$osRelease = @parse_ini_file('/etc/os-release');
$idLike = strtolower(trim((string)($osRelease['ID_LIKE'] ?? '')));
$id = strtolower(trim((string)($osRelease['ID'] ?? '')));
if ($id !== 'ubuntu' && $id !== 'debian' && !str_contains($idLike, 'debian') && !str_contains($idLike, 'ubuntu')) {
    fwrite(STDERR, "Unsupported distro: ID={$id}, ID_LIKE={$idLike}. Ubuntu / Debian only.\n");
    exit(1);
}

$opts = getopt('', ['check', 'verbose', 'help']);
$checkOnly = isset($opts['check']);
$verbose = isset($opts['verbose']);

if (isset($opts['help'])) {
    echo "Usage: sudo php bin/setup-host.php [--check] [--verbose]\n";
    echo "\n";
    echo "  --check    Report what's installed and what's missing; exit 1 on any gap.\n";
    echo "  --verbose  Print success-line details too.\n";
    echo "  (no flag)  Apply fixes and reload nginx if any nginx-touching fix succeeded.\n";
    exit(0);
}

// Concurrency guard.
$lockPath = '/run/pluriverse-setup-host.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "bin/setup-host.php is already running (lock held at {$lockPath}).\n");
    exit(1);
}

$rootCandidate = dirname(__DIR__);
$root = realpath($rootCandidate);
if ($root === false) {
    fwrite(STDERR, "Could not realpath the docroot ({$rootCandidate}).\n");
    exit(1);
}
$siteName = basename($root);

$vhostSrc = $root . '/etc/nginx/www.telaris.ca.conf.sample';
$vhostDst = '/etc/nginx/sites-available/' . $siteName . '.conf';
$vhostLink = '/etc/nginx/sites-enabled/' . $siteName . '.conf';
$configPath = $root . '/config.php';

// ---------------------------------------------------------------------------
// Tasks.
// ---------------------------------------------------------------------------

$tasks = [];

// 1. nginx installed + active.
$tasks[] = (function() {
    $bin = '/usr/sbin/nginx';
    if (!file_exists($bin)) {
        return ['name' => 'nginx installed', 'status' => 'missing', 'detail' => "nginx binary not at {$bin}", 'fix' => null];
    }
    $rc = 1; $out = [];
    @exec('systemctl is-active nginx 2>&1', $out, $rc);
    $msg = trim(implode("\n", $out));
    if ($rc !== 0 || $msg !== 'active') {
        return ['name' => 'nginx service active', 'status' => 'missing', 'detail' => "systemctl is-active nginx → '{$msg}' (rc={$rc})", 'fix' => null];
    }
    return ['name' => 'nginx installed + active', 'status' => 'ok', 'detail' => 'active', 'fix' => null];
})();

// 2. PHP-FPM 8.3 active.
$tasks[] = (function() {
    $rc = 1; $out = [];
    @exec('systemctl is-active php8.3-fpm 2>&1', $out, $rc);
    $msg = trim(implode("\n", $out));
    if ($rc !== 0 || $msg !== 'active') {
        return ['name' => 'php8.3-fpm active', 'status' => 'missing', 'detail' => "systemctl is-active php8.3-fpm → '{$msg}' (rc={$rc})", 'fix' => null];
    }
    return ['name' => 'php8.3-fpm active', 'status' => 'ok', 'detail' => 'active', 'fix' => null];
})();

// 3. nginx vhost present. Install from repo sample if missing; do NOT
// drift-check, because Certbot edits the file in place after the initial
// install and a literal match would fail on every cert renewal.
$tasks[] = (function() use ($vhostSrc, $vhostDst) {
    if (!file_exists($vhostSrc)) {
        return ['name' => 'nginx vhost', 'status' => 'error', 'detail' => "repo source missing at {$vhostSrc}", 'fix' => null];
    }
    if (file_exists($vhostDst)) {
        return ['name' => 'nginx vhost', 'status' => 'ok', 'detail' => "{$vhostDst} present (operator-managed after first install)", 'fix' => null];
    }
    $fix = function() use ($vhostSrc, $vhostDst) {
        if (is_link($vhostDst)) {
            return ['ok' => false, 'detail' => "{$vhostDst} is a symlink; refusing to write through it"];
        }
        $canonical = (string)file_get_contents($vhostSrc);
        $tmp = $vhostDst . '.new.' . posix_getpid();
        if (file_put_contents($tmp, $canonical) === false) {
            return ['ok' => false, 'detail' => "could not write to {$tmp}"];
        }
        @chmod($tmp, 0644);
        @chown($tmp, 'root');
        @chgrp($tmp, 'root');
        if (!rename($tmp, $vhostDst)) {
            @unlink($tmp);
            return ['ok' => false, 'detail' => "could not move {$tmp} → {$vhostDst}"];
        }
        $rc = 1; $out = [];
        @exec('/usr/sbin/nginx -t 2>&1', $out, $rc);
        if ($rc !== 0) {
            @unlink($vhostDst);
            return ['ok' => false, 'detail' => "nginx -t rejected the new vhost:\n" . implode("\n", $out)];
        }
        return ['ok' => true, 'detail' => "wrote {$vhostDst} (validated by nginx -t)"];
    };
    return ['name' => 'nginx vhost', 'status' => 'missing', 'detail' => "{$vhostDst} does not exist", 'fix' => $fix];
})();

// 4. vhost enabled (sites-enabled symlink).
$tasks[] = (function() use ($vhostDst, $vhostLink) {
    if (!file_exists($vhostDst)) {
        return ['name' => 'vhost enabled', 'status' => 'missing', 'detail' => 'install the vhost first (task above)', 'fix' => null];
    }
    if (is_link($vhostLink) && readlink($vhostLink) === $vhostDst) {
        return ['name' => 'vhost enabled', 'status' => 'ok', 'detail' => "{$vhostLink} → {$vhostDst}", 'fix' => null];
    }
    if (file_exists($vhostLink)) {
        return ['name' => 'vhost enabled', 'status' => 'mismatch', 'detail' => "{$vhostLink} exists but does not link to {$vhostDst} — operator intervention", 'fix' => null];
    }
    $fix = function() use ($vhostDst, $vhostLink) {
        if (!symlink($vhostDst, $vhostLink)) {
            return ['ok' => false, 'detail' => "symlink {$vhostLink} → {$vhostDst} failed"];
        }
        return ['ok' => true, 'detail' => "linked {$vhostLink} → {$vhostDst}"];
    };
    return ['name' => 'vhost enabled', 'status' => 'missing', 'detail' => "{$vhostLink} missing", 'fix' => $fix];
})();

// 5. config.php perms: 0640, group www-data, owner in allowlist (root or
// $SUDO_USER). Refuses to bless a config.php owned by an unexpected user.
$tasks[] = (function() use ($configPath) {
    if (!file_exists($configPath)) {
        return ['name' => 'config.php exists', 'status' => 'missing', 'detail' => "{$configPath} does not exist (copy config.php.sample → config.php, then re-run)", 'fix' => null];
    }
    $mode = fileperms($configPath) & 0777;
    $group = posix_getgrgid(filegroup($configPath));
    $groupName = is_array($group) ? ($group['name'] ?? '?') : '?';
    $ownerUid = fileowner($configPath);
    $ownerPwd = posix_getpwuid($ownerUid);
    $ownerName = is_array($ownerPwd) ? ($ownerPwd['name'] ?? '?') : '?';

    $allowedOwners = ['root'];
    $sudoUser = (string)($_SERVER['SUDO_USER'] ?? '');
    if ($sudoUser !== '' && $sudoUser !== 'root') {
        $allowedOwners[] = $sudoUser;
    }
    $modeOk = ($mode === 0640);
    $groupOk = ($groupName === 'www-data');
    $ownerOk = in_array($ownerName, $allowedOwners, true);

    if ($modeOk && $groupOk && $ownerOk) {
        return ['name' => 'config.php perms', 'status' => 'ok', 'detail' => sprintf('mode %o owner %s group %s', $mode, $ownerName, $groupName), 'fix' => null];
    }
    if (!$ownerOk) {
        $allow = implode(', ', $allowedOwners);
        return [
            'name' => 'config.php perms',
            'status' => 'error',
            'detail' => "owned by '{$ownerName}', not in allowlist [{$allow}]; refusing to chmod (investigate before re-running)",
            'fix' => null,
        ];
    }
    $fix = function() use ($configPath) {
        $okGroup = @chgrp($configPath, 'www-data');
        $okMode = @chmod($configPath, 0640);
        if (!$okGroup || !$okMode) {
            return ['ok' => false, 'detail' => 'chgrp/chmod failed; check ownership of ' . $configPath];
        }
        return ['ok' => true, 'detail' => "chgrp www-data + chmod 0640 on {$configPath}"];
    };
    return [
        'name' => 'config.php perms',
        'status' => 'mismatch',
        'detail' => sprintf('mode %o owner %s group %s; want 0640 group www-data', $mode, $ownerName, $groupName),
        'fix' => $fix,
    ];
})();

// 6. ACLs on the docs source tree so PHP-FPM (www-data) can read the markdown
// the Manifest / Privacy / Terms pages render at request time. Discovers the
// path from config.php's PLURIVERSE_DOCS_SRC; falls back to a clear "manual"
// message if the constant isn't defined yet.
$tasks[] = (function() use ($configPath) {
    if (!file_exists($configPath)) {
        return ['name' => 'docs ACLs', 'status' => 'missing', 'detail' => 'config.php must exist first', 'fix' => null];
    }
    // Defer-load config.php in an isolated way: just grep for the constant
    // value so we don't execute the DB-init side-effects of the real config.
    $body = (string)file_get_contents($configPath);
    if (!preg_match("/define\(\s*['\"]PLURIVERSE_DOCS_SRC['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $body, $m)) {
        return ['name' => 'docs ACLs', 'status' => 'missing', 'detail' => "PLURIVERSE_DOCS_SRC not defined in config.php", 'fix' => null];
    }
    $docsSrc = $m[1];
    if (!is_dir($docsSrc)) {
        return ['name' => 'docs ACLs', 'status' => 'error', 'detail' => "PLURIVERSE_DOCS_SRC={$docsSrc} is not a directory", 'fix' => null];
    }

    // Walk the chain of parents www-data needs `x` (search) on, plus
    // `rX` (read + traverse) on the docs src itself.
    // Parents are every directory from docsSrc back to /.
    $parents = [];
    $p = dirname($docsSrc);
    while ($p !== '/' && $p !== '.' && $p !== '') {
        $parents[] = $p;
        $next = dirname($p);
        if ($next === $p) break;
        $p = $next;
    }
    $parents = array_reverse($parents); // outer-first

    $setfacl = '/usr/bin/setfacl';
    if (!is_executable($setfacl)) {
        return ['name' => 'docs ACLs', 'status' => 'missing', 'detail' => "{$setfacl} not installed; apt install acl", 'fix' => null];
    }

    // Probe: can www-data read PLURIVERSE_DOCS_SRC/manifest/01-manifest.md
    // (or any file under it)? If yes, ACLs are sufficient.
    $probeCmd = 'sudo -u www-data test -r ' . escapeshellarg($docsSrc) . ' && '
              . 'sudo -u www-data find ' . escapeshellarg($docsSrc) . ' -name "01-*.md" -readable 2>/dev/null | head -1';
    $rc = 1; $out = [];
    @exec($probeCmd, $out, $rc);
    $sampleReadable = isset($out[0]) && $out[0] !== '';
    if ($sampleReadable) {
        return ['name' => 'docs ACLs', 'status' => 'ok', 'detail' => "www-data can read {$docsSrc}", 'fix' => null];
    }

    $fix = function() use ($setfacl, $parents, $docsSrc) {
        foreach ($parents as $parent) {
            $rc = 1; $out = [];
            @exec('sudo ' . escapeshellarg($setfacl) . ' -m u:www-data:x ' . escapeshellarg($parent) . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                return ['ok' => false, 'detail' => "setfacl -m u:www-data:x {$parent} failed: " . implode("\n", $out)];
            }
        }
        $rc = 1; $out = [];
        @exec('sudo ' . escapeshellarg($setfacl) . ' -R -m u:www-data:rX ' . escapeshellarg($docsSrc) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return ['ok' => false, 'detail' => "setfacl -R -m u:www-data:rX {$docsSrc} failed: " . implode("\n", $out)];
        }
        return ['ok' => true, 'detail' => 'applied search ACLs on ' . count($parents) . ' parent(s) + read+traverse on ' . $docsSrc];
    };
    return [
        'name' => 'docs ACLs',
        'status' => 'missing',
        'detail' => "www-data cannot read {$docsSrc}; needs ACLs on it and its parents",
        'fix' => $fix,
    ];
})();

// 7. secrets/ directory perms: 0700 www-data:www-data. The federation secret
// keys (pluriverse-coord.key, log.key, pii_master.key, pii_lookup.key) live
// here; bin/init-coord-key + bin/init-log-key + bin/init-pii-keys generate
// the file contents. This task only ensures the dir itself is correct.
$tasks[] = (function() use ($root) {
    $secretsDir = $root . '/secrets';
    if (!file_exists($secretsDir)) {
        $fix = function() use ($secretsDir) {
            if (!@mkdir($secretsDir, 0700, true) && !is_dir($secretsDir)) {
                return ['ok' => false, 'detail' => "could not create {$secretsDir}"];
            }
            $okMode = @chmod($secretsDir, 0700);
            $okOwner = @chown($secretsDir, 'www-data');
            $okGroup = @chgrp($secretsDir, 'www-data');
            if (!$okMode || !$okOwner || !$okGroup) {
                return ['ok' => false, 'detail' => "chmod/chown/chgrp on {$secretsDir} partially failed"];
            }
            return ['ok' => true, 'detail' => "created {$secretsDir} (0700 www-data:www-data)"];
        };
        return ['name' => 'secrets/ dir', 'status' => 'missing', 'detail' => "{$secretsDir} does not exist", 'fix' => $fix];
    }
    if (is_link($secretsDir)) {
        return ['name' => 'secrets/ dir', 'status' => 'error', 'detail' => "{$secretsDir} is a symlink; refusing to chmod through it (unlink first if intentional)", 'fix' => null];
    }
    $mode = fileperms($secretsDir) & 0777;
    $ownerPwd = posix_getpwuid(fileowner($secretsDir));
    $ownerName = is_array($ownerPwd) ? ($ownerPwd['name'] ?? '?') : '?';
    $groupGrp = posix_getgrgid(filegroup($secretsDir));
    $groupName = is_array($groupGrp) ? ($groupGrp['name'] ?? '?') : '?';
    $modeOk = ($mode === 0700);
    $ownerOk = ($ownerName === 'www-data');
    $groupOk = ($groupName === 'www-data');
    if ($modeOk && $ownerOk && $groupOk) {
        return ['name' => 'secrets/ dir', 'status' => 'ok', 'detail' => sprintf('mode %o owner %s group %s', $mode, $ownerName, $groupName), 'fix' => null];
    }
    $fix = function() use ($secretsDir) {
        $okMode = @chmod($secretsDir, 0700);
        $okOwner = @chown($secretsDir, 'www-data');
        $okGroup = @chgrp($secretsDir, 'www-data');
        if (!$okMode || !$okOwner || !$okGroup) {
            return ['ok' => false, 'detail' => "chmod/chown/chgrp on {$secretsDir} partially failed"];
        }
        return ['ok' => true, 'detail' => "set {$secretsDir} to 0700 www-data:www-data"];
    };
    return [
        'name' => 'secrets/ dir',
        'status' => 'mismatch',
        'detail' => sprintf('mode %o owner %s group %s; want 0700 www-data:www-data', $mode, $ownerName, $groupName),
        'fix' => $fix,
    ];
})();

// ---------------------------------------------------------------------------
// Execute.
// ---------------------------------------------------------------------------

$header = $checkOnly
    ? sprintf("Pluriverse host check — site=%s root=%s\n", $siteName, $root)
    : sprintf("Pluriverse host setup — site=%s root=%s\n", $siteName, $root);
echo $header;
echo str_repeat('=', strlen(trim($header))) . "\n";

$exitCode = 0;
$nginxTouched = false;

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
        if (str_contains($name, 'nginx vhost') || str_contains($name, 'vhost enabled')) {
            $nginxTouched = true;
        }
    } else {
        printf("           → fix FAILED: %s\n", $result['detail']);
        $exitCode = 1;
    }
}

if ($nginxTouched && !$checkOnly) {
    echo "\nReloading nginx (vhost changed)…\n";
    $rc = 1; $out = [];
    @exec('systemctl reload nginx 2>&1', $out, $rc);
    if ($rc === 0) {
        echo "  [ok]      nginx reloaded\n";
    } else {
        echo "  [error]   systemctl reload nginx failed (rc={$rc}): " . implode("\n", $out) . "\n";
        $exitCode = 1;
    }
}

echo "\n";
if ($exitCode === 0) {
    echo $checkOnly ? "All checks passed.\n" : "Host setup complete.\n";
} else {
    echo $checkOnly
        ? "One or more checks failed; re-run without --check to apply fixes, or address operator-intervention items first.\n"
        : "Setup completed with errors; re-run after addressing the messages above.\n";
}
exit($exitCode);
