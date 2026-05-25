<?php
declare(strict_types=1);

/**
 * Page head + navbar partial.
 *
 * Required globals set by inc/bootstrap.php:
 *   $pluriverseLocale, $pluriversePage, $pluriversePrefix, $pluriverseInfo
 *
 * Per-page values passed by the calling page renderer:
 *   $pageTitle   string  human-readable title (empty for home)
 *   $bodyClass   string  CSS class on <body>
 *   $includeBg   bool    include the <canvas id="bg"> background (home only)
 */

global $pluriverseLocale, $pluriversePage, $pluriverseInfo;
$pageTitle = $pageTitle ?? '';
$bodyClass = $bodyClass ?? '';
$includeBg = $includeBg ?? false;

$titleSuffix = info('title_suffix');
$fullTitle = $pageTitle !== '' ? ($pageTitle . ' · ' . $titleSuffix) : $titleSuffix;
$desc = info('tagline_desc');
$htmlLang = info('html_lang');

$navItems = [
    ['', info('nav_home')],
    ['documentation', info('nav_documentation')],
    ['instances', info('nav_instances')],
    ['manifest', info('nav_manifest')],
];
?><!doctype html>
<html lang="<?= h($htmlLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($fullTitle) ?></title>
  <meta name="description" content="<?= h($desc) ?>">
  <link rel="icon" href="data:,">
  <link rel="stylesheet" href="/assets/styles.css?v=<?= h((string)@filemtime(dirname(__DIR__, 2) . '/assets/styles.css') ?: '0') ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<?php if ($includeBg): ?>
<canvas id="bg" aria-hidden="true"></canvas>
<?php endif; ?>
<nav class="navbar" aria-label="Site"><div class="nav-main"><?php
$first = true;
foreach ($navItems as [$slug, $label]):
    if (!$first) echo '<span class="nav-sep" aria-hidden="true">·</span>';
    $first = false;
    $active = ($slug === $pluriversePage) ? ' active' : '';
    $href = pluriverse_locale_url($slug, $pluriverseLocale);
    ?><a class="nav-link<?= $active ?>" href="<?= h($href) ?>"><?= h($label) ?></a><?php
endforeach;
?><span class="nav-sep" aria-hidden="true">·</span><a class="nav-link" href="https://github.com/theagitist/telaris" target="_blank" rel="noopener noreferrer"><?= h(info('nav_source_code')) ?></a></div><div class="nav-lang" aria-label="Language"><?php
$first = true;
foreach (['en' => 'EN', 'es' => 'ES', 'pt' => 'PT', 'fr' => 'FR'] as $code => $label):
    if (!$first) echo '<span class="nav-sep" aria-hidden="true">·</span>';
    $first = false;
    $active = ($code === $pluriverseLocale) ? ' active' : '';
    $href = pluriverse_locale_url($pluriversePage, $code);
    ?><a class="nav-link<?= $active ?>" href="<?= h($href) ?>"><?= h($label) ?></a><?php
endforeach;
?></div></nav>
