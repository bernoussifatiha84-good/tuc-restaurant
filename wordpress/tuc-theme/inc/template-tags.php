<?php
defined('ABSPATH') || exit;

/** URL d'une image du thème, avec fallback. */
function tuc_img(string $name): string
{
    $file = TUC_DIR . '/assets/img/' . $name . '.jpg';
    return file_exists($file) ? TUC_URI . '/assets/img/' . $name . '.jpg' : TUC_URI . '/assets/img/tagine.jpg';
}

/** Prix formaté : 0 => "Gratuit", sinon "12 MAD". */
function tuc_fee($fee): string
{
    return $fee === null
        ? __('Gratuit', 'tuc')
        : sprintf(__('%s MAD', 'tuc'), $fee);
}

/** Note formatée à la française : 4.9 -> "4,9". */
function tuc_rating(float $r): string
{
    return str_replace('.', ',', number_format($r, 1));
}

/** Icône SVG inline (aucun emoji, aucune requête). */
function tuc_icon(string $name, int $size = 18): string
{
    $paths = [
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
        'pin'     => '<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
        'star'    => '<path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.4l6.5-.9z"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'left'    => '<path d="M15 6l-6 6 6 6"/>',
        'right'   => '<path d="M9 6l6 6-6 6"/>',
        'bag'     => '<path d="M4 5h2l2 11h10l2-8H7"/><circle cx="9.5" cy="19.5" r="1.4"/><circle cx="17.5" cy="19.5" r="1.4"/>',
        'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'qr'      => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><path d="M14 14h3v3h-3zM19 19h2v2h-2z"/>',
        'close'   => '<path d="M6 6l12 12M18 6L6 18"/>',
    ];
    $p = $paths[$name] ?? $paths['search'];
    $fill = in_array($name, ['star'], true) ? 'fill="currentColor" stroke="none"' : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    return sprintf(
        '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" %2$s aria-hidden="true">%3$s</svg>',
        $size,
        $fill,
        $p
    );
}

/** Le logo TUC (texte + point jaune). */
function tuc_logo(int $size = 27): string
{
    return sprintf(
        '<a class="logo" href="%s" style="font-size:%dpx">TUC<span class="dot"></span></a>',
        esc_url(home_url('/')),
        $size
    );
}
