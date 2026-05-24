<?php
declare(strict_types=1);

/**
 * Shared template for content pages backed by markdown (Manifest, Privacy,
 * Terms). The calling page renderer sets:
 *   $contentSlug   string  URL slug, also the info() key prefix
 *                          ('manifest' | 'privacy' | 'terms')
 */

require_once __DIR__ . '/../content.php';

$slug = $contentSlug ?? '';
if ($slug === '') {
    http_response_code(500);
    return;
}

$pageTitle = info($slug . '_title');
$bodyClass = 'page-' . $slug;
$includeBg = false;
require __DIR__ . '/../partials/head.php';

$bodyHtml = pluriverse_render_doc($slug, $pluriverseLocale);
$pdfHref = ($pluriverseLocale === 'en')
    ? '/docs/' . ($slug === 'terms' ? 'tos' : $slug) . '.pdf'
    : '/docs/' . ($slug === 'terms' ? 'tos' : $slug) . '-' . $pluriverseLocale . '.pdf';
$downloadLabel = info($slug . '_download_pdf');
?>

<main class="page">
  <p class="page-eyebrow"><?= h(info($slug . '_title')) ?></p>
  <h1 class="page-title"><?= h(info($slug . '_title')) ?></h1>
  <p class="page-lead"><?= h(info($slug . '_lead')) ?></p>
  <div class="prose">
<?= $bodyHtml ?>
  </div>
  <a class="pdf-cta" href="<?= h($pdfHref) ?>"><?= h($downloadLabel) ?> &rarr;</a>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
