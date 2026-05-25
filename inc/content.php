<?php
declare(strict_types=1);

/**
 * Markdown rendering for Manifest / Privacy / Terms.
 *
 * Sources live under PLURIVERSE_DOCS_SRC (the docs repo on this host).
 * Per-locale: src/<slug>/01-<slug>.md for EN, src/<slug>-{es,pt,fr}/01-<slug>.md
 * for others. The same files produce the downloadable PDFs in docs/.
 *
 * Mirrors build.py's two-pass callout pipeline:
 *   1. Rewrite Obsidian `> [!kind] Title\n> body` blocks to marker pairs.
 *   2. CommonMark render.
 *   3. Replace marker pairs with <div class="callout callout-<kind>">.
 *
 * Cached per (slug, locale, source mtime) in the content_cache table.
 *
 * The docs list and instances list (semi-structured content with per-locale
 * captions) stay in PHP code here. When federation lands, the instances
 * list moves to the peers table; for now hardcoded keeps the migration
 * scope-tight.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;

/** Known callout kinds. Mirrors build.py. */
const PLURIVERSE_CALLOUT_KINDS = ['note', 'warning', 'tip', 'important', 'danger', 'example'];

/** Map URL slug → docs-repo slug. The Terms page lives at docs/tos/. */
const PLURIVERSE_DOC_SLUG_MAP = [
    'manifest' => 'manifest',
    'privacy' => 'privacy',
    'terms' => 'tos',
];

/**
 * Resolve the on-disk source path for a markdown doc.
 * Returns null if the file does not exist.
 */
function pluriverse_content_source_path(string $urlSlug, string $locale): ?string {
    $docSlug = PLURIVERSE_DOC_SLUG_MAP[$urlSlug] ?? null;
    if ($docSlug === null) return null;
    if (!defined('PLURIVERSE_DOCS_SRC')) return null;
    $dirSuffix = ($locale === 'en') ? '' : '-' . $locale;
    $path = PLURIVERSE_DOCS_SRC . '/' . $docSlug . $dirSuffix . '/01-' . $docSlug . '.md';
    return file_exists($path) ? $path : null;
}

/**
 * Render a doc to HTML body, ready to drop inside .prose. Returns '' if
 * the source is missing.
 */
function pluriverse_render_doc(string $urlSlug, string $locale): string {
    $path = pluriverse_content_source_path($urlSlug, $locale);
    if ($path === null) return '';
    $mtime = (int)filemtime($path);
    $cached = db_content_cache_get($urlSlug, $locale, $mtime);
    if ($cached !== null) return $cached;

    $src = (string)file_get_contents($path);
    // Strip leading H1; the page chrome carries the title.
    if (str_starts_with($src, '# ')) {
        $nl = strpos($src, "\n");
        $src = $nl !== false ? ltrim(substr($src, $nl + 1)) : '';
    }
    $withMarkers = pluriverse_transform_callouts($src);
    $rawHtml = pluriverse_commonmark()->convert($withMarkers)->getContent();
    $html = pluriverse_render_callouts($rawHtml);
    db_content_cache_put($urlSlug, $locale, $mtime, $html);
    return $html;
}

/** Lazy singleton CommonMark converter, matching build.py's enabled extensions. */
function pluriverse_commonmark(): CommonMarkConverter {
    static $converter = null;
    if ($converter !== null) return $converter;
    $env = new Environment([
        'html_input' => 'allow',
        'allow_unsafe_links' => false,
        'renderer' => ['soft_break' => "\n"],
    ]);
    $env->addExtension(new CommonMarkCoreExtension());
    $env->addExtension(new TableExtension());
    $env->addExtension(new StrikethroughExtension());
    $env->addExtension(new AutolinkExtension());
    $converter = new CommonMarkConverter([], $env);
    return $converter;
}

/**
 * First pass: rewrite Obsidian callout blocks (`> [!kind] Title\n> body`)
 * into HTML-comment marker pairs that survive markdown rendering.
 */
function pluriverse_transform_callouts(string $src): string {
    $lines = explode("\n", $src);
    $out = [];
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        if (str_starts_with($line, '>')) {
            $block = [];
            while ($i < $n && str_starts_with($lines[$i], '>')) {
                $block[] = ltrim(ltrim($lines[$i], '>'), ' ');
                $i++;
            }
            if ($block !== []) {
                if (preg_match('/^\s*\[!(?P<kind>[a-z]+)\](?:\s+(?P<title>.*))?$/', $block[0], $m)
                    && in_array($m['kind'], PLURIVERSE_CALLOUT_KINDS, true)) {
                    $kind = $m['kind'];
                    $title = trim($m['title'] ?? '') !== '' ? trim($m['title']) : ucfirst($kind);
                    $body = trim(implode("\n", array_slice($block, 1)));
                    $out[] = '<!--CALLOUT kind=' . $kind . ' title=' . $title . '-->';
                    $out[] = $body;
                    $out[] = '<!--CALLOUT-END-->';
                    continue;
                }
            }
            // Not a callout: emit as ordinary blockquote.
            foreach ($block as $orig) {
                $out[] = $orig !== '' ? ('> ' . $orig) : '>';
            }
            continue;
        }
        $out[] = $line;
        $i++;
    }
    return implode("\n", $out);
}

/**
 * Second pass: replace the marker pairs in the rendered HTML with the
 * callout markup. The body inside the markers is re-rendered as markdown.
 */
function pluriverse_render_callouts(string $html): string {
    return (string)preg_replace_callback(
        '/<!--CALLOUT kind=(?P<kind>[a-z]+) title=(?P<title>[^>]*)-->(?P<body>.*?)<!--CALLOUT-END-->/s',
        function (array $m): string {
            $kind = $m['kind'];
            $title = trim($m['title']);
            $body = trim($m['body']);
            $bodyHtml = $body !== '' ? pluriverse_commonmark()->convert($body)->getContent() : '';
            return '<div class="callout callout-' . h_safe($kind) . '">'
                . '<div class="callout-title">' . h_safe($title) . '</div>'
                . '<div class="callout-body">' . $bodyHtml . '</div>'
                . '</div>';
        },
        $html
    );
}

/** Local HTML escape; bootstrap.php defines a global h(); avoid the dep here. */
function h_safe(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Structured content: docs list + instances list.
// ---------------------------------------------------------------------------
//
// Per-locale (name, caption); structural fields (slug, color, url, host,
// draft flag) shared. Hardcoded here, same as build.py. When the
// federation peers table replaces the instances list, that part moves to
// the DB and this hardcoding goes with it.

function pluriverse_docs(string $locale): array {
    $base = [
        'manifest' => ['slug' => 'manifest', 'color' => '#00ffcc'],
        'editor-quick-start' => ['slug' => 'editor-quick-start', 'color' => '#fde047'],
        'editor-manual' => ['slug' => 'editor-manual', 'color' => '#86efac'],
        'admin-manual' => ['slug' => 'admin-manual', 'color' => '#7dd3fc', 'draft' => true],
    ];
    $i18n = [
        'en' => [
            'manifest' => ['name' => 'Manifest', 'caption' => 'Position statement. What Telaris is, what it refuses, the six principles that hold it together. Approximately five pages.'],
            'editor-quick-start' => ['name' => 'Editor Quick Start', 'caption' => 'Five steps to your first wormhole. For new editors who want the shortest possible path. Six pages.'],
            'editor-manual' => ['name' => 'Editor Manual', 'caption' => 'Complete reference for editors authoring galaxies, wormholes, keywords, portals, tours, and visitor views. Fifteen chapters, seventy-two pages.'],
            'admin-manual' => ['name' => 'Admin Manual', 'caption' => 'For operators running a Telaris instance: deployment, configuration, federation, key management, backups. Draft pending.'],
        ],
        'es' => [
            'manifest' => ['name' => 'Manifiesto', 'caption' => 'Declaración de posición. Qué es Telaris, qué rechaza, los seis principios que lo sostienen. Aproximadamente cinco páginas.'],
            'editor-quick-start' => ['name' => 'Inicio rápido para editoras', 'caption' => 'Cinco pasos hasta tu primer agujero de gusano. Para editoras que quieren el camino más corto posible. Seis páginas.'],
            'editor-manual' => ['name' => 'Manual del editor', 'caption' => 'Referencia completa para editoras que crean galaxias, agujeros de gusano, palabras clave, portales, recorridos y vistas de visitante. Quince capítulos, setenta y dos páginas.'],
            'admin-manual' => ['name' => 'Manual de administración', 'caption' => 'Para quienes operan una instancia de Telaris: despliegue, configuración, federación, gestión de claves, copias de seguridad. Borrador pendiente.'],
        ],
        'pt' => [
            'manifest' => ['name' => 'Manifesto', 'caption' => 'Declaração de posição. O que Telaris é, o que recusa, os seis princípios que o sustentam. Aproximadamente cinco páginas.'],
            'editor-quick-start' => ['name' => 'Início rápido para editoras', 'caption' => 'Cinco passos até seu primeiro buraco de minhoca. Para editoras que querem o caminho mais curto possível. Seis páginas.'],
            'editor-manual' => ['name' => 'Manual do editor', 'caption' => 'Referência completa para editoras que criam galáxias, buracos de minhoca, palavras-chave, portais, percursos e vistas de visitante. Quinze capítulos, setenta e duas páginas.'],
            'admin-manual' => ['name' => 'Manual de administração', 'caption' => 'Para quem opera uma instância de Telaris: implantação, configuração, federação, gestão de chaves, backups. Rascunho pendente.'],
        ],
        'fr' => [
            'manifest' => ['name' => 'Manifeste', 'caption' => "Déclaration de position. Ce que Telaris est, ce qu'il refuse, les six principes qui le tiennent ensemble. Environ cinq pages."],
            'editor-quick-start' => ['name' => "Démarrage rapide d'édition", 'caption' => "Cinq étapes jusqu'au premier trou de ver. Pour les comptes d'édition qui veulent le chemin le plus court possible. Six pages."],
            'editor-manual' => ['name' => "Manuel d'édition", 'caption' => 'Référence complète pour qui crée galaxies, trous de ver, mots-clés, portails, visites et vues de visite. Quinze chapitres, soixante-douze pages.'],
            'admin-manual' => ['name' => "Manuel d'administration", 'caption' => "Pour qui exploite une instance Telaris : déploiement, configuration, fédération, gestion des clés, sauvegardes. Brouillon à venir."],
        ],
    ];
    $rows = $i18n[$locale] ?? $i18n['en'];
    $out = [];
    foreach ($base as $key => $shared) {
        $row = $rows[$key] ?? $i18n['en'][$key];
        $out[] = array_merge($shared, $row);
    }
    return $out;
}

/**
 * Live list of federated instances published in the Pluriverse.
 *
 * Reads instances WHERE admission_status='published', ordered with the
 * is_highlighted rows first, then by label alphabetically. Editorial framing
 * is stored as a single string per row (the operator chose its language at
 * apply time); no per-locale framing variants exist on the schema, so the
 * $locale parameter is ignored for now.
 *
 * Color is derived from a stable hash of the hostname against a small
 * palette so each instance renders with a consistent accent without a
 * dedicated column.
 *
 * @return list<array{host:string,label:string,url:string,caption:string,color:string,is_highlighted:bool,tags:list<string>}>
 */
function pluriverse_instances(string $locale): array {
    try {
        $pdo = getDB();
        if (function_exists('db_ensure_instances_table')) {
            db_ensure_instances_table();
        }
        $rows = $pdo->query("
            SELECT label, hostname, url, editorial_framing, is_highlighted
            FROM instances
            WHERE admission_status = 'published'
            ORDER BY is_highlighted DESC, label
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('pluriverse_instances: ' . $e->getMessage());
        return [];
    }
    $palette = ['#7dd3fc', '#86efac', '#fdba74', '#fda4af', '#c4b5fd', '#fcd34d'];
    $out = [];
    foreach ($rows as $row) {
        $host = (string)($row['hostname'] ?? '');
        $idx = $host === '' ? 0 : abs(crc32($host)) % count($palette);
        $out[] = [
            'host' => $host,
            'label' => (string)($row['label'] ?? $host),
            'url' => (string)($row['url'] ?? ''),
            'caption' => (string)($row['editorial_framing'] ?? ''),
            'color' => $palette[$idx],
            'is_highlighted' => (bool)($row['is_highlighted'] ?? false),
            'tags' => [],
        ];
    }
    return $out;
}
