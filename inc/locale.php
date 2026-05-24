<?php
declare(strict_types=1);

/**
 * Locale + page resolution from REQUEST_URI.
 *
 * URL shapes (slugs stay English across all locales):
 *   /                       → en, ''            (home)
 *   /documentation/         → en, 'documentation'
 *   /es/                    → es, ''
 *   /es/manifest/           → es, 'manifest'
 *   /pt/terms/              → pt, 'terms'
 *
 * Any unrecognized first segment falls through to EN with the segment
 * treated as a page slug; the page renderer is responsible for emitting
 * 404 if the slug is unknown.
 */

require_once __DIR__ . '/db.php';

/**
 * @return array{locale: string, page: string, prefix: string}
 *   locale: en|es|pt|fr
 *   page:   '' for home, 'manifest', 'privacy', 'terms', 'documentation', 'instances', or anything else
 *   prefix: '' for EN, '/es' / '/pt' / '/fr' for others — useful for building same-page URLs across locales
 */
function pluriverse_resolve_request(string $requestUri): array {
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    // Normalize: strip leading slash, trim trailing slashes.
    $path = trim($path, '/');
    if ($path === '') {
        return ['locale' => 'en', 'page' => '', 'prefix' => ''];
    }
    $parts = explode('/', $path);
    $first = $parts[0];
    if (in_array($first, PLURIVERSE_LOCALES, true) && $first !== 'en') {
        array_shift($parts);
        $page = $parts[0] ?? '';
        return ['locale' => $first, 'page' => $page, 'prefix' => '/' . $first];
    }
    return ['locale' => 'en', 'page' => $first, 'prefix' => ''];
}

/** Build a same-page URL for a target locale, slugs stay English. */
function pluriverse_locale_url(string $page, string $locale): string {
    $prefix = ($locale === 'en') ? '' : '/' . $locale;
    if ($page === '') return $prefix . '/';
    return $prefix . '/' . $page . '/';
}
