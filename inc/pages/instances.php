<?php
declare(strict_types=1);

require_once __DIR__ . '/../content.php';
require_once __DIR__ . '/../db_federation.php';

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
<?php if ($instances !== []): ?>
  <ul class="instance-list">
<?php foreach ($instances as $inst): ?>
    <li class="instance-list-item" style="--c:<?= h($inst['color']) ?>">
      <span class="dot" aria-hidden="true"></span>
      <div>
        <a class="instance-url" href="<?= h($inst['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($inst['label']) ?> &rarr;</a>
<?php if ($inst['host'] !== '' && $inst['host'] !== $inst['label']): ?>
        <p class="instance-host"><code><?= h($inst['host']) ?></code></p>
<?php endif; ?>
<?php if ($inst['caption'] !== ''): ?>
        <p class="instance-caption"><?= h($inst['caption']) ?></p>
<?php endif; ?>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if (db_self_service_is_open()): ?>
  <section class="instance-request-cta">
    <h2><?= h(info('instance_request_cta_title')) ?></h2>
    <p><?= h(info('instance_request_cta_body')) ?></p>
    <p><a class="btn" href="<?= h(pluriverse_locale_url('request-instance', $pluriverseLocale)) ?>"><?= h(info('instance_request_cta_button')) ?></a></p>
  </section>
<?php endif; ?>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
