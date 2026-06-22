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
// The "Request an instance" link only appears while requests are being
// accepted; when closed we don't advertise the entry point at all.
require_once __DIR__ . '/../db_federation.php';
$footerRequestOpen = db_self_service_is_open();
?>

<footer class="site-footer"><span class="dot" aria-hidden="true"></span><span class="status"><?= h(info('footer_status')) ?></span><span class="sep" aria-hidden="true">·</span><a href="<?= h(pluriverse_locale_url('contact', $pluriverseLocale)) ?>"><?= h(info('footer_contact')) ?></a><span class="sep" aria-hidden="true">·</span><a href="<?= h(pluriverse_locale_url('governance', $pluriverseLocale)) ?>"><?= h(info('footer_governance')) ?></a><span class="sep" aria-hidden="true">·</span><a href="<?= h(pluriverse_locale_url('privacy', $pluriverseLocale)) ?>"><?= h(info('footer_privacy')) ?></a><span class="sep" aria-hidden="true">·</span><a href="<?= h(pluriverse_locale_url('terms', $pluriverseLocale)) ?>"><?= h(info('footer_terms')) ?></a><?php if ($footerRequestOpen): ?><span class="sep" aria-hidden="true">·</span><a href="<?= h(pluriverse_locale_url('request-instance', $pluriverseLocale)) ?>"><?= h(info('footer_request')) ?></a><?php endif; ?></footer>
<?php if ($includeBg): ?>
<script src="/assets/bg.js"></script>
<?php endif; ?>
</body>
</html>
