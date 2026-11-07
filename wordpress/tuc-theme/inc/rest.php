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

    // /sante est fourni par le PLUGIN tuc-market-core : un thème cassé doit rester détectable.

});
