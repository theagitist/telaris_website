<?php
declare(strict_types=1);

require_once __DIR__ . '/../content.php';

global $pluriverseLocale;

$pageTitle = info('governance_title');
$bodyClass = 'page-governance';
$includeBg = false;
require __DIR__ . '/../partials/head.php';

$sections = [
    ['governance_section_admission_title', 'governance_section_admission_body'],
    ['governance_section_lifecycle_title', 'governance_section_lifecycle_body'],
    ['governance_section_fork_title',      'governance_section_fork_body'],
];
?>

<main class="page">
  <h1 class="page-title"><?= h(info('governance_title')) ?></h1>
  <p class="page-lead"><?= h(info('governance_lead')) ?></p>
  <div class="prose">
<?php foreach ($sections as [$titleKey, $bodyKey]): ?>
    <h2><?= h(info($titleKey)) ?></h2>
<?= pluriverse_commonmark()->convert(info($bodyKey))->getContent() ?>
<?php endforeach; ?>
  </div>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
