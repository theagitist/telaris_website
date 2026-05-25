<?php
declare(strict_types=1);

require_once __DIR__ . '/../content.php';

global $pluriverseLocale;

$pageTitle = info('contact_title');
$bodyClass = 'page-contact';
$includeBg = false;
require __DIR__ . '/../partials/head.php';

$sections = [
    ['contact_section_repos_title',    'contact_section_repos_body'],
    ['contact_section_admin_title',    'contact_section_admin_body'],
    ['contact_section_security_title', 'contact_section_security_body'],
];
?>

<main class="page">
  <h1 class="page-title"><?= h(info('contact_title')) ?></h1>
  <p class="page-lead"><?= h(info('contact_lead')) ?></p>
  <div class="prose">
<?php foreach ($sections as [$titleKey, $bodyKey]): ?>
    <h2><?= h(info($titleKey)) ?></h2>
<?= pluriverse_commonmark()->convert(info($bodyKey))->getContent() ?>
<?php endforeach; ?>
  </div>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
