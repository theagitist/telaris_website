<?php
declare(strict_types=1);

require_once __DIR__ . '/../content.php';

$pageTitle = info('doc_title');
$bodyClass = 'page-documentation';
$includeBg = false;
require __DIR__ . '/../partials/head.php';

$docs = pluriverse_docs($pluriverseLocale);
$downloadLabel = info('doc_download');
?>

<main class="page">
  <p class="page-eyebrow"><?= h(info('doc_title')) ?></p>
  <h1 class="page-title"><?= h(info('doc_title')) ?></h1>
  <p class="page-lead"><?= h(info('doc_lead')) ?></p>
  <ul class="doc-list">
<?php foreach ($docs as $doc):
    $isDraft = !empty($doc['draft']);
    $pdfHref = ($pluriverseLocale === 'en')
        ? '/docs/' . $doc['slug'] . '.pdf'
        : '/docs/' . $doc['slug'] . '-' . $pluriverseLocale . '.pdf';
?>
    <li class="doc-list-item" style="--c:<?= h($doc['color']) ?>">
      <span class="dot" aria-hidden="true"></span>
      <div class="doc-meta">
        <span class="doc-name"><?= h($doc['name']) ?></span>
        <span class="doc-caption"><?= h($doc['caption']) ?></span>
      </div>
<?php if (!$isDraft): ?>
      <a class="doc-download" href="<?= h($pdfHref) ?>"><?= h($downloadLabel) ?> &rarr;</a>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
