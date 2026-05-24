<?php
declare(strict_types=1);

/**
 * Common bootstrap. Every page require_onces this file once.
 *
 * Order:
 *   1. config.php (DB creds + path constants)  → loads inc/db.php transitively
 *   2. inc/locale.php
 *   3. Resolve REQUEST_URI → locale + page
 *   4. Ensure schema + seed default rows on first call (idempotent)
 *   5. Load the project_info row for the resolved locale
 *
 * Exposes via globals (the simplest accessor pattern for static-site-shaped
 * code; no DI container needed):
 *   $pluriverseLocale  string  e.g. 'en'
 *   $pluriversePage    string  e.g. 'manifest' or '' for home
 *   $pluriversePrefix  string  '/es' '/pt' '/fr' or '' for en (URL prefix)
 *   $pluriverseInfo    array   the project_info row, keyed by column name
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/locale.php';

global $pluriverseLocale, $pluriversePage, $pluriversePrefix, $pluriverseInfo;

$resolved = pluriverse_resolve_request((string)($_SERVER['REQUEST_URI'] ?? '/'));
$pluriverseLocale = $resolved['locale'];
$pluriversePage = $resolved['page'];
$pluriversePrefix = $resolved['prefix'];

db_ensure_project_info();
db_ensure_content_cache();

$pluriverseInfo = db_get_project_info_for_locale($pluriverseLocale);
if ($pluriverseInfo === []) {
    // Defensive: should never happen post-seed, but if a locale row is
    // somehow missing fall back to EN rather than blanking the page.
    $pluriverseInfo = db_get_project_info_for_locale('en');
    $pluriverseLocale = 'en';
    $pluriversePrefix = '';
}

/** Escape a string from $pluriverseInfo (or anywhere) for HTML output. */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Look up a chrome string from the current locale's project_info row. */
function info(string $key): string {
    global $pluriverseInfo;
    return (string)($pluriverseInfo[$key] ?? '');
}
