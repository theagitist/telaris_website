<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/operators/check-name?n=<url-encoded-name>
 *
 * Lightweight availability probe for the operator-application form's Name
 * field. Returns { name, available }. One indexed SELECT against the
 * UNIQUE uniq_label index per call; the front-end debounces typing to
 * avoid hammering this endpoint with every keystroke.
 *
 * Rate limit 60 req/min/IP via APCu. Public read.
 *
 * Always returns 200 even when the name is invalid or taken; the
 * `available` boolean carries the answer. Reserving 4xx for actual API
 * failures (rate limit, malformed query) makes the polling JS simpler.
 */

require_once dirname(__DIR__, 2) . '/config.php';

if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_check_name:' . date('YmdHi') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(
            429,
            'rate_limited',
            'Too many name-availability checks from this IP this minute; retry shortly.',
            '/api/pluriverse/operators/check-name'
        );
        return;
    }
}

$name = isset($_GET['n']) ? trim((string)$_GET['n']) : '';

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($name === '' || mb_strlen($name) > 255) {
    echo json_encode([
        'name' => $name,
        'available' => false,
        'reason' => 'invalid',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

try {
    $available = db_label_available($name);
} catch (Throwable $e) {
    error_log('check-name: ' . $e->getMessage());
    echo json_encode([
        'name' => $name,
        'available' => false,
        'reason' => 'error',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return;
}

echo json_encode([
    'name' => $name,
    'available' => $available,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
