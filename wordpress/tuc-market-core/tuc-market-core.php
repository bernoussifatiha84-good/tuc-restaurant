<?php
/**
 * Plugin Name: TUC Market Core
 * Description: Cœur métier TUC : fiches restaurant, quartiers, commandes, QR de table, santé du site. Comme partikulier-core, il porte le module de santé que le déploiement interroge.
 * Version: 0.8.6
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: TUC
 * Text Domain: tuc-core
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const TUC_CORE_VERSION = '0.8.6';
const TUC_CORE_FILE    = __FILE__;
define('TUC_CORE_DIR', plugin_dir_path(__FILE__));
define('TUC_CORE_URL', plugin_dir_url(__FILE__));

require_once TUC_CORE_DIR . 'includes/class-tuc-health.php';
require_once TUC_CORE_DIR . 'includes/class-tuc-activator.php';
require_once TUC_CORE_DIR . 'includes/class-tuc-roles.php';
require_once TUC_CORE_DIR . 'includes/class-tuc-acces.php';
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-options.php';
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-espace-pro.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once TUC_CORE_DIR . 'includes/class-tuc-options-cli.php';
    WP_CLI::add_command('tuc-options migrate', [TUC_Options_CLI::class, 'migrate']);
    WP_CLI::add_command('tuc-options rollback', [TUC_Options_CLI::class, 'rollback']);
}

// modules métier — tout dans un seul plugin, pas dix
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-cpt.php';       // 1. CPT resto + menu
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-commande.php';  // 4. REST commande
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-qr.php';        // 5. QR table
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-geo.php';       // 8. quartiers
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-rewrite.php';   // 1b. slug racine /kfc
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-compte.php';    // 2b. comptes par numéro WhatsApp
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-wa-message.php'; // 2c. message pré-rempli (panier en texte)
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-push.php';      // 6a. Web Push natif (RFC 8291)
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-caisse.php';   // 6b. PWA de caisse — le canal de commande
require_once TUC_CORE_DIR . 'includes/modules/class-tuc-security-headers.php'; // en-têtes HTTP défensifs

/**
 * Activation : on ne devine pas, on prépare le minimum et on le vérifie.
 */
register_activation_hook(__FILE__, ['TUC_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['TUC_Activator', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    TUC_Roles::init();

    // le module de santé doit répondre même si le thème n'est pas activé
    TUC_Health::init();

    // le métier vit ici, pas dans le thème : il survit à un changement de design
    TUC_CPT::init();
    TUC_Commande::init();
    TUC_QR::init();
    TUC_Geo::init();
    TUC_Rewrite::init();
    TUC_Compte::init();
    TUC_Push::init();          // clés VAPID disponibles dès la première visite
    TUC_Caisse::init();        // module 6 — le P0 : sans elle, une commande n'arrive nulle part
    TUC_Espace_Pro::init();
    TUC_Security_Headers::init();
}, 5);

/**
 * Le noyau reste muet côté public tant que le thème n'est pas en place.
 * Tout ce qui est métier (CPT, REST commandes, QR) viendra dans des includes.
 */
add_action('init', static function (): void {
    load_plugin_textdomain('tuc-core', false, dirname(plugin_basename(TUC_CORE_FILE)) . '/languages');
});
