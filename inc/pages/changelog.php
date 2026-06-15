<?php
declare(strict_types=1);

require_once __DIR__ . '/../content.php';

$pageTitle = info('changelog_title');
$bodyClass = 'page-changelog';
$includeBg = false;
require __DIR__ . '/../partials/head.php';

$entries = pluriverse_changelog($pluriverseLocale);
?>

<main class="page">
  <p class="page-eyebrow"><?= h(info('changelog_title')) ?></p>
  <h1 class="page-title"><?= h(info('changelog_title')) ?></h1>
  <p class="page-lead"><?= h(info('changelog_lead')) ?></p>
<?php if ($entries !== []): ?>
  <ol class="changelog-list">
<?php foreach ($entries as $entry): ?>
    <li class="changelog-entry">
      <p class="changelog-date"><?= h($entry['date']) ?></p>
      <h2 class="changelog-entry-title"><?= h($entry['title']) ?></h2>
      <p class="changelog-entry-body"><?= h($entry['body']) ?></p>
    </li>
<?php endforeach; ?>
  </ol>
<?php endif; ?>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
