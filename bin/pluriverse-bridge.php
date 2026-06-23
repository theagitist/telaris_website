<?php
declare(strict_types=1);

/**
 * Orrery -> Pluriverse bridge dispatcher.
 *
 * The Telaris Orrery (a separate program, runs as a non-web user) holds NO
 * database credentials to the Pluriverse and NO PII master key. Every time it
 * needs to touch Pluriverse-owned tables (the federation `instances` registry
 * and the `provisioning_jobs` queue) it invokes THIS script as www-data:
 *
 *     sudo -n -u www-data /usr/bin/php bin/pluriverse-bridge.php <func>   < <json-args>
 *
 * The function name is the first CLI arg; its arguments are a JSON array on
 * stdin (so there is no shell quoting and no injection surface). The result is
 * a single JSON object on stdout: {"ok":true,"result":<value>} on success, or
 * {"ok":false,"error":"..."} + a non-zero exit on failure. Only the whitelisted
 * functions below are reachable; this is the entire trust surface the Orrery
 * has into the Pluriverse, replacing the Orrery's former direct DB login.
 */

$root = dirname(__DIR__);
require $root . '/config.php';
if (!function_exists('getDB')) { require $root . '/inc/db.php'; }
require_once $root . '/inc/db_federation.php';

function bridge_out(bool $ok, $payload): never {
    if ($ok) {
        echo json_encode(['ok' => true, 'result' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    echo json_encode(['ok' => false, 'error' => (string)$payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(1);
}

$func = $argv[1] ?? '';
$raw  = stream_get_contents(STDIN);
$args = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($args)) { bridge_out(false, 'args must be a JSON array'); }

try {
    switch ($func) {
        case 'register_pending':
            // [label, email, locale, framing|null]
            $r = db_create_pending_federation_instance(
                (string)($args[0] ?? ''),
                (string)($args[1] ?? ''),
                (string)($args[2] ?? 'en'),
                (($args[3] ?? '') !== '') ? (string)$args[3] : null
            );
            bridge_out(true, $r === null ? 0 : (int)$r);
            // no break (bridge_out exits)

        case 'publish_key':
            // [instanceId, pubkeyHex]
            $r = db_publish_instance_key((int)($args[0] ?? 0), (string)($args[1] ?? ''));
            bridge_out(true, $r); // hostname or null

        case 'retract':
            // [label]
            bridge_out(true, db_retract_instance((string)($args[0] ?? '')));

        case 'claim_job':
            bridge_out(true, db_claim_provisioning_job()); // row array or null

        case 'finish_job':
            // [jobId, resultArray]
            db_finish_provisioning_job((int)($args[0] ?? 0), is_array($args[1] ?? null) ? $args[1] : []);
            bridge_out(true, true);

        case 'fail_job':
            // [jobId, attemptCount, maxAttempts, error]
            bridge_out(true, db_fail_provisioning_job(
                (int)($args[0] ?? 0),
                (int)($args[1] ?? 0),
                (int)($args[2] ?? 0),
                (string)($args[3] ?? '')
            ));

        case 'set_request_status':
            // [requestId|null, status, instanceId|null]
            db_set_request_status(
                isset($args[0]) ? (int)$args[0] : null,
                (string)($args[1] ?? ''),
                isset($args[2]) ? (int)$args[2] : null
            );
            bridge_out(true, true);

        default:
            bridge_out(false, "unknown function '$func'");
    }
} catch (Throwable $e) {
    // SQLSTATE / class only, never the message: bound values may include PII.
    $code = $e instanceof PDOException ? ('SQLSTATE ' . $e->getCode()) : get_class($e);
    error_log('pluriverse-bridge ' . $func . ': ' . $code);
    bridge_out(false, $func . ' failed (' . $code . ')');
}
