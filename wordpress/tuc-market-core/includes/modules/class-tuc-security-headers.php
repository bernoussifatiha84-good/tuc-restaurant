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
