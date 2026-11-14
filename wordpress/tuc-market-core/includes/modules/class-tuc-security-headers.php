<?php
/** HTTP security headers for the public WordPress application. */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Security_Headers
{
    public static function init(): void
    {
        add_action('send_headers', [self::class, 'send'], 100);
        add_filter('wp_robots', [self::class, 'robots']);

        // XML-RPC n'est utilisé par aucun chemin du produit (0 occurrence dans le
        // plugin et le thème), et il reste ouvert sur WordPress par défaut : c'est
        // un vecteur d’amplification pour les attaques par mot de passe, via
        // system.multicall. On le ferme pour tous les environnements.
        add_filter('xmlrpc_enabled', '__return_false');

        // Mesure du 13/11/2026, controle comparatif entre /uat (corrige) et le site
        // pilote (non corrige) : `xmlrpc_enabled` __return_false ne supprime PAS
        // l'introspection — system.listMethods repond le catalogue complet, 4 224 o,
        // sur les deux. Seule une methode sans contexte de blog est refusee. Le
        // catalogue lui-meme est le renseignement que cherche un attaquant (liste des
        // methodes, versions, vecteurs), donc on vide la table des methodes : plus
        // rien ne se sert, y compris la liste.
        add_filter('xmlrpc_methods', static fn(): array => array());

        // Le menu public est lu par des visiteurs qui veulent le prix du jour, et
        // LiteSpeed le servait depuis une copie aged de 117 s avec max-age=604800
        // (mesure du 13/11/2026 sur /uat) : une semaine de prix faux apres une
        // modification. Deux greffons, un par bout du probleme : la duree de vie
        // de la reponse, et un signal de purge a chaque ecriture de prix.
        add_filter('rest_post_dispatch', [self::class, 'tune_menu_response'], 10, 3);
        add_action('updated_post_meta', [self::class, 'purge_menu_on_price_change'], 10, 4);
        add_action('added_post_meta', [self::class, 'purge_menu_on_price_change'], 10, 4);
    }

    /** Lifetime we are willing to serve a stale public menu, in seconds. */
    public const MENU_TTL = 60;

    /** Route prefix of the public menu JSON — the one entry point this file re-times. */
    public const MENU_ROUTE = '/tuc/v1/menu/';

    /**
     * The same prefix, in the one form that produces a URL a client can actually fetch.
     *
     * The distinction is not cosmetic and it cost five versions. `rest_post_dispatch`
     * hands us a route name (`/tuc/v1/menu/(?P<slug>…)`) and `home_url()` hands us a path
     * under the site root — but the REST server lives behind `rest_url()`, which is the
     * only one of the two that knows about `/wp-json/`, about `?rest_route=`, and about a
     * site installed in a subdirectory. On /uat, measured on 2026-11-13:
     *   home_url('/tuc/v1/menu/kfc') -> 404 (HTML, and LiteSpeed even caches that 404)
     *   rest_url('tuc/v1/menu/kfc')  -> 200 (the JSON the browser reads)
     * A purge list built on the first form never drops the entry the customer reads, and
     * every unit test on the list itself stayed green.
     */
    public const MENU_ROUTE_PATH = 'tuc/v1/menu/';

    public static function is_menu_route(string $route): bool
    {
        return str_starts_with($route, self::MENU_ROUTE);
    }

    /**
     * Pure decision table for the menu JSON cache headers, same shape as headers_for().
     *
     * A failed lookup must not be cached at all: a 404 for one bad slug should not
     * shadow a real menu. The success case is `no-cache` for whoever reads the cart
     * back, while LiteSpeed keeps serving from its own 60 s copy.
     *
     * @return array<string, string>
     */
    public static function menu_cache_headers(bool $is_error = false): array
    {
        if ($is_error) {
            return ['Cache-Control' => 'no-store'];
        }

        return [
            // LiteSpeed owns the lifetime, through its own override header. The
            // browser is told `no-cache`, not `max-age`: the PWA re-reads this
            // endpoint to refresh a cart after a 409 price conflict, and any
            // browser-side freshness window — 60 s included — shows a wrong total
            // at the only moment where one must not be wrong (CI run
            // 43408480964, tests/checkout-scenarios.js:568). Revalidation is cheap:
            // LiteSpeed still answers from its cache.
            'X-LiteSpeed-Cache-Control' => 'max-age=' . self::MENU_TTL,
            'Cache-Control'             => 'no-cache',
        ];
    }

    /**
     * The URI LiteSpeed should drop after a price change, or null when nothing
     * should be emitted. Pure so the guard rails are testable without a cache.
     *
     * A CLI or cron write has no response to attach a header to; pretending to
     * purge from the command line would purge nothing and hide that fact.
     */
    public static function menu_purge_path(?string $slug, bool $skip_when_no_response = false): ?string
    {
        if ($skip_when_no_response || null === $slug || '' === $slug) {
            return null;
        }

        // $slug comes from post_name; refuse anything that could break out of a header.
        if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,199}$/', $slug)) {
            return null;
        }

        return rest_url(self::MENU_ROUTE_PATH . $slug);
    }

    /**
     * @param \WP_REST_Response|\WP_Error $result
     * @param \WP_REST_Server             $server
     * @param \WP_REST_Request             $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function tune_menu_response($result, $server, $request)
    {
        $route = (is_object($request) && method_exists($request, 'get_route'))
            ? (string) $request->get_route()
            : '';

        if ('' === $route || !self::is_menu_route($route) || headers_sent() || (defined('WP_CLI') && WP_CLI)) {
            return $result;
        }

        foreach (self::menu_cache_headers(is_wp_error($result)) as $name => $value) {
            header($name . ': ' . $value, true);
        }

        return $result;
    }

    /**
     * Signature imposed by core, in this order: `updated_post_meta`, `added_post_meta`
     * fire as (meta_id, object_id, meta_key, meta_value). A typed declaration in the
     * wrong slot is a fatal error on every price write — the CI smoke test caught it
     * on 2026-11-13, which is the only reason anyone can trust this hook today.
     *
     * @param int|string $meta_id   unused
     * @param int|string $post_id
     * @param string     $meta_key
     * @param mixed      $meta_value
     */
    /**
     * Purge calls to make against the LiteSpeed plugin, as closures, decided apart
     * so the choice is testable without the plugin being installed: no cache plugin
     * must not become a fatal error, and a missing cache must not look like a purge.
     *
     * @return array<int, callable>
     */
    public static function purge_requests_for(string $url): array
    {
        $calls = [];

        if (function_exists('lite_speed_purge_url')) {
            $calls[] = static function () use ($url): void { lite_speed_purge_url($url); };
        }

        // The plugin's own hook accepts a URL and covers the tagged entries of that
        // URL; it exists whether or not the helper function is loaded.
        if (has_action('litespeed_purge_url') !== false) {
            $calls[] = static function () use ($url): void { do_action('litespeed_purge_url', $url); };
        }

        return $calls;
    }

    /**
     * The URIs one price write must drop from the edge cache, in order, deduplicated.
     *
     * Pure on purpose, because that is where the gap was: the JSON route alone is not
     * enough. The rendered restaurant page prints the prices as well (60 money-looking
     * values measured in /uat/menu/kfc/ HTML, served for 604800 s by
     * litespeed.conf.cache-ttl_frontpage-style defaults), so a purge list that stops at
     * `home_url('/tuc/v1/menu/<slug>')` leaves exactly the page the customer reads stale
     * for a week — while every unit test on the JSON route stays green.
     *
     * @return list<string>
     */
    public static function purge_urls_for(string $route_url, string $item_url = '', string $restaurant_url = ''): array
    {
        $urls = [];

        foreach ([ $route_url, $item_url, $restaurant_url ] as $url) {
            if ('' !== $url && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    public static function purge_menu_on_price_change($meta_id, $post_id, $meta_key = '', $meta_value = null): void
    {
        if ('tuc_prix' !== (string) $meta_key || !function_exists('get_post_field')) {
            return;
        }

        $skip = (defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON);
        $resto_id = (int) get_post_meta((int) $post_id, 'tuc_resto_id', true);
        $path = self::menu_purge_path($resto_id ? (string) get_post_field('post_name', $resto_id) : null, $skip);

        if (null === $path || headers_sent()) {
            return;
        }

        // Three URIs, not one: the JSON route, the dish's own page, and — the one this
        // function used to miss — the rendered menu page of the restaurant, which is what
        // a customer actually reads. The header only reaches the current response, so it
        // cannot help a write coming from WP-CLI, the CSV import or a cron run; asking the
        // plugin directly works from any context when the plugin is there.
        $resto_path = $resto_id ? get_permalink($resto_id) : false;
        $urls = self::purge_urls_for(
            (string) $path,
            is_string(get_permalink((int) $post_id)) ? (string) get_permalink((int) $post_id) : '',
            is_string($resto_path) ? $resto_path : ''
        );

        if ([] === $urls) {
            return;
        }

        // Best effort by design: the header is ignored when LiteSpeed is not in
        // front of PHP, and the short lifetime above already bounds the damage.
        header('X-LiteSpeed-Purge: ' . implode(', ', $urls), false);

        foreach ($urls as $url) {
            foreach (self::purge_requests_for($url) as $call) {
                $call();
            }
        }
    }

    /**
     * Pure decision table for robots directives, same shape as headers_for().
     *
     * The two tables fail in OPPOSITE directions on purpose. HSTS withheld on an
     * unknown environment costs nothing; a noindex emitted by mistake on the real
     * site removes it from search results, which costs the business. So an unknown
     * environment must never produce a noindex, while a known non-production one
     * always must — an environment de test n'a aucune raison d'être indexée.
     *
     * @return array<string, bool>
     */
    public static function robots_for(string $environment): array
    {
        if (in_array($environment, ['local', 'development', 'staging'], true)) {
            return ['noindex' => true];
        }

        return [];
    }

    /**
     * @param array<string, bool> $robots
     * @return array<string, bool>
     */
    public static function robots(array $robots): array
    {
        // Unknown environment => withhold, exactly as headers_for() does for HSTS.
        $environment = function_exists('wp_get_environment_type')
            ? wp_get_environment_type()
            : '';

        return array_merge($robots, self::robots_for($environment));
    }

    /**
     * Pure decision table: which headers apply for a given transport/environment.
     *
     * Split out from header() so the environment-dependent branch stays testable
     * without TLS or a second WordPress install. Changing WP_ENVIRONMENT_TYPE on
     * a host changes what that host emits; that must never be a silent edit.
     *
     * @return array<string, string>
     */
    public static function headers_for(bool $ssl, string $environment): array
    {
        $headers = [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Permissions-Policy'      => 'geolocation=(self), camera=(), microphone=()',
        ];

        // HSTS is meaningful only over verified HTTPS, and only on the public
        // production site. TLS termination and the canonical host remain
        // deployment concerns; never emit it on local HTTP or on staging.
        if ($ssl && $environment === 'production') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        // A test environment has no reason to be crawled. The meta tag written by
        // robots_for() covers the document; the header covers everything the
        // document does not (PDFs, feeds, cached copies) and survives a theme that
        // drops the head. Withheld on an unknown environment, for the opposite
        // reason HSTS is withheld there: a stray noindex on production is not a
        // hardening detail, it is a disappearance.
        if (in_array($environment, ['local', 'development', 'staging'], true)) {
            $headers['X-Robots-Tag'] = 'noindex';
        }

        return $headers;
    }

    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        // Unknown environment => withhold HSTS. Fail-closed for an emitter means
        // sending nothing, unlike a writer where it means refusing to run.
        $environment = function_exists('wp_get_environment_type')
            ? wp_get_environment_type()
            : '';

        foreach (self::headers_for(is_ssl(), $environment) as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }
}
