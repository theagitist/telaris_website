<?php
declare(strict_types=1);

$pageTitle = '';
$bodyClass = 'home page-home';
$includeBg = true;
require __DIR__ . '/../partials/head.php';
?>

<main class="home">
  <h1 class="wordmark">Telaris</h1>
  <p class="tagline"><?= h(info('weaving')) ?></p>
  <div class="hud-line"></div>
  <p class="desc"><?= h(info('tagline_desc')) ?></p>
</main>
<?php require __DIR__ . '/../partials/footer.php'; ?>
