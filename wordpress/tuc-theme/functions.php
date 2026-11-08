<?php
/**
 * TUC Market — thème du site provisoire.
 *
 * Principe : le thème ne fait QUE rendre du HTML. Aucune logique métier ici.
 * Les données (restaurants, promos) viennent de l'API TUC ou d'un custom post type.
 */

defined('ABSPATH') || exit;

define('TUC_VERSION', '0.1.0');
define('TUC_DIR', get_template_directory());
define('TUC_URI', get_template_directory_uri());

/* ---------------------------------------------------------------- supports */
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('responsive-embeds');
    register_nav_menus([
        'primary' => __('Navigation principale', 'tuc'),
        'footer'  => __('Pied de page', 'tuc'),
    ]);
    add_image_size('tuc-card', 680, 440, true);
    add_image_size('tuc-banner', 1160, 380, true);
});

/* ---------------------------------------------------------------- assets */
add_action('wp_enqueue_scripts', function () {
    $css = TUC_DIR . '/assets/css/tuc.css';
    $js  = TUC_DIR . '/assets/js/tuc.js';

    wp_enqueue_style('tuc', TUC_URI . '/assets/css/tuc.css', [], file_exists($css) ? filemtime($css) : TUC_VERSION);
    wp_enqueue_script('tuc', TUC_URI . '/assets/js/tuc.js', [], file_exists($js) ? filemtime($js) : TUC_VERSION, true);

    // polices : Manrope + IBM Plex Sans Arabic, servies depuis le thème (pas de Google)
    $font = TUC_URI . '/assets/css/fonts.css';
    if (file_exists(TUC_DIR . '/assets/css/fonts.css')) {
        wp_enqueue_style('tuc-fonts', $font, [], TUC_VERSION);
    }
}, 20);

/* retire les emojis de WordPress : ils polluent le HTML et coûtent 12 Ko */
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
});


/* ---------------------------------------------------------------- perf */
/**
 * WooCommerce ne sert qu'à l'ADMIN (catalogue). Sur le site public, ses assets
 * coûtent 3 CSS + jQuery + 7 scripts. Mesuré : 127 Ko de CSS dont 108 Ko de Woo.
 * On garde Woo uniquement sur les pages boutique / panier / compte.
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }
    // Les fonctions WooCommerce n'existent pas si le plugin est désactivé.
    // Les pages produit utilisent aussi les conditionnels WordPress natifs.
    $garder = is_singular('product') || is_post_type_archive('product');
    foreach (['is_cart', 'is_checkout', 'is_account_page', 'is_product_taxonomy', 'is_shop'] as $condition) {
        if (function_exists($condition) && $condition()) {
            $garder = true;
            break;
        }
    }

    if ($garder) {
        return;
    }

    // styles
    wp_dequeue_style('woocommerce-layout');
    wp_dequeue_style('woocommerce-smallscreen');
    wp_dequeue_style('woocommerce-general');
    wp_dequeue_style('wc-blocks-style');

    // scripts
    foreach ([
        'woocommerce', 'wc-add-to-cart', 'wc-cart-fragments', 'jquery-blockui',
        'js-cookie', 'sourcebuster-js', 'wc-order-attribution', 'wc-price-slider',
    ] as $h) {
        wp_dequeue_script($h);
    }

    // blocks inutiles hors boutique
    wp_dequeue_style('wc-blocks-vendors-style');
}, 99);

/**
 * Un seul fichier JS, 4 Ko, aucun framework. On retire jQuery du front :
 * le thème n'en a pas besoin et Woo n'est plus chargé hors boutique.
 */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }

    foreach (['is_cart', 'is_checkout', 'is_account_page'] as $condition) {
        if (function_exists($condition) && $condition()) {
            return;
        }
    }

    wp_deregister_script('jquery');
    wp_deregister_script('jquery-core');
    wp_deregister_script('jquery-migrate');
}, 100);

/* pas de bloc <style> ni de scripts de blocs sur le front */
add_action('wp_enqueue_scripts', function () {
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
}, 100);

/* ---------------------------------------------------------------- données */
require_once TUC_DIR . '/inc/data.php';
require_once TUC_DIR . '/inc/template-tags.php';

/* ---------------------------------------------------------------- REST */
require_once TUC_DIR . '/inc/rest.php';
require_once TUC_DIR . '/inc/picture.php';

/* ------------------------------------------------ SEO éditorial, uniquement en repli si aucun plugin SEO n'est actif */
add_action('wp_head', function (): void {
    foreach (['wpseo_head', 'rank_math_the_head', 'seopress_head', 'aioseo_head'] as $seo_callback) {
        if (has_action('wp_head', $seo_callback)) {
            return;
        }
    }

    if (is_front_page()) {
        $description = __('Découvrez les restaurants, cafés et snacks de Casablanca. Recherchez une adresse ou un plat, explorez les quartiers et consultez les menus.', 'tuc');
        if (isset($_GET['q']) && is_scalar($_GET['q']) && taxonomy_exists('tuc_quartier_cat')) {
            $term = get_term_by('slug', sanitize_title((string) wp_unslash($_GET['q'])), 'tuc_quartier_cat');
            if ($term instanceof WP_Term) $description = sprintf(__('Restaurants disponibles dans le quartier %s à Casablanca.', 'tuc'), $term->name);
        } elseif (isset($_GET['ville']) && is_scalar($_GET['ville']) && sanitize_title((string) wp_unslash($_GET['ville'])) === 'casablanca') {
            $description = __('Restaurants et quartiers disponibles à Casablanca. Consultez les menus, les avis et les informations de livraison.', 'tuc');
        }
        echo '<link rel="canonical" href="' . esc_url(home_url('/')) . '">' . "\n";
    } elseif (is_singular()) {
        $post = get_queried_object();
        if (!$post instanceof WP_Post) return;
        $description = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(strip_shortcodes($post->post_content), 28, '…');
        if (trim(wp_strip_all_tags((string) $description)) === '' && $post->post_type === 'tuc_resto') {
            $description = sprintf(__('Consultez le menu et les modes de commande de %s à Casablanca.', 'tuc'), get_the_title($post));
        }
    } else {
        return;
    }

    $description = trim(wp_strip_all_tags((string) $description));
    if ($description === '') return;
    $description = wp_html_excerpt($description, 160, '…');
    echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
}, 1);

add_filter('wp_robots', function (array $robots): array {
    // Les filtres par quartier sont utiles aux clients mais ne créent pas des pages SEO distinctes.
    if (is_front_page() && (isset($_GET['q']) || isset($_GET['ville']))) {
        $robots['noindex'] = true;
    }
    return $robots;
});

add_filter('pre_get_document_title', function (string $title): string {
    if (!is_front_page()) return $title;
    if (isset($_GET['q']) && taxonomy_exists('tuc_quartier_cat')) {
        $term = get_term_by('slug', sanitize_title(is_scalar($_GET['q']) ? (string) wp_unslash($_GET['q']) : ''), 'tuc_quartier_cat');
        if ($term instanceof WP_Term) return sprintf(__('Restaurants à %1$s | %2$s', 'tuc'), $term->name, get_bloginfo('name'));
    }
    return sprintf(__('Restaurants à Casablanca | %s', 'tuc'), get_bloginfo('name'));
});
