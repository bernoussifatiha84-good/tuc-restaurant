<?php
/**
 * Module 5 — QR par restaurant, par table, par mode de service.
 *
 * Trois familles de QR, comme demandé :
 *   table      tuc.ma/kfc?t=12        commande à table
 *   emporter   tuc.ma/kfc?mode=emporter
 *   livraison  tuc.ma/kfc?mode=livraison
 *
 * Le QR encode toujours une URL, jamais un numéro. Le numéro WhatsApp du
 * restaurant est une donnée modifiable : s'il change, les QR restent valables.
 * Le restaurant IMPRIME lui-même — TUC ne produit ni QR payant ni chevalet.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_QR
{
    public const MODES = ['table', 'emporter', 'livraison'];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('init', [self::class, 'rewrite']);
        add_filter('query_vars', static function (array $v): array {
            $v[] = 'tuc_qr';
            return $v;
        });
    }

    public static function rewrite(): void
    {
        add_rewrite_rule('^qr/([a-z0-9-]+)/?$', 'index.php?tuc_qr=$matches[1]', 'top');
    }

    public static function routes(): void
    {
        register_rest_route('tuc/v1', '/qr/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [self::class, 'planche'],
        ]);
    }

    /**
     * Cible encodée dans le QR.
     * Le paramètre `m` transporte le MODE et la référence que n8n lira.
     */
    public static function cible(string $slug, string $mode = 'emporter', int $table = 0): string
    {
        $url = home_url('/' . $slug . '/');

        if ($mode === 'table') {
            return add_query_arg('t', max(1, $table), $url);
        }

        return add_query_arg('mode', $mode, $url);
    }

    /**
     * Planche de QR d'un restaurant : une entrée par table + les deux modes.
     * Format de sortie : une liste prête à imprimer (le resto imprime).
     */
    public static function planche(WP_REST_Request $req)
    {
        $slug = sanitize_title((string) $req->get_param('slug'));
        $resto = get_page_by_path($slug, OBJECT, 'tuc_resto');

        if (!$resto instanceof WP_Post) {
            return new WP_Error('tuc_resto', 'Restaurant inconnu.', ['status' => 404]);
        }

        $nb_tables = max(1, (int) get_post_meta($resto->ID, 'tuc_tables', true));

        $qr = [];

        // une entrée par table
        for ($t = 1; $t <= $nb_tables; $t++) {
            $qr[] = [
                'type'  => 'table',
                'table' => $t,
                'url'   => self::cible($slug, 'table', $t),
                'image' => add_query_arg(['table' => $t, 'taille' => 600], home_url('/qr/' . $slug . '/')),
            ];
        }

        // une entrée par mode, sans table
        foreach (['emporter', 'livraison'] as $mode) {
            $qr[] = [
                'type'  => $mode,
                'table' => 0,
                'url'   => self::cible($slug, $mode),
                'image' => add_query_arg(['taille' => 600], home_url('/qr/' . $slug . '/')),
            ];
        }

        return new WP_REST_Response([
            'resto'  => $resto->post_title,
            'slug'   => $slug,
            'tables' => $nb_tables,
            'qr'     => $qr,
        ], 200);
    }
}
