<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rôles et capacités métier TUC. Aucun utilisateur n'est affecté automatiquement;
 * l'accès réel reste lié à un tuc_resto_id explicite et aux contrôles des routes.
 */
final class TUC_Roles
{
    public const CAP_ACCESS_CAISSE = 'tuc_access_caisse';
    public const CAP_VIEW_ORDERS = 'tuc_view_orders';
    public const CAP_UPDATE_ORDERS = 'tuc_update_orders';
    public const CAP_MANAGE_RESTAURANT = 'tuc_manage_restaurant';
    public const CAP_MANAGE_MENU = 'tuc_manage_menu';
    public const CAP_MANAGE_PROMOTIONS = 'tuc_manage_promotions';
    public const CAP_MANAGE_STAFF = 'tuc_manage_staff';
    public const CAP_AUDIBLE_ORDER_ALERTS = 'tuc_audible_order_alerts';

    private const SCHEMA_VERSION = 4;

    public static function init(): void
    {
        add_action('init', [self::class, 'synchroniser'], 1);
    }

    /** Crée les rôles une seule fois; ne leur donne jamais les capacités WP admin. */
    public static function synchroniser(): void
    {
        if ((int) get_option('tuc_roles_schema_version', 0) >= self::SCHEMA_VERSION) {
            return;
        }

        $roles = [
            'tuc_restaurateur' => [
                'label' => 'Restaurateur TUC',
                'caps' => [
                    'read', self::CAP_ACCESS_CAISSE, self::CAP_VIEW_ORDERS, self::CAP_UPDATE_ORDERS,
                    self::CAP_MANAGE_RESTAURANT, self::CAP_MANAGE_MENU, self::CAP_MANAGE_PROMOTIONS,
                    self::CAP_MANAGE_STAFF, self::CAP_AUDIBLE_ORDER_ALERTS,
                ],
            ],
            'tuc_gestionnaire' => [
                'label' => 'Gestionnaire TUC',
                'caps' => [
                    'read', self::CAP_ACCESS_CAISSE, self::CAP_VIEW_ORDERS, self::CAP_UPDATE_ORDERS,
                    self::CAP_MANAGE_RESTAURANT, self::CAP_MANAGE_MENU, self::CAP_MANAGE_PROMOTIONS,
                    self::CAP_AUDIBLE_ORDER_ALERTS,
                ],
            ],
            'tuc_caissier' => [
                'label' => 'Caissier TUC',
                'caps' => [
                    'read', self::CAP_ACCESS_CAISSE, self::CAP_VIEW_ORDERS, self::CAP_UPDATE_ORDERS,
                    self::CAP_AUDIBLE_ORDER_ALERTS,
                ],
            ],
            // Le serveur suit les commandes de son restaurant dans la caisse mobile,
            // sans accès aux réglages du restaurant, au menu ou à la gestion d'équipe.
            'tuc_serveur' => [
                'label' => 'Serveur TUC',
                'caps' => [
                    'read', self::CAP_ACCESS_CAISSE, self::CAP_VIEW_ORDERS,
                    self::CAP_UPDATE_ORDERS, self::CAP_AUDIBLE_ORDER_ALERTS,
                ],
            ],
        ];

        foreach ($roles as $slug => $definition) {
            $role = get_role($slug);
            if ($role === null) {
                add_role($slug, $definition['label'], array_fill_keys($definition['caps'], true));
                continue;
            }
            foreach ($definition['caps'] as $capability) {
                $role->add_cap($capability);
            }
        }

        // Le CPT menu garde son éditeur natif uniquement pour les administrateurs.
        // Les comptes TUC passent par les routes tenant-scoped de l’Espace Pro.
        $administrator = get_role('administrator');
        if ($administrator !== null) {
            foreach ([
                'edit_tuc_menu_item', 'read_tuc_menu_item', 'delete_tuc_menu_item',
                'edit_tuc_menu_items', 'edit_others_tuc_menu_items', 'publish_tuc_menu_items',
                'read_private_tuc_menu_items', 'delete_tuc_menu_items', 'delete_private_tuc_menu_items',
                'delete_published_tuc_menu_items', 'delete_others_tuc_menu_items',
                'edit_private_tuc_menu_items', 'edit_published_tuc_menu_items',
            ] as $capability) {
                $administrator->add_cap($capability);
            }
        }

        update_option('tuc_roles_schema_version', self::SCHEMA_VERSION, false);
    }
}
