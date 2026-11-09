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
