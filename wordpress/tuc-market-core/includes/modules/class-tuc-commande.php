<?php
/**
 * Module 4 — REST `POST /tuc/v1/commande`.
 *
 * ⚠️ POURQUOI DANS LE PLUGIN : une route REST enregistrée par un thème cesse
 * d'exister au changement de thème. La PWA de caisse appellerait une URL morte.
 * Les commandes sont des DONNÉES métier : elles doivent survivre au design.
 *
 * Pas de paiement en ligne : la commande est transmise, le client paie en cash.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Commande
{
    public const CPT = 'tuc_commande';

    public static function init(): void
    {
        add_action('init', [self::class, 'register'], 5);
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function register(): void
    {
        register_post_type(self::CPT, [
            'labels'       => ['name' => __('Commandes', 'tuc-core')],
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => false, // lecture seule via nos routes
            'supports'     => ['title'],
            'capability_type' => 'post',
            'menu_icon'    => 'dashicons-clipboard',
        ]);
    }

    public static function routes(): void
    {
        register_rest_route('tuc/v1', '/commande', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true', // le client n'a pas de compte
            'args'                => [
                'resto'  => ['required' => true, 'sanitize_callback' => 'sanitize_title'],
                'table'  => ['default' => 0, 'sanitize_callback' => 'absint'],
                'items'  => ['required' => true],
                'total'  => ['required' => true, 'sanitize_callback' => 'absint'],
                'tel'    => ['sanitize_callback' => 'sanitize_text_field'],
            ],
            'callback' => [self::class, 'creer'],
        ]);
    }

    public static function creer(WP_REST_Request $req)
    {
        $items = json_decode((string) wp_json_encode($req->get_param('items')), true) ?: [];

        if ($items === []) {
            return new WP_Error('tuc_vide', 'Panier vide.', ['status' => 400]);
        }

        $id = wp_insert_post([
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => sprintf('Commande %s — %s', gmdate('d/m H:i'), $req->get_param('resto')),
            'meta_input'  => [
                'tuc_resto'   => $req->get_param('resto'),
                'tuc_table'   => (int) $req->get_param('table'),
                'tuc_items'   => $items,
                'tuc_total'   => (int) $req->get_param('total'),
                'tuc_tel'     => $req->get_param('tel'),
                'tuc_statut'  => 'nouvelle',
            ],
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }

        /** Étape suivante (module 6) : Web Push vers le téléphone de la caisse. */
        do_action('tuc_commande_creee', $id, $items);

        return new WP_REST_Response(['ok' => true, 'commande' => $id], 201);
    }
}
