<?php
/**
 * Module 1 — CPT `tuc_resto` et `tuc_menu_item`.
 *
 * ⚠️ POURQUOI DANS LE PLUGIN ET PAS DANS LE THÈME :
 * un type de contenu enregistré dans le thème disparaît de l'admin dès qu'on
 * change de thème. Les fiches, les menus et les commandes restent en base mais
 * deviennent invisibles et introuvables (erreur 404 sur /kfc).
 * Le rsync du déploiement utilise --delete : un thème remplacé efface le CPT.
 * Donc : CPT = plugin, toujours.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_CPT
{
    public const RESTO = 'tuc_resto';
    public const ITEM  = 'tuc_menu_item';

    public static function init(): void
    {
        add_action('init', [self::class, 'register'], 5);
        add_action('init', [self::class, 'meta'], 6);
    }

    public static function register(): void
    {
        // ---------------- restaurants ----------------
        register_post_type(self::RESTO, [
            'labels' => [
                'name'          => __('Restaurants', 'tuc-core'),
                'singular_name' => __('Restaurant', 'tuc-core'),
                'add_new_item'  => __('Ajouter un restaurant', 'tuc-core'),
                'edit_item'     => __('Modifier le restaurant', 'tuc-core'),
            ],
            'public'        => true,
            'show_in_rest'  => true,   // indispensable pour l'API et l'éditeur de blocs
            'has_archive'   => false,
            'menu_icon'     => 'dashicons-store',
            'menu_position' => 5,
            'supports'      => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            // racine tuc.ma/kfc : géré par TUC_Rewrite, pas par WordPress
            'rewrite'       => false,
            'taxonomies'    => ['tuc_quartier_cat'],
        ]);

        // ---------------- quartiers ----------------
        register_taxonomy('tuc_quartier_cat', [self::RESTO], [
            'labels'            => ['name' => __('Quartiers', 'tuc-core')],
            'public'            => true,
            'hierarchical'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'quartier'],
        ]);

        // ---------------- plats / menu ----------------
        register_post_type(self::ITEM, [
            'labels'       => [
                'name'          => __('Menu', 'tuc-core'),
                'singular_name' => __('Plat', 'tuc-core'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'supports'     => ['title', 'editor', 'thumbnail', 'page-attributes'],
            // Gestion via l’API Espace Pro; l’API REST native est réservée aux admins.
            'capability_type' => ['tuc_menu_item', 'tuc_menu_items'],
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-food',
        ]);

        register_taxonomy('tuc_categorie', [self::ITEM], [
            'labels'       => ['name' => __('Catégories de plat', 'tuc-core')],
            'public'       => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'hierarchical' => true,
        ]);
    }

    /**
     * Champs des fiches. Déclarés avec `show_in_rest` pour rester lisibles
     * par l'API et par l'éditeur — on n'ajoute pas ACF pour ça.
     */
    public static function meta(): void
    {
        $champs = [
            'tuc_cuisine'   => 'string',   // Marocain · Libanais
            'tuc_note'      => 'number',   // 4.9
            'tuc_temps_min' => 'integer',  // 20
            'tuc_temps_max' => 'integer',  // 35
            'tuc_distance'  => 'number',   // 2.1 (km)
            'tuc_frais'     => 'integer',  // 0 = gratuit
            'tuc_modes'     => 'array',    // livraison | emporter | table
            'tuc_whatsapp'  => 'string',   // donnée modifiable, jamais encodée dans le QR
            'tuc_adresse'   => 'string',
            'tuc_lat'       => 'number',
            'tuc_lng'       => 'number',
            'tuc_minimum'   => 'number',   // panier minimum (0 = aucun)
            'tuc_ferme'     => 'boolean',  // pause restaurant
            'tuc_horaires'  => 'array',    // ouverture par jour
            'tuc_tables'    => 'integer',  // nombre de tables (QR par table)
        ];

        foreach ($champs as $key => $type) {
            register_post_meta(self::RESTO, $key, [
                'type'              => $type,
                'single'            => true,
                'show_in_rest'      => true,
                'sanitize_callback' => $type === 'array' ? [self::class, 'sanitize_liste'] : null,
                'auth_callback'     => static fn(): bool => current_user_can('edit_posts'),
            ]);
        }

        foreach (['tuc_prix' => 'number', 'tuc_promo' => 'integer', 'tuc_promo_pct' => 'integer', 'tuc_options' => 'array', 'tuc_resto_id' => 'integer', 'tuc_dispo' => 'boolean'] as $key => $type) {
            register_post_meta(self::ITEM, $key, [
                'type'          => $type,
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => static fn(): bool => current_user_can('edit_posts'),
            ]);
        }
    }

    public static function sanitize_liste($value): array
    {
        return array_values(array_filter(array_map('sanitize_text_field', (array) $value)));
    }
}
