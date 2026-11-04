<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TUC_Activator
{
    public static function activate(): void
    {
        // le thème doit exister : sinon l'admin se retrouve sur un thème blanc
        $themes = wp_get_themes();
        if (isset($themes['tuc-theme'])) {
            switch_theme('tuc-theme');
        }

        // permaliens jolis : sans ça /wp-json/ casse et les slugs SEO tombent en 404
        if (get_option('permalink_structure') === '') {
            update_option('permalink_structure', '/%postname%/');
        }

        flush_rewrite_rules();
        update_option('tuc_core_version', TUC_CORE_VERSION);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
