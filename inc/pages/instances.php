<?php
declare(strict_types=1);

require_once __DIR__ . '/../content.php';

$pageTitle = info('instance_title');
$bodyClass = 'page-instances';
$includeBg = false;
require __DIR__ . '/../partials/head.php';

$instances = pluriverse_instances($pluriverseLocale);
?>

<main class="page">
  <p class="page-eyebrow"><?= h(info('instance_title')) ?></p>
  <h1 class="page-title"><?= h(info('instance_title')) ?></h1>
  <p class="page-lead"><?= h(info('instance_lead')) ?></p>
  <ul class="instance-list">
<?php foreach ($instances as $inst): ?>
    <li class="instance-list-item" style="--c:<?= h($inst['color']) ?>">
      <span class="dot" aria-hidden="true"></span>
      <div>
        <a class="instance-url" href="<?= h($inst['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($inst['host']) ?> &rarr;</a>
        <p class="instance-caption"><?= h($inst['caption']) ?></p>
        <div class="instance-tags"><?php foreach ($inst['tags'] as $tag): ?><span class="instance-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
