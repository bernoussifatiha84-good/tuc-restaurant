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

    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff', true);
        header('X-Frame-Options: SAMEORIGIN', true);
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        header('Permissions-Policy: geolocation=(self), camera=(), microphone=()', true);

        // HSTS is meaningful only over verified HTTPS. TLS termination and the
        // canonical host remain deployment concerns; never emit it on local HTTP.
        if (is_ssl() && function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production') {
            header('Strict-Transport-Security: max-age=31536000', true);
        }
    }
}
