<?php
declare(strict_types=1);

/**
 * Pluriverse website front controller.
 *
 * Two dispatch paths land here:
 *   1. Federation API requests at /api/pluriverse/* are short-circuited to
 *      inc/federation/router.php BEFORE bootstrap, because bootstrap pulls
 *      in DB-backed locale resolution that federation endpoints don't need
 *      and don't want.
 *   2. Visitor page requests run through bootstrap (locale + page resolve,
 *      project_info load) and dispatch by $pluriversePage to a handler in
 *      inc/pages/.
 *
 * Nginx try_files routes everything without a literal file match through
 * here ($uri → /index.php?route=$uri&$args), so this is the single entry
 * point for both surfaces.
 */

// 1. Federation short-circuit. No bootstrap needed; the router reads
// secrets/pluriverse-coord.key directly via inc/federation/identity.php.
$reqPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/';
if (str_starts_with($reqPath, '/api/pluriverse/')) {
    require __DIR__ . '/inc/federation/router.php';
    exit;
}

// 2. Visitor pages.
require_once __DIR__ . '/inc/bootstrap.php';

// 2a. Multi-segment operator routes that don't fit the single-segment page
//     dispatch below. These render through the same chrome (head/footer) but
//     their handler does its own state work first (token consume, etc.).
//     The path is matched before locale-stripping because magic-link URLs
//     are emitted without a locale prefix; the handler picks the locale from
//     the instance row after token consumption.
if ($reqPath === '/operators/verify-magic-link' || $reqPath === '/operators/verify-magic-link/') {
    require __DIR__ . '/inc/pages/operators_verify.php';
    return;
}

$pageHandlers = [
    '' => 'home',
    'documentation' => 'documentation',
    'instances' => 'instances',
    'manifest' => 'manifest',
    'privacy' => 'privacy',
    'terms' => 'terms',
    'dashboard' => 'dashboard',
];

$handler = $pageHandlers[$pluriversePage] ?? null;
if ($handler === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    // Render the home chrome with a 404 body so the page is navigable.
    $pageTitle = '404';
    $bodyClass = 'page-404';
    $includeBg = false;
    require __DIR__ . '/inc/partials/head.php';
    echo '<main class="page"><h1 class="page-title">404</h1><p class="page-lead">' . h($pluriversePage) . '</p></main>';
    require __DIR__ . '/inc/partials/footer.php';
    return;
}

require __DIR__ . '/inc/pages/' . $handler . '.php';
