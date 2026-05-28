<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/schema/{name}.json (Pluriverse side, stage 5g)
 *
 * The instance-side galaxy envelope schema lives at the same path on each
 * Telaris instance (see /var/www/starmaps.polivoxia.ca/inc/federation/schema_handler.php).
 * The schema's `$id` is `https://www.telaris.ca/api/pluriverse/schema/envelope-1.0.json`,
 * so peers (and external validators) need a stable copy on the Pluriverse
 * coordination server too. This file serves that copy.
 *
 * The schema files under `inc/federation/schemas/` are a verbatim copy of the
 * instance-side canonical sources; on a schema change (new version), bump
 * BOTH repos together and add the new file to the allowlist below.
 *
 * Public, no signature; rate-limited 60 req/min/IP; Last-Modified +
 * If-Modified-Since → 304.
 */

const FEDERATION_SCHEMA_DIR = __DIR__ . '/schemas';

// Allowlist of servable schema files; one route entry in router.php per file.
const FEDERATION_SCHEMA_FILES = [
    'envelope-1.0.json' => 'envelope-1.0.json',
];

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$name = basename($path);
$schemaPath = '/api/pluriverse/schema/' . $name;

if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_schema:' . date('YmdHi') . ':' . $rateIp;
    $ok = false;
    $count = apcu_inc($bucket, 1, $ok, 120);
    if ($count !== false && (int)$count > 60) {
        federation_router_problem(429, 'rate_limited', 'Too many schema requests this minute; retry shortly.', $schemaPath);
        return;
    }
}

if (!isset(FEDERATION_SCHEMA_FILES[$name])) {
    federation_router_problem(404, 'not_found', 'No published schema named ' . $name . '.', $schemaPath);
    return;
}

$file = FEDERATION_SCHEMA_DIR . '/' . FEDERATION_SCHEMA_FILES[$name];
$body = @file_get_contents($file);
if ($body === false) {
    error_log('pluriverse/schema: cannot read ' . $file);
    federation_router_problem(500, 'schema_unavailable', 'The schema file could not be read.', $schemaPath);
    return;
}

$mtime = @filemtime($file) ?: time();
$lastModifiedHeader = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
if (is_string($ifModifiedSince) && $ifModifiedSince !== '') {
    $clientTs = @strtotime($ifModifiedSince);
    if ($clientTs !== false && $clientTs >= $mtime) {
        http_response_code(304);
        header('Last-Modified: ' . $lastModifiedHeader);
        header('Cache-Control: public, max-age=3600');
        return;
    }
}

http_response_code(200);
header('Content-Type: application/schema+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Last-Modified: ' . $lastModifiedHeader);
header('X-Content-Type-Options: nosniff');
echo $body;
