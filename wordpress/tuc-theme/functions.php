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
    $garder = is_cart() || is_checkout() || is_account_page() || is_singular('product')
        || is_post_type_archive('product') || is_product_taxonomy() || is_shop();

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
    if (is_admin() || is_cart() || is_checkout() || is_account_page()) {
        return;
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
