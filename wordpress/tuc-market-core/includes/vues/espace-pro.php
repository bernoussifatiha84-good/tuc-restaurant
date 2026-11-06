<?php
if (!defined('ABSPATH')) { exit; }
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#12372a">
  <title><?php esc_html_e('Espace Pro — TUC', 'tuc-core'); ?></title>
  <?php wp_head(); ?>
</head>
<body class="tuc-pro-page">
  <a class="tuc-skip" href="#tuc-pro-main"><?php esc_html_e('Aller au contenu', 'tuc-core'); ?></a>
  <header class="tuc-pro-header">
    <a class="tuc-pro-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="TUC — accueil">TUC<span>PRO</span></a>
    <div class="tuc-pro-restaurant"><span class="tuc-status-dot"></span><span id="tuc-restaurant-name"><?php esc_html_e('Espace restaurant', 'tuc-core'); ?></span></div>
    <a class="tuc-pro-logout" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"><?php esc_html_e('Déconnexion', 'tuc-core'); ?></a>
  </header>
  <main id="tuc-pro-main" class="tuc-pro-main">
    <div class="tuc-pro-heading">
      <div><p class="tuc-eyebrow"><?php esc_html_e('ESPACE PROFESSIONNEL', 'tuc-core'); ?></p><h1><?php esc_html_e('Votre menu', 'tuc-core'); ?></h1>
        <p class="tuc-pro-intro"><?php esc_html_e('Gérez vos plats, options et photos. Les prix sont recalculés côté serveur pour chaque commande.', 'tuc-core'); ?></p></div>
      <button id="tuc-new-item" class="tuc-button tuc-button-primary" type="button"><span aria-hidden="true">＋</span> <?php esc_html_e('Ajouter un plat', 'tuc-core'); ?></button>
    </div>
    <div id="tuc-pro-notice" class="tuc-notice" role="status" aria-live="polite" hidden></div>
    <div class="tuc-pro-grid">
      <section class="tuc-menu-panel" aria-label="Menu du restaurant">
        <div id="tuc-menu-loading" class="tuc-loading" role="status"><?php esc_html_e('Chargement de votre menu…', 'tuc-core'); ?></div>
        <div id="tuc-menu-list" class="tuc-menu-list"></div>
        <form id="tuc-menu-editor" class="tuc-editor" hidden>
          <div class="tuc-editor-heading"><div><p class="tuc-eyebrow" id="tuc-editor-mode"><?php esc_html_e('NOUVEAU PLAT', 'tuc-core'); ?></p><h2 id="tuc-editor-title"><?php esc_html_e('Ajouter au menu', 'tuc-core'); ?></h2></div>
            <button id="tuc-editor-close" class="tuc-icon-button" type="button" aria-label="Fermer">×</button></div>
          <input id="tuc-item-id" type="hidden" value="">
          <label class="tuc-field"><span><?php esc_html_e('Nom du plat', 'tuc-core'); ?> <b>*</b></span><input id="tuc-item-name" name="title" type="text" maxlength="100" required autocomplete="off" placeholder="Ex. Tajine de poulet"></label>
          <label class="tuc-field"><span><?php esc_html_e('Description', 'tuc-core'); ?></span><textarea id="tuc-item-description" name="description" rows="3" maxlength="700" placeholder="Quelques mots sur votre plat"></textarea></label>
          <div class="tuc-field-row">
            <label class="tuc-field"><span><?php esc_html_e('Prix (MAD)', 'tuc-core'); ?> <b>*</b></span><input id="tuc-item-price" name="price" type="number" min="0" max="100000" step="0.01" inputmode="decimal" required placeholder="0.00"></label>
            <label class="tuc-field"><span><?php esc_html_e('Promotion (%)', 'tuc-core'); ?></span><input id="tuc-item-promo" name="promo_pct" type="number" min="0" max="100" step="1" inputmode="numeric" value="0"></label>
          </div>
          <label class="tuc-toggle"><input id="tuc-item-available" type="checkbox" checked><span class="tuc-toggle-ui"></span><span><?php esc_html_e('Disponible à la commande', 'tuc-core'); ?></span></label>
          <section class="tuc-options-editor" aria-labelledby="tuc-options-title">
            <div class="tuc-section-title"><div><h3 id="tuc-options-title"><?php esc_html_e('Options et suppléments', 'tuc-core'); ?></h3><p><?php esc_html_e('Taille, cuisson, accompagnements…', 'tuc-core'); ?></p></div>
              <button id="tuc-add-group" class="tuc-button tuc-button-soft" type="button">＋ <?php esc_html_e('Groupe', 'tuc-core'); ?></button></div>
            <div id="tuc-option-groups" class="tuc-option-groups"></div>
          </section>
          <section class="tuc-photo-field"><label class="tuc-field"><span><?php esc_html_e('Photo du plat', 'tuc-core'); ?></span><input id="tuc-item-photo" type="file" accept="image/jpeg,image/png,image/webp"><small><?php esc_html_e('JPEG, PNG ou WebP — 5 Mo maximum.', 'tuc-core'); ?></small></label><img id="tuc-photo-preview" alt="" hidden></section>
          <div class="tuc-editor-actions"><button class="tuc-button tuc-button-outline" type="button" data-save-status="draft"><?php esc_html_e('Enregistrer en brouillon', 'tuc-core'); ?></button><button class="tuc-button tuc-button-primary" type="button" data-save-status="publish"><?php esc_html_e('Publier le plat', 'tuc-core'); ?></button></div>
          <p class="tuc-editor-footnote"><?php esc_html_e('Un brouillon reste invisible aux clients. La suppression archive le plat en brouillon.', 'tuc-core'); ?></p>
        </form>
      </section>
      <aside class="tuc-preview-panel" aria-label="Aperçu du plat">
        <p class="tuc-eyebrow"><?php esc_html_e('APERÇU CLIENT', 'tuc-core'); ?></p><h2><?php esc_html_e('Sur votre menu', 'tuc-core'); ?></h2>
        <article id="tuc-preview-card" class="tuc-preview-card"><div class="tuc-preview-placeholder">🍽️</div><div class="tuc-preview-copy"><h3><?php esc_html_e('Votre prochain plat', 'tuc-core'); ?></h3><p><?php esc_html_e('Saisissez un nom et un prix pour voir l’aperçu.', 'tuc-core'); ?></p><strong>— MAD</strong></div></article>
        <div class="tuc-preview-note"><span aria-hidden="true">✓</span><p><?php esc_html_e('Le total de chaque commande est toujours recalculé par le serveur.', 'tuc-core'); ?></p></div>
      </aside>
    </div>
  </main>
  <?php wp_footer(); ?>
</body>
</html>
