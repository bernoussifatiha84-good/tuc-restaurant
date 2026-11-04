<?php
/**
 * Module 5 — Générateur de QR.
 *
 * Encode une URL, jamais un numéro : `tuc.ma/{slug}?t={table}`.
 * Le numéro WhatsApp du restaurant est une DONNÉE modifiable, pas une constante
 * gravée dans le QR. Si le resto change de numéro, le QR reste valable.
 *
 * ⚠️ POURQUOI DANS LE PLUGIN : le QR est généré à la volée (PNG) via GD.
 * Dans un thème, le jour où le thème change, les QR déjà imprimés pointent vers
 * une route qui n'existe plus. Le plugin garantit que la route survit au design.
 *
 * Le restaurant IMPRIME lui-même : TUC ne produit ni QR payant ni chevalet.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_QR
{
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
        // /qr/{slug}?t=12  ->  image PNG
        add_rewrite_rule('^qr/([a-z0-9-]+)/?$', 'index.php?tuc_qr=$matches[1]', 'top');
    }

    public static function routes(): void
    {
        register_rest_route('tuc/v1', '/qr/(?P<slug>[a-z0-9-]+)', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => [
                'slug'  => ['required' => true, 'sanitize_callback' => 'sanitize_title'],
                'table' => ['default' => '0', 'sanitize_callback' => 'absint'],
                'taille' => ['default' => 600, 'sanitize_callback' => 'absint'],
            ],
            'callback' => [self::class, 'png'],
        ]);
    }

    /** URL encodée dans le QR. Une URL, pas un numéro. */
    public static function cible(string $slug, int $table = 0): string
    {
        $url = home_url('/' . $slug . '/');
        return $table > 0 ? add_query_arg('t', $table, $url) : $url;
    }

    /**
     * Rend le PNG. Pas de dépendance externe : GD suffit pour un QR de table.
     * (implémentation complète du QR dans une v2 ; ici on expose la structure
     * et on renvoie un SVG lisible qui contient l'URL, sans librairie.)
     */
    public static function png(WP_REST_Request $req)
    {
        $slug  = (string) $req->get_param('slug');
        $table = (int) $req->get_param('table');
        $url   = self::cible($slug, $table);

        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=31536000, immutable');

        printf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 300 300">'
            . '<rect width="300" height="300" fill="#fff"/>'
            . '<text x="150" y="120" font-family="sans-serif" font-size="11" text-anchor="middle" fill="#212121">%2$s</text>'
            . '<text x="150" y="150" font-family="sans-serif" font-size="9" text-anchor="middle" fill="#8B8B8B">table %3$d</text>'
            . '<text x="150" y="280" font-family="sans-serif" font-size="10" text-anchor="middle" fill="#0E9E7E">TUC</text>'
            . '</svg>',
            (int) $req->get_param('taille'),
            esc_html($url),
            $table
        );
        exit;
    }
}
