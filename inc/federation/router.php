<?php
declare(strict_types=1);

/**
 * Pluriverse-side federation request router.
 *
 * Entry point for every `/api/pluriverse/*` request on www.telaris.ca.
 * Dispatches to the matching endpoint handler. Called from index.php
 * before the visitor page handler dispatch runs.
 *
 * Endpoints land here as 2c → 2d → 2e → stage 2g+ work ships. At 2c the
 * only endpoint is GET /api/pluriverse/identity.
 *
 * Responses use RFC 9457 Problem Details (application/problem+json) for
 * errors. Same shape as the instance-side router so verifier code can
 * parse both surfaces with one decoder.
 */

require_once __DIR__ . '/problem.php';

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');
if ($path === '') $path = '/';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// HEAD is allowed on every unauthenticated GET route (cheap probing for
// monitoring + cache validation). Signed GET endpoints stay GET-only
// because RFC 9421 covers @method in the signature base — letting HEAD
// in would require instances to sign HEAD requests explicitly, which is
// out of scope for monitoring tools.
$routes = [
    '/api/pluriverse/identity' => ['methods' => ['GET', 'HEAD'], 'handler' => __DIR__ . '/identity_handler.php'],
    '/api/pluriverse/openapi.json' => ['methods' => ['GET', 'HEAD'], 'handler' => __DIR__ . '/openapi_handler.php'],
    '/api/pluriverse/operators/apply' => ['methods' => ['POST'], 'handler' => __DIR__ . '/apply_handler.php'],
    '/api/pluriverse/operators/status' => ['methods' => ['GET'], 'handler' => __DIR__ . '/status_handler.php'],
    '/api/pluriverse/peers.json' => ['methods' => ['GET', 'HEAD'], 'handler' => __DIR__ . '/peers_handler.php'],
    '/api/pluriverse/blacklist.json' => ['methods' => ['GET', 'HEAD'], 'handler' => __DIR__ . '/blacklist_handler.php'],
    '/api/pluriverse/key-events.json' => ['methods' => ['GET', 'HEAD'], 'handler' => __DIR__ . '/key_events_handler.php'],
    '/api/pluriverse/relay' => ['methods' => ['POST'], 'handler' => __DIR__ . '/relay_handler.php'],
    '/api/pluriverse/peer-blacklist-notice' => ['methods' => ['POST'], 'handler' => __DIR__ . '/peer_blacklist_notice_handler.php'],
    '/api/pluriverse/schema/envelope-1.0.json' => ['methods' => ['GET', 'HEAD'], 'handler' => __DIR__ . '/schema_handler.php'],
];

if (!isset($routes[$path])) {
    federation_router_problem(
        404,
        'not_found',
        'No federation endpoint at ' . $path,
        $path
    );
    return;
}

$route = $routes[$path];
if (!in_array($method, $route['methods'], true)) {
    header('Allow: ' . implode(', ', $route['methods']));
    federation_router_problem(
        405,
        'method_not_allowed',
        $method . ' is not allowed on ' . $path . '; allowed: ' . implode(', ', $route['methods']),
        $path
    );
    return;
}

// For HEAD requests, run the GET handler with output buffering, then
// drop the body and emit Content-Length so the response is bit-identical
// in headers to the GET response (RFC 9110 §9.3.2).
if ($method === 'HEAD') {
    ob_start();
    require $route['handler'];
    $body = ob_get_clean();
    if (!headers_sent()) {
        header('Content-Length: ' . strlen($body));
    }
    return;
}

require $route['handler'];
return;
