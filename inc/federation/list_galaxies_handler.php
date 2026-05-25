<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/operators/list-galaxies
 *
 * Server-side proxy for the operator-application form's "Load galaxies"
 * button. Takes a JSON body { url: "https://<instance>" }, fetches
 * <url>/api/pluriverse/galaxies.json from the instance over HTTPS, and
 * relays the parsed list back to the browser. Exists so the browser does
 * not have to make a cross-origin request to an arbitrary instance, and
 * so we can apply uniform timeouts / size caps.
 *
 * Returns:
 *   200 { protocol_version, galaxies: [{slug, name, tagline}, ...] }
 *   422 if the URL is malformed / not https
 *   422 if the instance does not serve /api/pluriverse/galaxies.json
 *   429 on rate-limit
 *
 * Rate limit 20 req/hour/IP via APCu. Public; the same data is
 * world-readable on the instance.
 */

require_once dirname(__DIR__, 2) . '/config.php';

if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_list_galaxies:' . date('YmdH') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 3700);
    if ($count !== false && (int)$count > 20) {
        federation_router_problem(
            429,
            'rate_limited',
            'Too many galaxies-list proxy requests from this IP this hour; retry within an hour.',
            '/api/pluriverse/operators/list-galaxies'
        );
        return;
    }
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    federation_router_problem(400, 'empty_body', 'Request body is empty; expected JSON.', '/api/pluriverse/operators/list-galaxies');
    return;
}
if (strlen($raw) > 2048) {
    federation_router_problem(413, 'body_too_large', 'Request body exceeds 2 KB.', '/api/pluriverse/operators/list-galaxies');
    return;
}
try {
    $body = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    federation_router_problem(400, 'invalid_json', 'Request body is not valid JSON: ' . $e->getMessage(), '/api/pluriverse/operators/list-galaxies');
    return;
}
$url = is_array($body) ? trim((string)($body['url'] ?? '')) : '';
if ($url === '' || !preg_match('#^https://#', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
    federation_router_problem(422, 'invalid_url', 'url must be a valid https:// URL', '/api/pluriverse/operators/list-galaxies');
    return;
}

$galaxiesUrl = rtrim($url, '/') . '/api/pluriverse/galaxies.json';

if (!function_exists('curl_init')) {
    federation_router_problem(500, 'curl_missing', 'curl extension required.', '/api/pluriverse/operators/list-galaxies');
    return;
}
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $galaxiesUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: Pluriverse/1.0 (+https://www.telaris.ca/)',
    ],
]);
$respBody = curl_exec($ch);
if ($respBody === false) {
    $err = curl_error($ch);
    curl_close($ch);
    federation_router_problem(422, 'fetch_failed', 'Could not reach the instance: ' . $err, '/api/pluriverse/operators/list-galaxies');
    return;
}
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 404) {
    federation_router_problem(422, 'galaxies_endpoint_missing', "The instance at {$url} does not serve /api/pluriverse/galaxies.json. The operator can list slugs manually.", '/api/pluriverse/operators/list-galaxies');
    return;
}
if ($httpCode !== 200) {
    federation_router_problem(422, 'fetch_failed', "Instance returned HTTP {$httpCode}.", '/api/pluriverse/operators/list-galaxies');
    return;
}
if (strlen((string)$respBody) > 524288) {
    federation_router_problem(422, 'response_too_large', 'Galaxies listing exceeds 512 KB.', '/api/pluriverse/operators/list-galaxies');
    return;
}

try {
    $parsed = json_decode((string)$respBody, true, 6, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    federation_router_problem(422, 'invalid_response', 'Instance returned non-JSON: ' . $e->getMessage(), '/api/pluriverse/operators/list-galaxies');
    return;
}
if (!is_array($parsed) || !isset($parsed['galaxies']) || !is_array($parsed['galaxies'])) {
    federation_router_problem(422, 'invalid_response', 'Instance response missing galaxies array.', '/api/pluriverse/operators/list-galaxies');
    return;
}

$out = [];
foreach ($parsed['galaxies'] as $g) {
    if (!is_array($g)) continue;
    $slug = isset($g['slug']) && is_string($g['slug']) ? trim($g['slug']) : '';
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,127}$/', $slug)) continue;
    $name = isset($g['name']) && is_string($g['name']) ? trim($g['name']) : $slug;
    $tagline = isset($g['tagline']) && is_string($g['tagline']) ? trim($g['tagline']) : '';
    if (mb_strlen($name) > 255) $name = mb_substr($name, 0, 255);
    if (mb_strlen($tagline) > 512) $tagline = mb_substr($tagline, 0, 512);
    $out[] = ['slug' => $slug, 'name' => $name, 'tagline' => $tagline];
    if (count($out) >= 1024) break;
}

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'protocol_version' => (string)($parsed['protocol_version'] ?? '1.0'),
    'galaxies' => $out,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
