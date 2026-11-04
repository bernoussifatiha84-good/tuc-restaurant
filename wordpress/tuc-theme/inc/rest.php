<?php
/**
 * Endpoints REST — consommés par la PWA de caisse et par le menu mobile.
 * Volontairement en lecture seule. Aucune écriture sans authentification.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('tuc/v1', '/restos', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => fn() => rest_ensure_response(tuc_restos()),
    ]);

    register_rest_route('tuc/v1', '/promos', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => fn() => rest_ensure_response(tuc_promos()),
    ]);

    // fumigène : le workflow de déploiement appelle /sante après chaque mise en ligne
    register_rest_route('tuc/v1', '/sante', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => fn() => rest_ensure_response([
            'ok'      => true,
            'theme'   => TUC_VERSION,
            'heure'   => current_time('c'),
            'restos'  => count(tuc_restos()),
        ]),
    ]);
});
