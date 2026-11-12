<?php
if (!defined('ABSPATH')) { exit; }
?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php esc_html_e('Accès refusé — TUC', 'tuc-core'); ?></title><?php wp_head(); ?></head>
<body style="margin:0;background:#f4f3ed;color:#17271f;font:16px/1.5 system-ui,sans-serif"><main style="max-width:560px;margin:12vh auto;padding:32px;background:#fff;border-radius:20px"><p style="color:#66806e;font-weight:700">TUC · ESPACE PRO</p><h1><?php esc_html_e('Aucun restaurant associé', 'tuc-core'); ?></h1><p><?php esc_html_e('Votre compte ne dispose pas encore d’un accès à un restaurant publié. Contactez un administrateur TUC pour qu’il vous affecte un rôle et un restaurant.', 'tuc-core'); ?></p><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Retour à l’accueil', 'tuc-core'); ?></a></main><?php wp_footer(); ?></body></html>
