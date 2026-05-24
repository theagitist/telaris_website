<?php
declare(strict_types=1);

/**
 * Pluriverse website front controller.
 *
 * Bootstrap resolves REQUEST_URI → ($pluriverseLocale, $pluriversePage).
 * This file maps $pluriversePage to a handler under inc/pages/ and
 * dispatches to it. Unknown pages return 404.
 */

require_once __DIR__ . '/inc/bootstrap.php';

$pageHandlers = [
    '' => 'home',
    'documentation' => 'documentation',
    'instances' => 'instances',
    'manifest' => 'manifest',
    'privacy' => 'privacy',
    'terms' => 'terms',
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
