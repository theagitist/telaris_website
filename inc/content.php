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
 * Curated, visitor-facing changelog highlights.
 *
 * Not the full per-version engineering CHANGELOG (that lives in the project
 * vault). This is a short, plain-language list of the changes that alter what
 * visitors, editors, and operators can actually do, newest first. Each entry
 * carries a shared order key plus a per-locale date label, title, and body.
 *
 * Hardcoded here, same pattern as pluriverse_docs(): add a new key at the top
 * of $order and a matching row in every locale block when something ships.
 *
 * @return list<array{key:string,date:string,title:string,body:string}>
 */
function pluriverse_changelog(string $locale): array {
    // Newest first.
    $order = [
        'mobile-views',
        'editor-switch',
        'galaxy-name-in-view',
        'two-d-zoom',
        'media-toggle',
        'self-enroll',
        'cross-galaxy-keywords',
        'public-keyword-map',
        'consent-terms',
        'embedded-media',
        'federation',
        'four-languages',
        'downloadable-docs',
        'two-d-view',
        'multigalaxy',
        'rich-media',
        'discovery',
        'backups',
        'sound',
        'themes',
        'search',
        'multi-editor',
        'multiple-galaxies',
        'beginning',
    ];
    $i18n = [
        'en' => [
            'mobile-views' => [
                'date' => 'June 2026',
                'title' => 'The editor and admin screens now work on a phone',
                'body' => 'The screens where editors manage wormholes and where operators run an instance were built for a wide display. They now adapt to a phone: long lists become stacked cards you can read without scrolling sideways, toolbars and search fold to fit, and buttons are easier to tap. The 3D view already worked on a phone, and nothing changes on a computer.',
            ],
            'editor-switch' => [
                'date' => 'June 2026',
                'title' => 'Turn editing on or off, from the whole instance down to one editor',
                'body' => 'Whoever runs an instance can now switch editing on or off at four levels: the whole instance, a cluster, a single galaxy, or one editor. This helps when an activity ends and you want to stop new changes while keeping every account and everything people made. The switches are on by default, and turning one off never deletes anything.',
            ],
            'galaxy-name-in-view' => [
                'date' => 'June 2026',
                'title' => 'The galaxy\'s name when several galaxies share a view',
                'body' => 'When a scene holds wormholes from more than one galaxy, each wormhole now shows its galaxy\'s name alongside its own, both when you hover over it and when you open it. With a single galaxy in view nothing changes, so the name appears only when it helps tell the galaxies apart. This works in both the 3D scene and the 2D layout.',
            ],
            'two-d-zoom' => [
                'date' => 'June 2026',
                'title' => 'Zoom, pan, and see every wormhole in the 2D layout',
                'body' => 'The flat 2D layout now lets you zoom in and out, drag to move around, and fit the whole galaxy back into view with one button. Large galaxies that used to crowd or hide wormholes now lay every tile out clearly, and choosing galaxies from the list at the bottom right dims the rest here too.',
            ],
            'media-toggle' => [
                'date' => 'June 2026',
                'title' => 'Operators can switch embedded media off',
                'body' => 'Each instance can now turn off embedded media pages from its settings. Wormholes that already carry a media page keep showing it; new wormholes simply offer the classic media section. The setting is on by default, so nothing changes unless an operator chooses to switch it off.',
            ],
            'self-enroll' => [
                'date' => 'June 2026',
                'title' => 'Sign yourself up as an editor',
                'body' => 'Where an operator allows it, you can request an editor account directly from the sign-in screen and confirm it through a one-time link sent to your email. There is no password to set up: each visit signs you in through a fresh link.',
            ],
            'cross-galaxy-keywords' => [
                'date' => 'June 2026',
                'title' => 'Connections that cross galaxies',
                'body' => 'Wormholes in different galaxies that share close keywords can now be drawn together, so a thread of meaning can be followed across the whole archive, not only inside one galaxy. Each operator decides whether to enable it.',
            ],
            'embedded-media' => [
                'date' => 'May 2026',
                'title' => 'Freeform media inside a wormhole',
                'body' => 'A wormhole can now hold a freeform page of images, text, and links arranged by hand, alongside or instead of the classic media. Editors manage these pages from their own tab in the editor.',
            ],
            'federation' => [
                'date' => 'May 2026',
                'title' => 'Instances that share their galaxies',
                'body' => 'Independent Telaris instances can now trust one another, publish galaxies, and mirror each other\'s published work. A community keeps full control: trust and sharing can be withdrawn at any time, and a withdrawal removes the shared copy.',
            ],
            'four-languages' => [
                'date' => 'May 2026',
                'title' => 'The whole archive in four languages',
                'body' => 'Telaris now speaks English, Spanish, Portuguese, and French throughout, with no language treated as a fallback for another. Switch language from the bar at the top of any page.',
            ],
            'public-keyword-map' => [
                'date' => 'June 2026',
                'title' => 'Explore a galaxy\'s keywords without an account',
                'body' => 'A galaxy\'s keyword map, the web of words that ties its wormholes together, can now be opened and read by anyone, with no sign-in. Visitors can look but not change it; editing still needs an editor account.',
            ],
            'consent-terms' => [
                'date' => 'June 2026',
                'title' => 'A clear consent step and plain-language terms',
                'body' => 'Editors now agree to the Terms of Use and the Privacy Policy the first time they sign in, and both documents were rewritten in plain language. You can read them any time from the links at the foot of every page.',
            ],
            'downloadable-docs' => [
                'date' => 'May 2026',
                'title' => 'Documentation you can download',
                'body' => 'The Manifest, an Editor Quick Start, and a full Editor Manual are available as PDFs, each in all four languages, from the Documentation page.',
            ],
            'two-d-view' => [
                'date' => 'May 2026',
                'title' => 'A flat 2D layout, not only 3D',
                'body' => 'Alongside the 3D scene, a galaxy can offer a calm 2D layout where each wormhole is a small tile on a grid and connections are drawn as lines. A switch at the top lets visitors move between the two, and the choice is remembered.',
            ],
            'multigalaxy' => [
                'date' => 'May 2026',
                'title' => 'See several galaxies at once',
                'body' => 'Wormholes from more than one galaxy can now share a single scene, gathered into a cluster or a family, with the connections that cross between them drawn as bridges. Each galaxy keeps its own look so its wormholes stay recognizable.',
            ],
            'rich-media' => [
                'date' => 'May 2026',
                'title' => 'Images, audio, video, and PDFs in a wormhole',
                'body' => 'A wormhole can carry an image, an audio clip, a video, or a PDF as its main piece, shown right inside its window. Uploaded files are tidied automatically so they load quickly without losing quality.',
            ],
            'discovery' => [
                'date' => 'April 2026',
                'title' => 'Guided tours and gentle discovery',
                'body' => 'A galaxy can run a slow guided tour through its wormholes, drift to a random one when a visitor has been still for a while, and show a strip of clickable keywords that highlight related wormholes. Each of these is optional and stays off until an editor turns it on.',
            ],
            'backups' => [
                'date' => 'April 2026',
                'title' => 'Backups, snapshots, and restore for operators',
                'body' => 'Whoever runs an instance can take full snapshots on a schedule or on demand, download them, and restore from one, and can export galaxies and editors to a portable file to move between instances.',
            ],
            'themes' => [
                'date' => 'February 2026',
                'title' => 'Each galaxy with its own look',
                'body' => 'Galaxies can wear different visual themes, so the same archive can hold a stark abstract space, a plain simple one, or other looks, each with its own background, lighting, and wormhole icons.',
            ],
            'sound' => [
                'date' => 'February 2026',
                'title' => 'Sound and atmosphere',
                'body' => 'A galaxy can carry a soft, generative soundscape and small interactive sounds as you move through it, with a single control in the navigation panel to turn all sound on or off.',
            ],
            'search' => [
                'date' => 'February 2026',
                'title' => 'Search within a galaxy',
                'body' => 'A search box in the navigation panel filters the wormholes of a galaxy as you type, so a large galaxy stays easy to move around. The navigation panel itself was redesigned around it.',
            ],
            'multi-editor' => [
                'date' => 'February 2026',
                'title' => 'Editors, each with their own galaxies',
                'body' => 'An instance can have many editors, and each one sees and tends only the galaxies assigned to them, while an operator oversees them all. This is what lets several people and communities share one instance without stepping on each other.',
            ],
            'multiple-galaxies' => [
                'date' => 'February 2026',
                'title' => 'More than one galaxy',
                'body' => 'An instance can hold many separate galaxies rather than a single network, each with its own wormholes and keywords, so different threads of knowledge can live side by side.',
            ],
            'beginning' => [
                'date' => 'January 2026',
                'title' => 'Telaris begins',
                'body' => 'The first version of Telaris: a single 3D network of wormholes you can fly through, linked by shared meaning, and open one by one. Everything since has grown from this.',
            ],
        ],
        'es' => [
            'mobile-views' => [
                'date' => 'Junio de 2026',
                'title' => 'Las pantallas de edición y administración ya funcionan en el teléfono',
                'body' => 'Las pantallas donde editas agujeros de gusano y donde se administra una instancia estaban pensadas para una pantalla ancha. Ahora se adaptan al teléfono: las listas largas se convierten en tarjetas apiladas que se leen sin desplazarse de lado, las barras de herramientas y la búsqueda se reacomodan, y los botones son más fáciles de tocar. La vista 3D ya funcionaba en el teléfono, y en la computadora no cambia nada.',
            ],
            'editor-switch' => [
                'date' => 'Junio de 2026',
                'title' => 'Activa o desactiva la edición, desde toda la instancia hasta una sola editora',
                'body' => 'Quien opera una instancia ahora puede activar o desactivar la edición en cuatro niveles: toda la instancia, un grupo, una sola galaxia o una editora. Es útil cuando una actividad termina y quieres detener los cambios nuevos conservando todas las cuentas y todo lo que se creó. Los controles están activados por defecto, y desactivar uno nunca borra nada.',
            ],
            'galaxy-name-in-view' => [
                'date' => 'Junio de 2026',
                'title' => 'El nombre de la galaxia cuando varias comparten una vista',
                'body' => 'Cuando una escena reúne agujeros de gusano de más de una galaxia, cada agujero de gusano ahora muestra el nombre de su galaxia junto al propio, tanto al pasar el cursor por encima como al abrirlo. Con una sola galaxia a la vista nada cambia, así que el nombre aparece solo cuando ayuda a distinguir las galaxias. Funciona tanto en la escena en 3D como en la vista en 2D.',
            ],
            'two-d-zoom' => [
                'date' => 'Junio de 2026',
                'title' => 'Acerca, desplaza y ve todos los agujeros de gusano en la vista 2D',
                'body' => 'La vista plana en 2D ahora permite acercar y alejar, arrastrar para moverte y volver a encuadrar toda la galaxia con un botón. Las galaxias grandes que antes amontonaban u ocultaban agujeros de gusano ahora disponen cada ficha con claridad, y elegir galaxias en la lista de abajo a la derecha también atenúa el resto aquí.',
            ],
            'media-toggle' => [
                'date' => 'Junio de 2026',
                'title' => 'Quien opera puede desactivar el contenido multimedia incrustado',
                'body' => 'Cada instancia ahora puede desactivar las páginas de contenido multimedia incrustado desde sus ajustes. Los agujeros de gusano que ya tienen una página la siguen mostrando; los nuevos solo ofrecen la sección de medios clásica. El ajuste está activado por defecto, así que nada cambia salvo que quien opera decida desactivarlo.',
            ],
            'self-enroll' => [
                'date' => 'Junio de 2026',
                'title' => 'Date de alta como editora',
                'body' => 'Donde quien opera lo permita, puedes solicitar una cuenta de edición directamente desde la pantalla de inicio de sesión y confirmarla con un enlace de un solo uso enviado a tu correo. No hay contraseña que configurar: cada visita inicia sesión con un enlace nuevo.',
            ],
            'cross-galaxy-keywords' => [
                'date' => 'Junio de 2026',
                'title' => 'Conexiones que cruzan galaxias',
                'body' => 'Los agujeros de gusano de galaxias distintas que comparten palabras clave cercanas ahora pueden enlazarse, de modo que un hilo de sentido se puede seguir por todo el archivo, no solo dentro de una galaxia. Cada quien que opera decide si lo activa.',
            ],
            'embedded-media' => [
                'date' => 'Mayo de 2026',
                'title' => 'Contenido libre dentro de un agujero de gusano',
                'body' => 'Un agujero de gusano ahora puede contener una página libre de imágenes, texto y enlaces dispuestos a mano, junto a la sección de medios clásica o en su lugar. Las editoras gestionan estas páginas desde su propia pestaña en el editor.',
            ],
            'federation' => [
                'date' => 'Mayo de 2026',
                'title' => 'Instancias que comparten sus galaxias',
                'body' => 'Las instancias independientes de Telaris ahora pueden confiar unas en otras, publicar galaxias y reflejar el trabajo publicado de las demás. La comunidad conserva el control total: la confianza y lo compartido se pueden retirar en cualquier momento, y al retirarlos se elimina la copia compartida.',
            ],
            'four-languages' => [
                'date' => 'Mayo de 2026',
                'title' => 'Todo el archivo en cuatro idiomas',
                'body' => 'Telaris ahora habla inglés, español, portugués y francés de principio a fin, sin tratar ningún idioma como respaldo de otro. Cambia de idioma desde la barra superior de cualquier página.',
            ],
            'public-keyword-map' => [
                'date' => 'Junio de 2026',
                'title' => 'Explora las palabras clave de una galaxia sin cuenta',
                'body' => 'El mapa de palabras clave de una galaxia, la red de palabras que enlaza sus agujeros de gusano, ahora se puede abrir y leer sin iniciar sesión. Quien visita puede mirarlo pero no cambiarlo; editar sigue necesitando una cuenta de edición.',
            ],
            'consent-terms' => [
                'date' => 'Junio de 2026',
                'title' => 'Un paso de consentimiento claro y términos en lenguaje sencillo',
                'body' => 'Las editoras ahora aceptan los Términos de Uso y la Política de Privacidad la primera vez que inician sesión, y ambos documentos se reescribieron en lenguaje sencillo. Puedes leerlos cuando quieras desde los enlaces al pie de cada página.',
            ],
            'downloadable-docs' => [
                'date' => 'Mayo de 2026',
                'title' => 'Documentación que puedes descargar',
                'body' => 'El Manifiesto, un Inicio rápido para editoras y un Manual del editor completo están disponibles en PDF, cada uno en los cuatro idiomas, desde la página de Documentación.',
            ],
            'two-d-view' => [
                'date' => 'Mayo de 2026',
                'title' => 'Una vista plana en 2D, no solo en 3D',
                'body' => 'Junto a la escena en 3D, una galaxia puede ofrecer una vista en 2D tranquila donde cada agujero de gusano es una pequeña ficha en una cuadrícula y las conexiones se dibujan como líneas. Un selector arriba permite cambiar entre ambas, y la elección se recuerda.',
            ],
            'multigalaxy' => [
                'date' => 'Mayo de 2026',
                'title' => 'Ve varias galaxias a la vez',
                'body' => 'Los agujeros de gusano de más de una galaxia ahora pueden compartir una sola escena, reunidos en un grupo o una familia, con las conexiones que las cruzan dibujadas como puentes. Cada galaxia conserva su propio aspecto para que sus agujeros de gusano sigan siendo reconocibles.',
            ],
            'rich-media' => [
                'date' => 'Mayo de 2026',
                'title' => 'Imágenes, audio, video y PDF dentro de un agujero de gusano',
                'body' => 'Un agujero de gusano puede llevar una imagen, un audio, un video o un PDF como pieza principal, mostrado dentro de su ventana. Los archivos subidos se optimizan de forma automática para que carguen rápido sin perder calidad.',
            ],
            'discovery' => [
                'date' => 'Abril de 2026',
                'title' => 'Recorridos guiados y descubrimiento sutil',
                'body' => 'Una galaxia puede ofrecer un recorrido guiado y pausado por sus agujeros de gusano, acercarse a uno al azar cuando quien visita lleva un rato sin moverse, y mostrar una tira de palabras clave que al tocarlas resaltan agujeros de gusano relacionados. Cada una de estas opciones es opcional y está desactivada hasta que una editora la activa.',
            ],
            'backups' => [
                'date' => 'Abril de 2026',
                'title' => 'Copias de seguridad, instantáneas y restauración para quien opera',
                'body' => 'Quien opera una instancia puede crear instantáneas completas de forma programada o cuando quiera, descargarlas y restaurar desde una, y puede exportar galaxias y cuentas de edición a un archivo portátil para moverlas entre instancias.',
            ],
            'themes' => [
                'date' => 'Febrero de 2026',
                'title' => 'Cada galaxia con su propio aspecto',
                'body' => 'Las galaxias pueden vestir distintos temas visuales, así un mismo archivo puede contener un espacio abstracto y austero, uno sencillo y llano, u otros aspectos, cada uno con su propio fondo, iluminación e iconos de agujero de gusano.',
            ],
            'sound' => [
                'date' => 'Febrero de 2026',
                'title' => 'Sonido y atmósfera',
                'body' => 'Una galaxia puede llevar un paisaje sonoro suave y generativo y pequeños sonidos al moverte por ella, con un único control en el panel de navegación para activar o silenciar todo el sonido.',
            ],
            'search' => [
                'date' => 'Febrero de 2026',
                'title' => 'Busca dentro de una galaxia',
                'body' => 'Un buscador en el panel de navegación filtra los agujeros de gusano de una galaxia a medida que escribes, así una galaxia grande sigue siendo fácil de recorrer. El propio panel de navegación se rediseñó en torno a esto.',
            ],
            'multi-editor' => [
                'date' => 'Febrero de 2026',
                'title' => 'Editoras, cada una con sus propias galaxias',
                'body' => 'Una instancia puede tener muchas editoras, y cada una ve y cuida solo las galaxias que tiene asignadas, mientras quien opera las supervisa todas. Esto es lo que permite que varias personas y comunidades compartan una instancia sin pisarse.',
            ],
            'multiple-galaxies' => [
                'date' => 'Febrero de 2026',
                'title' => 'Más de una galaxia',
                'body' => 'Una instancia puede contener muchas galaxias separadas en lugar de una sola red, cada una con sus propios agujeros de gusano y palabras clave, así distintos hilos de conocimiento pueden convivir lado a lado.',
            ],
            'beginning' => [
                'date' => 'Enero de 2026',
                'title' => 'Comienza Telaris',
                'body' => 'La primera versión de Telaris: una sola red en 3D de agujeros de gusano por la que puedes volar, enlazada por sentido compartido, y que se abre uno a uno. Todo lo demás ha crecido a partir de aquí.',
            ],
        ],
        'pt' => [
            'mobile-views' => [
                'date' => 'Junho de 2026',
                'title' => 'As telas de edição e administração agora funcionam no celular',
                'body' => 'As telas onde você edita buracos de minhoca e onde uma instância é administrada foram feitas para uma tela larga. Agora elas se adaptam ao celular: listas longas viram cartões empilhados que se leem sem rolar para o lado, as barras de ferramentas e a busca se reorganizam, e os botões ficam mais fáceis de tocar. A vista 3D já funcionava no celular, e no computador nada muda.',
            ],
            'editor-switch' => [
                'date' => 'Junho de 2026',
                'title' => 'Ative ou desative a edição, da instância inteira até uma só editora',
                'body' => 'Quem opera uma instância agora pode ativar ou desativar a edição em quatro níveis: a instância inteira, um grupo, uma só galáxia ou uma editora. É útil quando uma atividade termina e você quer parar as mudanças novas mantendo todas as contas e tudo o que foi criado. Os controles estão ativados por padrão, e desativar um nunca apaga nada.',
            ],
            'galaxy-name-in-view' => [
                'date' => 'Junho de 2026',
                'title' => 'O nome da galáxia quando várias compartilham uma vista',
                'body' => 'Quando uma cena reúne buracos de minhoca de mais de uma galáxia, cada buraco de minhoca agora mostra o nome da sua galáxia junto ao próprio, tanto ao passar o cursor por cima como ao abri-lo. Com uma só galáxia à vista nada muda, então o nome aparece só quando ajuda a distinguir as galáxias. Funciona tanto na cena em 3D como na vista em 2D.',
            ],
            'two-d-zoom' => [
                'date' => 'Junho de 2026',
                'title' => 'Aproxime, desloque e veja todos os buracos de minhoca na vista 2D',
                'body' => 'A vista plana em 2D agora permite aproximar e afastar, arrastar para se mover e reenquadrar a galáxia inteira com um botão. As galáxias grandes que antes amontoavam ou escondiam buracos de minhoca agora dispõem cada ficha com clareza, e escolher galáxias na lista no canto inferior direito também atenua o resto aqui.',
            ],
            'media-toggle' => [
                'date' => 'Junho de 2026',
                'title' => 'Quem opera pode desativar o conteúdo de mídia incorporado',
                'body' => 'Cada instância agora pode desativar as páginas de mídia incorporada pelos seus ajustes. Os buracos de minhoca que já têm uma página continuam a exibi-la; os novos apenas oferecem a seção de mídia clássica. O ajuste está ativado por padrão, então nada muda a menos que quem opera decida desativá-lo.',
            ],
            'self-enroll' => [
                'date' => 'Junho de 2026',
                'title' => 'Cadastre-se como editora',
                'body' => 'Onde quem opera permitir, você pode solicitar uma conta de edição direto na tela de entrada e confirmá-la por um link de uso único enviado ao seu email. Não há senha para configurar: cada visita entra por um link novo.',
            ],
            'cross-galaxy-keywords' => [
                'date' => 'Junho de 2026',
                'title' => 'Conexões que cruzam galáxias',
                'body' => 'Buracos de minhoca de galáxias diferentes que compartilham palavras-chave próximas agora podem ser ligados, de modo que um fio de sentido pode ser seguido por todo o arquivo, não só dentro de uma galáxia. Quem opera decide se ativa isso.',
            ],
            'embedded-media' => [
                'date' => 'Maio de 2026',
                'title' => 'Conteúdo livre dentro de um buraco de minhoca',
                'body' => 'Um buraco de minhoca agora pode conter uma página livre de imagens, texto e links dispostos à mão, junto à seção de mídia clássica ou no lugar dela. As editoras gerenciam essas páginas pela sua própria aba no editor.',
            ],
            'federation' => [
                'date' => 'Maio de 2026',
                'title' => 'Instâncias que compartilham suas galáxias',
                'body' => 'As instâncias independentes de Telaris agora podem confiar umas nas outras, publicar galáxias e espelhar o trabalho publicado das demais. A comunidade mantém o controle total: a confiança e o que é compartilhado podem ser retirados a qualquer momento, e ao retirá-los a cópia compartilhada é removida.',
            ],
            'four-languages' => [
                'date' => 'Maio de 2026',
                'title' => 'Todo o arquivo em quatro idiomas',
                'body' => 'Telaris agora fala inglês, espanhol, português e francês do início ao fim, sem tratar nenhum idioma como reserva de outro. Troque de idioma pela barra no topo de qualquer página.',
            ],
            'public-keyword-map' => [
                'date' => 'Junho de 2026',
                'title' => 'Explore as palavras-chave de uma galáxia sem conta',
                'body' => 'O mapa de palavras-chave de uma galáxia, a rede de palavras que liga seus buracos de minhoca, agora pode ser aberto e lido sem entrar. Quem visita pode olhar mas não alterar; editar ainda exige uma conta de edição.',
            ],
            'consent-terms' => [
                'date' => 'Junho de 2026',
                'title' => 'Um passo de consentimento claro e termos em linguagem simples',
                'body' => 'As editoras agora aceitam os Termos de Uso e a Política de Privacidade na primeira vez que entram, e ambos os documentos foram reescritos em linguagem simples. Você pode lê-los quando quiser pelos links no rodapé de cada página.',
            ],
            'downloadable-docs' => [
                'date' => 'Maio de 2026',
                'title' => 'Documentação que você pode baixar',
                'body' => 'O Manifesto, um Início rápido para editoras e um Manual do editor completo estão disponíveis em PDF, cada um nos quatro idiomas, pela página de Documentação.',
            ],
            'two-d-view' => [
                'date' => 'Maio de 2026',
                'title' => 'Uma vista plana em 2D, não só em 3D',
                'body' => 'Junto à cena em 3D, uma galáxia pode oferecer uma vista em 2D tranquila onde cada buraco de minhoca é uma pequena ficha numa grade e as conexões são desenhadas como linhas. Um seletor no topo permite alternar entre as duas, e a escolha é lembrada.',
            ],
            'multigalaxy' => [
                'date' => 'Maio de 2026',
                'title' => 'Veja várias galáxias ao mesmo tempo',
                'body' => 'Os buracos de minhoca de mais de uma galáxia agora podem compartilhar uma só cena, reunidos num grupo ou numa família, com as conexões que as cruzam desenhadas como pontes. Cada galáxia mantém o próprio visual para que seus buracos de minhoca continuem reconhecíveis.',
            ],
            'rich-media' => [
                'date' => 'Maio de 2026',
                'title' => 'Imagens, áudio, vídeo e PDF dentro de um buraco de minhoca',
                'body' => 'Um buraco de minhoca pode levar uma imagem, um áudio, um vídeo ou um PDF como peça principal, exibido dentro da sua janela. Os arquivos enviados são otimizados de forma automática para carregar rápido sem perder qualidade.',
            ],
            'discovery' => [
                'date' => 'Abril de 2026',
                'title' => 'Percursos guiados e descoberta sutil',
                'body' => 'Uma galáxia pode oferecer um percurso guiado e pausado por seus buracos de minhoca, aproximar-se de um ao acaso quando quem visita fica um tempo parado, e mostrar uma faixa de palavras-chave que ao tocá-las destacam buracos de minhoca relacionados. Cada uma dessas opções é opcional e fica desativada até que uma editora a ative.',
            ],
            'backups' => [
                'date' => 'Abril de 2026',
                'title' => 'Backups, instantâneos e restauração para quem opera',
                'body' => 'Quem opera uma instância pode criar instantâneos completos de forma agendada ou quando quiser, baixá-los e restaurar a partir de um, e pode exportar galáxias e contas de edição para um arquivo portátil para movê-las entre instâncias.',
            ],
            'themes' => [
                'date' => 'Fevereiro de 2026',
                'title' => 'Cada galáxia com o próprio visual',
                'body' => 'As galáxias podem vestir temas visuais distintos, assim um mesmo arquivo pode conter um espaço abstrato e austero, um simples e plano, ou outros visuais, cada um com o próprio fundo, iluminação e ícones de buraco de minhoca.',
            ],
            'sound' => [
                'date' => 'Fevereiro de 2026',
                'title' => 'Som e atmosfera',
                'body' => 'Uma galáxia pode levar uma paisagem sonora suave e generativa e pequenos sons enquanto você se move por ela, com um único controle no painel de navegação para ligar ou silenciar todo o som.',
            ],
            'search' => [
                'date' => 'Fevereiro de 2026',
                'title' => 'Busque dentro de uma galáxia',
                'body' => 'Um campo de busca no painel de navegação filtra os buracos de minhoca de uma galáxia conforme você digita, assim uma galáxia grande continua fácil de percorrer. O próprio painel de navegação foi redesenhado em torno disso.',
            ],
            'multi-editor' => [
                'date' => 'Fevereiro de 2026',
                'title' => 'Editoras, cada uma com as próprias galáxias',
                'body' => 'Uma instância pode ter muitas editoras, e cada uma vê e cuida apenas das galáxias que lhe foram atribuídas, enquanto quem opera supervisiona todas. É isso que permite que várias pessoas e comunidades compartilhem uma instância sem atrapalhar umas às outras.',
            ],
            'multiple-galaxies' => [
                'date' => 'Fevereiro de 2026',
                'title' => 'Mais de uma galáxia',
                'body' => 'Uma instância pode conter muitas galáxias separadas em vez de uma só rede, cada uma com os próprios buracos de minhoca e palavras-chave, assim diferentes fios de conhecimento podem conviver lado a lado.',
            ],
            'beginning' => [
                'date' => 'Janeiro de 2026',
                'title' => 'Telaris começa',
                'body' => 'A primeira versão de Telaris: uma só rede em 3D de buracos de minhoca pela qual você pode voar, ligada por sentido compartilhado, e que se abre um a um. Tudo desde então cresceu a partir daqui.',
            ],
        ],
        'fr' => [
            'mobile-views' => [
                'date' => 'Juin 2026',
                'title' => "Les écrans d'édition et d'administration fonctionnent maintenant sur téléphone",
                'body' => "Les écrans où l'on édite les trous de ver et où l'on administre une instance étaient conçus pour un grand écran. Ils s'adaptent désormais au téléphone : les longues listes deviennent des cartes empilées qui se lisent sans défilement latéral, les barres d'outils et la recherche se replient pour tenir, et les boutons sont plus faciles à toucher. La vue 3D fonctionnait déjà sur téléphone, et rien ne change sur ordinateur.",
            ],
            'editor-switch' => [
                'date' => 'Juin 2026',
                'title' => "Activez ou désactivez l'édition, de l'instance entière à un seul compte",
                'body' => "Qui exploite une instance peut désormais activer ou désactiver l'édition à quatre niveaux : l'instance entière, un groupe, une seule galaxie ou un compte d'édition. C'est utile quand une activité se termine et que l'on veut arrêter les nouveaux changements tout en gardant chaque compte et tout ce qui a été créé. Les réglages sont activés par défaut, et en désactiver un n'efface jamais rien.",
            ],
            'galaxy-name-in-view' => [
                'date' => 'Juin 2026',
                'title' => 'Le nom de la galaxie quand plusieurs partagent une vue',
                'body' => "Quand une scène réunit des trous de ver de plus d'une galaxie, chaque trou de ver montre désormais le nom de sa galaxie à côté du sien, au survol comme à l'ouverture. Avec une seule galaxie en vue, rien ne change : le nom apparaît seulement lorsqu'il aide à distinguer les galaxies. Cela fonctionne dans la scène en 3D comme dans la vue en 2D.",
            ],
            'two-d-zoom' => [
                'date' => 'Juin 2026',
                'title' => 'Zoomez, déplacez-vous et voyez chaque trou de ver dans la vue 2D',
                'body' => "La vue plate en 2D permet maintenant de zoomer et dézoomer, de glisser pour se déplacer et de recadrer toute la galaxie d'un bouton. Les grandes galaxies qui entassaient ou cachaient des trous de ver disposent désormais chaque tuile clairement, et choisir des galaxies dans la liste en bas à droite atténue aussi le reste ici.",
            ],
            'media-toggle' => [
                'date' => 'Juin 2026',
                'title' => 'Qui exploite peut désactiver le contenu multimédia intégré',
                'body' => "Chaque instance peut désormais désactiver les pages de contenu multimédia intégré depuis ses réglages. Les trous de ver qui portent déjà une page continuent de l'afficher ; les nouveaux n'offrent que la section multimédia classique. Le réglage est activé par défaut, donc rien ne change tant que qui exploite ne choisit pas de le désactiver.",
            ],
            'self-enroll' => [
                'date' => 'Juin 2026',
                'title' => "Inscris-toi comme compte d'édition",
                'body' => "Là où qui exploite l'autorise, tu peux demander un compte d'édition directement depuis l'écran de connexion et le confirmer par un lien à usage unique envoyé à ton courriel. Aucun mot de passe à configurer : chaque visite te connecte par un lien neuf.",
            ],
            'cross-galaxy-keywords' => [
                'date' => 'Juin 2026',
                'title' => 'Des connexions qui traversent les galaxies',
                'body' => "Les trous de ver de galaxies différentes qui partagent des mots-clés proches peuvent maintenant être reliés, pour qu'un fil de sens se suive à travers toute l'archive, et pas seulement dans une galaxie. Qui exploite décide de l'activer ou non.",
            ],
            'embedded-media' => [
                'date' => 'Mai 2026',
                'title' => 'Du contenu libre dans un trou de ver',
                'body' => "Un trou de ver peut désormais contenir une page libre d'images, de texte et de liens disposés à la main, à côté de la section multimédia classique ou à sa place. Les comptes d'édition gèrent ces pages depuis leur propre onglet dans l'éditeur.",
            ],
            'federation' => [
                'date' => 'Mai 2026',
                'title' => 'Des instances qui partagent leurs galaxies',
                'body' => "Les instances indépendantes de Telaris peuvent maintenant se faire confiance, publier des galaxies et refléter le travail publié des autres. La communauté garde le contrôle total : la confiance et le partage peuvent être retirés à tout moment, et un retrait supprime la copie partagée.",
            ],
            'four-languages' => [
                'date' => 'Mai 2026',
                'title' => "Toute l'archive en quatre langues",
                'body' => "Telaris parle maintenant anglais, espagnol, portugais et français de bout en bout, sans traiter aucune langue comme la solution de repli d'une autre. Change de langue depuis la barre en haut de chaque page.",
            ],
            'public-keyword-map' => [
                'date' => 'Juin 2026',
                'title' => "Explore les mots-clés d'une galaxie sans compte",
                'body' => "La carte des mots-clés d'une galaxie, le réseau de mots qui relie ses trous de ver, peut désormais être ouverte et lue sans connexion. Qui visite peut la regarder mais pas la modifier ; l'édition demande toujours un compte d'édition.",
            ],
            'consent-terms' => [
                'date' => 'Juin 2026',
                'title' => 'Une étape de consentement claire et des conditions en langage simple',
                'body' => "Les comptes d'édition acceptent désormais les Conditions d'utilisation et la Politique de confidentialité à la première connexion, et les deux documents ont été réécrits en langage simple. Tu peux les lire à tout moment depuis les liens au bas de chaque page.",
            ],
            'downloadable-docs' => [
                'date' => 'Mai 2026',
                'title' => 'Une documentation à télécharger',
                'body' => "Le Manifeste, un Démarrage rapide d'édition et un Manuel d'édition complet sont disponibles en PDF, chacun dans les quatre langues, depuis la page Documentation.",
            ],
            'two-d-view' => [
                'date' => 'Mai 2026',
                'title' => 'Une vue plane en 2D, pas seulement en 3D',
                'body' => "À côté de la scène en 3D, une galaxie peut proposer une vue en 2D paisible où chaque trou de ver est une petite tuile sur une grille et où les connexions sont tracées en lignes. Un sélecteur en haut permet de passer de l'une à l'autre, et le choix est mémorisé.",
            ],
            'multigalaxy' => [
                'date' => 'Mai 2026',
                'title' => 'Vois plusieurs galaxies à la fois',
                'body' => "Les trous de ver de plus d'une galaxie peuvent maintenant partager une seule scène, réunis en un groupe ou une famille, avec les connexions qui les traversent tracées en ponts. Chaque galaxie garde son propre aspect pour que ses trous de ver restent reconnaissables.",
            ],
            'rich-media' => [
                'date' => 'Mai 2026',
                'title' => 'Images, audio, vidéo et PDF dans un trou de ver',
                'body' => "Un trou de ver peut porter une image, un extrait audio, une vidéo ou un PDF comme pièce principale, affiché à l'intérieur de sa fenêtre. Les fichiers téléversés sont optimisés automatiquement pour se charger vite sans perdre en qualité.",
            ],
            'discovery' => [
                'date' => 'Avril 2026',
                'title' => 'Visites guidées et découverte en douceur',
                'body' => "Une galaxie peut proposer une visite guidée et lente de ses trous de ver, se déplacer vers l'un d'eux au hasard quand qui visite reste un moment immobile, et afficher une bande de mots-clés qui, une fois touchés, mettent en valeur les trous de ver liés. Chacune de ces options est facultative et reste désactivée tant qu'un compte d'édition ne l'active pas.",
            ],
            'backups' => [
                'date' => 'Avril 2026',
                'title' => 'Sauvegardes, instantanés et restauration pour qui exploite',
                'body' => "Qui exploite une instance peut prendre des instantanés complets de façon planifiée ou à la demande, les télécharger et restaurer à partir de l'un d'eux, et peut exporter des galaxies et des comptes d'édition vers un fichier portable pour les déplacer entre instances.",
            ],
            'themes' => [
                'date' => 'Février 2026',
                'title' => 'Chaque galaxie avec son propre aspect',
                'body' => "Les galaxies peuvent porter différents thèmes visuels, ainsi une même archive peut contenir un espace abstrait et épuré, un espace simple et sobre, ou d'autres aspects, chacun avec son propre fond, son éclairage et ses icônes de trou de ver.",
            ],
            'sound' => [
                'date' => 'Février 2026',
                'title' => 'Son et atmosphère',
                'body' => "Une galaxie peut porter un paysage sonore doux et génératif ainsi que de petits sons quand tu t'y déplaces, avec une seule commande dans le panneau de navigation pour activer ou couper tout le son.",
            ],
            'search' => [
                'date' => 'Février 2026',
                'title' => 'Cherche dans une galaxie',
                'body' => "Un champ de recherche dans le panneau de navigation filtre les trous de ver d'une galaxie à mesure que tu tapes, ainsi une grande galaxie reste facile à parcourir. Le panneau de navigation lui-même a été repensé autour de cela.",
            ],
            'multi-editor' => [
                'date' => 'Février 2026',
                'title' => "Des comptes d'édition, chacun avec ses propres galaxies",
                'body' => "Une instance peut avoir de nombreux comptes d'édition, et chacun ne voit et n'entretient que les galaxies qui lui sont attribuées, tandis que qui exploite les supervise toutes. C'est ce qui permet à plusieurs personnes et communautés de partager une instance sans se gêner.",
            ],
            'multiple-galaxies' => [
                'date' => 'Février 2026',
                'title' => "Plus d'une galaxie",
                'body' => "Une instance peut contenir de nombreuses galaxies distinctes plutôt qu'un seul réseau, chacune avec ses propres trous de ver et mots-clés, ainsi différents fils de savoir peuvent coexister côte à côte.",
            ],
            'beginning' => [
                'date' => 'Janvier 2026',
                'title' => 'Telaris commence',
                'body' => "La première version de Telaris : un seul réseau en 3D de trous de ver que tu peux survoler, relié par le sens partagé, et qui s'ouvre un à un. Tout ce qui a suivi est né de là.",
            ],
        ],
    ];
    $rows = $i18n[$locale] ?? $i18n['en'];
    $out = [];
    foreach ($order as $key) {
        $row = $rows[$key] ?? $i18n['en'][$key];
        $out[] = array_merge(['key' => $key], $row);
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
