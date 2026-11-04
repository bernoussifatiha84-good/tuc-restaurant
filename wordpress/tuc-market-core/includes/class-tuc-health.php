<?php
/**
 * Santé du site — l'endpoint que le déploiement interroge après chaque mise en ligne.
 *
 * Pourquoi il vit dans le PLUGIN et pas dans le thème :
 * un déploiement de thème cassé ne doit pas rendre le site indétectable. Le plugin
 * répond même si le thème est blanc, ce qui permet au CI de dire « thème cassé »
 * au lieu de « site mort ».
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Health
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route('tuc/v1', '/sante', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'rapport'],
        ]);
    }

    /**
     * Rapport volontairement factuel : ce qui est chargé, ce qui manque.
     * Un CI doit pouvoir décider sans interpréter.
     */
    public static function rapport(): WP_REST_Response
    {
        $theme   = wp_get_theme();
        $actifs  = (array) get_option('active_plugins', []);
        $manques = [];

        foreach (['tuc-market-core/tuc-market-core.php'] as $requis) {
            if (!in_array($requis, $actifs, true)) {
                $manques[] = $requis;
            }
        }

        $ok = ($theme->get('Name') === 'TUC Market') && $manques === [];

        return new WP_REST_Response([
            'ok'          => $ok,
            'plugin'      => TUC_CORE_VERSION,
            'theme'       => $theme->get('Name'),
            'theme_ver'   => $theme->get('Version'),
            'wp'          => get_bloginfo('version'),
            'php'         => PHP_VERSION,
            'langues'     => [get_locale(), is_rtl() ? 'rtl' : 'ltr'],
            'plugins_ko'  => $manques,
            'modules'     => [
                'cpt_resto'    => post_type_exists('tuc_resto'),
                'cpt_menu'     => post_type_exists('tuc_menu_item'),
                'cpt_commande' => post_type_exists('tuc_commande'),
                'rest_commande' => (bool) apply_filters('tuc_module_commande', true),
            ],
            'heure'       => current_time('c'),
        ], $ok ? 200 : 503);
    }
}
