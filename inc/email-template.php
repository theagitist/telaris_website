<?php
declare(strict_types=1);

/**
 * On-brand transactional email shell for Pluriverse system mail
 * (no-reply@telaris.ca). This is the www-side twin of the Telaris instance
 * shell (telaris/inc/email-template.php); the two are kept visually identical
 * on purpose so every email across the project reads as one surface. The only
 * deliberate difference is the wordmark, which names the surface that sent the
 * mail (Pluriverse here, Telaris on an instance).
 *
 * Brand canon (vault Documentation/Brand book/{Palette,Voice and tone}):
 *   - System palette: Void #000000 ground, Aurora white #e8eef0 body text,
 *     Aurora 60 for secondary text, Wormhole mint #00ffcc as a SIGNAL only
 *     (the CTA border/label, the wordmark dot, the hairline), never body text.
 *   - Monospace everywhere.
 *   - Tagline "weaving memory" (lowercase, letter-spaced) in the footer.
 *   - Voice: sentence case, no exclamation, no promotional flourish; lead with
 *     the action, then the facts, then the link.
 *
 * Email clients are hostile to modern CSS, so every style is inline and the
 * layout is a single centred container. Returns both an HTML body and a
 * plain-text alternative so pluriverse_send_mail() has a real multipart message.
 */

/**
 * Render a Pluriverse system email.
 *
 * @param array{
 *   heading: string,
 *   paragraphs: list<string>,
 *   bullets?: list<string>,
 *   cta?: array{label:string,url:string}|null,
 *   links?: list<array{label:string,url:string}>,
 *   body_links?: array<string,string>,
 *   note?: string|null,
 *   locale?: string,
 *   wordmark?: string
 * } $opts
 *
 * body_links turns a plain substring inside the paragraphs into a real link to
 * the given URL (so a bare hostname is not auto-linkified to the wrong place).
 * links renders secondary actions under the primary CTA. bullets renders a list
 * after the paragraphs.
 *
 * @return array{html:string,text:string}
 */
function pluriverse_email_render(array $opts): array
{
    $heading    = (string)($opts['heading'] ?? '');
    $paragraphs = array_values(array_filter((array)($opts['paragraphs'] ?? []), 'is_string'));
    $bullets    = array_values(array_filter((array)($opts['bullets'] ?? []), 'is_string'));
    $cta        = $opts['cta'] ?? null;
    $links      = is_array($opts['links'] ?? null) ? $opts['links'] : [];
    $bodyLinks  = is_array($opts['body_links'] ?? null) ? $opts['body_links'] : [];
    $note       = isset($opts['note']) ? (string)$opts['note'] : null;
    $locale     = isset($opts['locale']) && is_string($opts['locale']) ? $opts['locale'] : 'en';
    $wordmark   = isset($opts['wordmark']) && is_string($opts['wordmark']) && $opts['wordmark'] !== '' ? $opts['wordmark'] : 'Pluriverse';
    $tagline    = 'weaving memory';

    // Localized automated-address footer (the shell is standalone, so it carries
    // its own four-locale copy). Falls back to English only on an unknown locale.
    $autoFooters = [
        'en' => 'This message was sent by an automated address. Replies are not monitored.',
        'es' => 'Este mensaje se envió desde una dirección automática. No se leen las respuestas.',
        'pt' => 'Esta mensagem foi enviada de um endereço automático. As respostas não são lidas.',
        'fr' => "Ce message a été envoyé depuis une adresse automatique. Les réponses ne sont pas lues.",
    ];
    $autoFooter = $autoFooters[$locale] ?? $autoFooters['en'];

    $void      = '#000000';
    $panel     = '#0a0a0c';
    $aurora    = '#e8eef0';
    $aurora60  = 'rgba(232,238,240,0.6)';
    $aurora28  = 'rgba(232,238,240,0.28)';
    $mint      = '#00ffcc';
    $mintLine  = 'rgba(0,255,204,0.4)';
    $border    = 'rgba(255,255,255,0.08)';
    $mono      = "'SF Mono','JetBrains Mono',Menlo,Consolas,'Liberation Mono',monospace";

    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $linkNeedles = [];
    foreach ($bodyLinks as $needle => $url) {
        if (is_string($needle) && $needle !== '' && is_string($url) && $url !== '') {
            $linkNeedles[$needle] = $url;
        }
    }
    uksort($linkNeedles, static fn($a, $b) => strlen((string)$b) <=> strlen((string)$a));
    $applyBodyLinks = static function (string $escaped) use ($linkNeedles, $esc, $mint): string {
        foreach ($linkNeedles as $needle => $url) {
            $needleEsc = $esc((string)$needle);
            $anchor = '<a href="' . $esc((string)$url) . '" style="color:' . $mint . ';text-decoration:underline;">' . $needleEsc . '</a>';
            $escaped = str_replace($needleEsc, $anchor, $escaped);
        }
        return $escaped;
    };

    $paraHtml = '';
    foreach ($paragraphs as $p) {
        $paraHtml .= '<p style="margin:0 0 18px;color:' . $aurora . ';font-size:16px;line-height:1.7;">' . $applyBodyLinks($esc($p)) . '</p>';
    }

    $bulletsHtml = '';
    if ($bullets !== []) {
        $items = '';
        foreach ($bullets as $b) {
            $items .= '<li style="margin:0 0 8px;color:' . $aurora . ';font-size:16px;line-height:1.6;">' . $applyBodyLinks($esc($b)) . '</li>';
        }
        $bulletsHtml = '<ul style="margin:0 0 18px;padding-left:20px;">' . $items . '</ul>';
    }

    $ctaHtml = '';
    if (is_array($cta) && !empty($cta['url']) && !empty($cta['label'])) {
        $ctaHtml =
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;">'
            . '<tr><td style="border:1px solid ' . $mintLine . ';border-radius:4px;">'
            . '<a href="' . $esc((string)$cta['url']) . '" '
            . 'style="display:inline-block;padding:13px 26px;font-family:' . $mono . ';font-size:15px;'
            . 'letter-spacing:0.04em;color:' . $mint . ';text-decoration:none;">'
            . $esc((string)$cta['label']) . '</a>'
            . '</td></tr></table>';
    }

    $linksHtml = '';
    if ($links !== []) {
        $items = '';
        foreach ($links as $lnk) {
            if (!is_array($lnk) || empty($lnk['url']) || empty($lnk['label'])) {
                continue;
            }
            $items .= '<p style="margin:0 0 10px;font-family:' . $mono . ';font-size:14px;line-height:1.6;">'
                . '<a href="' . $esc((string)$lnk['url']) . '" style="color:' . $mint . ';text-decoration:underline;">'
                . $esc((string)$lnk['label']) . '</a></p>';
        }
        if ($items !== '') {
            $linksHtml = '<div style="margin:0 0 18px;">' . $items . '</div>';
        }
    }

    $noteHtml = '';
    if ($note !== null && $note !== '') {
        $noteHtml = '<p style="margin:0 0 8px;color:' . $aurora60 . ';font-size:13px;line-height:1.65;">' . $esc($note) . '</p>';
    }

    $linkFallbackHtml = '';
    if (is_array($cta) && !empty($cta['url'])) {
        $linkFallbackHtml =
            '<p style="margin:0 0 16px;color:' . $aurora60 . ';font-size:13px;line-height:1.65;word-break:break-all;">'
            . $esc((string)$cta['url']) . '</p>';
    }

    $html =
        '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
        . '<meta name="color-scheme" content="dark"></head>'
        . '<body style="margin:0;padding:0;background:' . $void . ';">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $void . ';">'
        . '<tr><td align="center" style="padding:32px 16px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="max-width:480px;background:' . $panel . ';border:1px solid ' . $border . ';border-radius:8px;">'
        . '<tr><td style="padding:28px 28px 8px;">'
        . '<div style="font-family:' . $mono . ';font-size:18px;letter-spacing:0.22em;color:' . $aurora . ';text-transform:uppercase;">'
        . $esc($wordmark) . ' <span style="color:' . $mint . ';">&bull;</span></div>'
        . '<div style="height:1px;background:' . $mintLine . ';margin:14px 0 22px;"></div>'
        . '</td></tr>'
        . '<tr><td style="padding:0 28px;">'
        . '<h1 style="margin:0 0 18px;font-family:' . $mono . ';font-size:21px;font-weight:600;line-height:1.4;color:' . $aurora . ';">' . $esc($heading) . '</h1>'
        . '<div style="font-family:' . $mono . ';">' . $paraHtml . $bulletsHtml . $ctaHtml . $linkFallbackHtml . $linksHtml . $noteHtml . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px 28px;">'
        . '<div style="height:1px;background:' . $border . ';margin:0 0 16px;"></div>'
        . '<div style="font-family:' . $mono . ';font-size:12px;letter-spacing:0.18em;color:' . $aurora28 . ';text-transform:lowercase;">' . $esc($tagline) . '</div>'
        . '<p style="margin:10px 0 0;font-family:' . $mono . ';font-size:12px;line-height:1.6;color:' . $aurora28 . ';">'
        . $esc($autoFooter) . '</p>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';

    // Plain-text alternative.
    $textLines = [$heading, ''];
    foreach ($paragraphs as $p) {
        $textLines[] = $p;
        $textLines[] = '';
    }
    foreach ($bullets as $b) {
        $textLines[] = '- ' . $b;
    }
    if ($bullets !== []) {
        $textLines[] = '';
    }
    if (is_array($cta) && !empty($cta['url']) && !empty($cta['label'])) {
        $textLines[] = strtoupper((string)$cta['label']) . ': ' . (string)$cta['url'];
        $textLines[] = '';
    }
    foreach ($links as $lnk) {
        if (is_array($lnk) && !empty($lnk['url']) && !empty($lnk['label'])) {
            $textLines[] = (string)$lnk['label'] . ': ' . (string)$lnk['url'];
        }
    }
    if ($links !== []) {
        $textLines[] = '';
    }
    if ($note !== null && $note !== '') {
        $textLines[] = $note;
        $textLines[] = '';
    }
    $textLines[] = '--';
    $textLines[] = $wordmark . ' · ' . $tagline;
    $textLines[] = $autoFooter;
    $text = implode("\n", $textLines);

    return ['html' => $html, 'text' => $text];
}
