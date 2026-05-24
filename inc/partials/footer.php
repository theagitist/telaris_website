<?php
declare(strict_types=1);

/**
 * Page footer + closing body/html partial.
 *
 * Per-page value passed by the calling page renderer:
 *   $includeBg   bool   include the bg.js script (home only)
 */

global $pluriverseLocale;
$includeBg = $includeBg ?? false;
?>

<footer class="site-footer"><span class="dot" aria-hidden="true"></span><span class="status"><?= h(info('footer_status')) ?></span><span class="sep" aria-hidden="true">·</span><a href="<?= h(pluriverse_locale_url('privacy', $pluriverseLocale)) ?>"><?= h(info('footer_privacy')) ?></a><span class="sep" aria-hidden="true">·</span><a href="<?= h(pluriverse_locale_url('terms', $pluriverseLocale)) ?>"><?= h(info('footer_terms')) ?></a></footer>
<?php if ($includeBg): ?>
<script src="/assets/bg.js"></script>
<?php endif; ?>
</body>
</html>
