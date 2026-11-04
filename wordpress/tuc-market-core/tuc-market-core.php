<?php
/**
 * Plugin Name: TUC Market Core
 * Description: Cœur métier TUC : fiches restaurant, quartiers, commandes, QR de table, santé du site. Comme partikulier-core, il porte le module de santé que le déploiement interroge.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: TUC
 * Text Domain: tuc-core
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUC_CORE_VERSION = '0.1.0';
const TUC_CORE_FILE    = __FILE__;
define('TUC_CORE_DIR', plugin_dir_path(__FILE__));

require_once TUC_CORE_DIR . 'includes/class-tuc-health.php';
require_once TUC_CORE_DIR . 'includes/class-tuc-activator.php';

/**
 * Activation : on ne devine pas, on prépare le minimum et on le vérifie.
 */
register_activation_hook(__FILE__, ['TUC_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['TUC_Activator', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    // le module de santé doit répondre même si le thème n'est pas activé
    TUC_Health::init();
}, 5);

/**
 * Le noyau reste muet côté public tant que le thème n'est pas en place.
 * Tout ce qui est métier (CPT, REST commandes, QR) viendra dans des includes.
 */
add_action('init', static function (): void {
    load_plugin_textdomain('tuc-core', false, dirname(plugin_basename(TUC_CORE_FILE)) . '/languages');
});
