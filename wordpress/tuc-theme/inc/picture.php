<?php
/**
 * Convertisseur d'images — <picture> avec AVIF, WebP et JPEG de repli.
 *
 * Recommandation mesurée sur les 28 visuels TUC (680×440) :
 *   JPEG 1 218 Ko · WebP q78 1 188 Ko (−2 %) · AVIF q42 509 Ko (−58 %)
 * Sur du mutualisé Hostinger, WebP n'apporte RIEN. AVIF si.
 *
 * Le navigateur choisit la première source qu'il comprend : AVIF, sinon WebP, sinon JPEG.
 * Aucune requête supplémentaire, aucun JS, aucun plugin.
 */

defined('ABSPATH') || exit;

/**
 * Rend une balise <picture> complète.
 *
 * @param string $name    nom du fichier sans extension (ex. 'tagine')
 * @param string $alt     texte alternatif
 * @param array  $attr    attributs supplémentaires (class, loading, width, height…)
 */
function tuc_picture(string $name, string $alt = '', array $attr = []): string
{
    $base = TUC_URI . '/assets/img/';
    $dir  = TUC_DIR . '/assets/img/';

    $w = (int) ($attr['width'] ?? 440);
    $h = (int) ($attr['height'] ?? 330);

    $class = $attr['class'] ?? '';
    $loading = $attr['loading'] ?? 'lazy';
    $decoding = $attr['decoding'] ?? 'async';
    $sizes = $attr['sizes'] ?? '(max-width: 900px) 50vw, 240px';
    $extra = $attr['extra'] ?? '';

    $attrs = sprintf(
        'width="%d" height="%d" loading="%s" decoding="%s" %s',
        $w, $h, esc_attr($loading), esc_attr($decoding), $extra
    );

    $srcset = static function (string $ext) use ($base, $w): string {
        // on expose 2 densités : 1x et 2x (2x = même fichier, retina n'a pas de sens ici)
        return esc_url($base . $ext . '/' . $name . '.' . substr($ext, 1)) . ' ' . $w . 'w';
    };

    $html  = '<picture>';
    if (file_exists($dir . 'avif/' . $name . '.avif')) {
        $html .= '<source type="image/avif" srcset="' . $srcset('avif') . '" sizes="' . esc_attr($sizes) . '">';
    }
    if (file_exists($dir . 'webp/' . $name . '.webp')) {
        $html .= '<source type="image/webp" srcset="' . $srcset('webp') . '" sizes="' . esc_attr($sizes) . '">';
    }
    $html .= sprintf(
        '<img src="%s" alt="%s" class="%s" %s>',
        esc_url($base . $name . '.jpg'),
        esc_attr($alt),
        esc_attr($class),
        $attrs
    );
    $html .= '</picture>';

    return $html;
}

/**
 * Précharge l'image du hero : c'est le LCP, elle ne doit pas attendre.
 */
add_action('wp_head', static function (): void {
    if (!is_front_page()) {
        return;
    }
    $hero = TUC_URI . '/assets/img/hero-banner.avif';
    if (!file_exists(TUC_DIR . '/assets/img/hero-banner.avif')) {
        return;
    }
    printf(
        '<link rel="preload" as="image" href="%s" type="image/avif" fetchpriority="high">' . "\n",
        esc_url($hero)
    );
});
