<?php
/**
 * Module 1b — slug SEO à la racine : tuc.ma/kfc
 *
 * Pourquoi pas `'rewrite' => ['slug' => '']` ? WordPress retombe alors sur le nom
 * du type de contenu et produit /tuc_resto/kfc/. Une règle générique `^([^/]+)/?$`
 * en 'top' avalerait toutes les pages du site.
 *
 * Solution retenue : on laisse WordPress résoudre normalement, puis on intercepte
 * sur le filtre `request`. Si le premier segment correspond à un resto publié,
 * on réécrit la requête. Aucune règle globale, aucun conflit avec les pages.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Rewrite
{
    /** Cache mémoire : une requête = une recherche de slug. */
    private static array $cache = [];

    public static function init(): void
    {
        add_filter('request', [self::class, 'intercepter'], 5);
        add_filter('post_type_link', [self::class, 'lien'], 10, 2);
        add_filter('wp_unique_post_slug', [self::class, 'reserver_slug'], 10, 4);
    }

    /**
     * /kfc  →  requête sur tuc_resto=kfc
     * /kfc avec un segment inconnu reste une 404 normale.
     */
    public static function intercepter(array $vars): array
    {
        // ne pas toucher à l'admin, à l'API REST ni aux fichiers
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $vars;
        }
        // si WordPress a déjà résolu quelque chose, on ne discute pas
        if (!empty($vars['p']) || !empty($vars['page_id']) || !empty($vars['pagename'])
            || !empty($vars['post_type']) || !empty($vars['attachment'])
            || !empty($vars['feed']) || !empty($vars['category_name']) || !empty($vars['name'])) {
            return $vars;
        }

        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

        // on enlève le préfixe d'installation éventuel (/uat)
        $home = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($path, $home)) {
            $path = trim(substr($path, strlen($home)), '/');
        }

        if ($path === '' || str_contains($path, '/')) {
            return $vars;
        }

        $slug = sanitize_title($path);
        if ($slug === '') {
            return $vars;
        }

        $id = self::trouver($slug);
        if ($id === 0) {
            return $vars;
        }

        return [
            'tuc_resto' => $slug,
            'post_type' => 'tuc_resto',
            'name'      => $slug,
        ];
    }

    public static function trouver(string $slug): int
    {
        if (array_key_exists($slug, self::$cache)) {
            return self::$cache[$slug];
        }

        $q = new WP_Query([
            'post_type'              => 'tuc_resto',
            'name'                   => $slug,
            'post_status'            => 'publish',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $id = $q->posts ? (int) $q->posts[0] : 0;
        self::$cache[$slug] = $id;

        return $id;
    }

    /** Le permalien affiché et copié par le restaurant : home_url('/kfc/') */
    public static function lien(string $url, WP_Post $post): string
    {
        if ($post->post_type !== 'tuc_resto') {
            return $url;
        }
        return home_url('/' . $post->post_name . '/');
    }

    /**
     * Un resto ne doit pas prendre le slug d'une page existante :
     * /contact, /cgv, /a-propos… restent réservés.
     */
    public static function reserver_slug(string $slug, int $post_id, string $post_status, string $post_type): string
    {
        if ($post_type !== 'tuc_resto') {
            return $slug;
        }

        // si une page porte déjà ce slug, on suffixe le resto
        $conflit = get_page_by_path($slug, OBJECT, 'page');
        if ($conflit instanceof WP_Post) {
            return $slug . '-resto';
        }

        // mots réservés par WordPress ou par TUC
        $reserves = ['admin', 'wp-admin', 'wp-json', 'api', 'qr', 'commande', 'panier',
                     'connexion', 'promos', 'quartier', 'restaurant', 'mahlaba'];
        if (in_array($slug, $reserves, true)) {
            return $slug . '-resto';
        }

        return $slug;
    }
}
