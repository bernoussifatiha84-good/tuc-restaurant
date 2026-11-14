<?php
if (!defined('ABSPATH')) { exit; }
$peutVoirCommandes = current_user_can(TUC_Roles::CAP_VIEW_ORDERS) || current_user_can('manage_options');
$peutModifierCommandes = current_user_can(TUC_Roles::CAP_UPDATE_ORDERS) || current_user_can('manage_options');
$peutGererBoutique = current_user_can(TUC_Roles::CAP_MANAGE_RESTAURANT) || current_user_can('manage_options');
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
    <div class="tuc-pro-restaurant"><span class="tuc-status-dot" id="tuc-open-dot"></span><span id="tuc-restaurant-name"><?php esc_html_e('Espace restaurant', 'tuc-core'); ?></span></div>
    <a class="tuc-pro-logout" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"><?php esc_html_e('Déconnexion', 'tuc-core'); ?></a>
  </header>
  <main id="tuc-pro-main" class="tuc-pro-main">
    <div class="tuc-pro-heading">
      <div><p class="tuc-eyebrow"><?php esc_html_e('ESPACE PROFESSIONNEL', 'tuc-core'); ?></p><h1 id="tuc-pro-page-title"><?php esc_html_e('Votre espace', 'tuc-core'); ?></h1>
        <p class="tuc-pro-intro"><?php esc_html_e('Menu, commandes et paramètres de votre restaurant.', 'tuc-core'); ?></p></div>
      <button id="tuc-new-item" class="tuc-button tuc-button-primary" type="button"><span aria-hidden="true">＋</span> <?php esc_html_e('Ajouter un plat', 'tuc-core'); ?></button>
    </div>
    <nav id="tuc-pro-tabs" class="tuc-pro-tabs" role="tablist" aria-label="Navigation de l’espace professionnel">
      <button id="tuc-tab-menu" type="button" role="tab" aria-controls="tuc-panel-menu" aria-selected="true" tabindex="0" data-tab="menu"><?php esc_html_e('Menu', 'tuc-core'); ?></button>
      <?php if ($peutVoirCommandes) : ?><button id="tuc-tab-orders" type="button" role="tab" aria-controls="tuc-panel-orders" aria-selected="false" tabindex="-1" data-tab="orders"><?php esc_html_e('Commandes', 'tuc-core'); ?><span id="tuc-orders-count" class="tuc-tab-count"></span></button><?php endif; ?>
      <?php if ($peutGererBoutique) : ?><button id="tuc-tab-settings" type="button" role="tab" aria-controls="tuc-panel-settings" aria-selected="false" tabindex="-1" data-tab="settings"><?php esc_html_e('Boutique', 'tuc-core'); ?></button><?php endif; ?>
    </nav>
    <div id="tuc-pro-notice" class="tuc-notice" role="status" aria-live="polite" hidden></div>
    <section id="tuc-panel-menu" class="tuc-pro-panel" data-panel="menu" role="tabpanel" aria-labelledby="tuc-tab-menu" tabindex="0">
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
              <label class="tuc-field"><span><?php esc_html_e('Prix (MAD)', 'tuc-core'); ?> <b>*</b></span><input id="tuc-item-price" name="price" type="text" inputmode="decimal" maxlength="16" autocomplete="off" required placeholder="45,50" aria-describedby="tuc-item-price-aide"><small id="tuc-item-price-aide">Virgule ou point acceptés, 2 chiffres maximum après.</small></label>
              <label class="tuc-field"><span><?php esc_html_e('Promotion (%)', 'tuc-core'); ?></span><input id="tuc-item-promo" name="promo_pct" type="text" inputmode="numeric" maxlength="3" autocomplete="off" value="0"></label>
            </div>
            <label class="tuc-toggle"><input id="tuc-item-available" type="checkbox" checked><span class="tuc-toggle-ui"></span><span><?php esc_html_e('Disponible à la commande', 'tuc-core'); ?></span></label>
            <section class="tuc-options-editor" aria-labelledby="tuc-options-title">
              <div class="tuc-section-title"><div><h3 id="tuc-options-title"><?php esc_html_e('Options et suppléments', 'tuc-core'); ?></h3><p><?php esc_html_e('Taille, cuisson, accompagnements…', 'tuc-core'); ?></p></div>
                <button id="tuc-add-group" class="tuc-button tuc-button-soft" type="button">＋ <?php esc_html_e('Groupe', 'tuc-core'); ?></button></div>
              <div id="tuc-option-groups" class="tuc-option-groups"></div>
            </section>
            <section class="tuc-photo-field"><label class="tuc-field"><span><?php esc_html_e('Photo du plat', 'tuc-core'); ?></span><input id="tuc-item-photo" type="file" accept="image/jpeg,image/png,image/webp"><small><?php esc_html_e('JPEG, PNG ou WebP — 5 Mo maximum.', 'tuc-core'); ?></small></label><img id="tuc-photo-preview" alt="" hidden></section>
            <div class="tuc-editor-actions"><button class="tuc-button tuc-button-outline" type="button" data-save-status="draft"><?php esc_html_e('Enregistrer en brouillon', 'tuc-core'); ?></button><button class="tuc-button tuc-button-primary" type="button" data-save-status="publish"><?php esc_html_e('Publier le plat', 'tuc-core'); ?></button></div>
            <p class="tuc-editor-footnote"><?php esc_html_e('Un brouillon reste invisible aux clients. Archiver conserve le plat et ses commandes historiques.', 'tuc-core'); ?></p>
          </form>
        </section>
        <aside class="tuc-preview-panel" aria-label="Aperçu du plat">
          <p class="tuc-eyebrow"><?php esc_html_e('APERÇU CLIENT', 'tuc-core'); ?></p><h2><?php esc_html_e('Sur votre menu', 'tuc-core'); ?></h2>
          <article id="tuc-preview-card" class="tuc-preview-card"><div class="tuc-preview-placeholder">🍽️</div><div class="tuc-preview-copy"><h3><?php esc_html_e('Votre prochain plat', 'tuc-core'); ?></h3><p><?php esc_html_e('Saisissez un nom et un prix pour voir l’aperçu.', 'tuc-core'); ?></p><strong>— MAD</strong></div></article>
          <div class="tuc-preview-note"><span aria-hidden="true">✓</span><p><?php esc_html_e('Le total de chaque commande est toujours recalculé par le serveur.', 'tuc-core'); ?></p></div>
        </aside>
      </div>
    </section>

    <?php if ($peutVoirCommandes) : ?>
    <section id="tuc-panel-orders" class="tuc-pro-panel" data-panel="orders" role="tabpanel" aria-labelledby="tuc-tab-orders" tabindex="0" hidden>
      <div class="tuc-panel-heading"><div><p class="tuc-eyebrow"><?php esc_html_e('SERVICE', 'tuc-core'); ?></p><h2><?php esc_html_e('Commandes récentes', 'tuc-core'); ?></h2></div><button id="tuc-refresh-orders" type="button" class="tuc-button tuc-button-soft"><?php esc_html_e('Actualiser', 'tuc-core'); ?></button></div>
      <label class="tuc-order-filter"><span><?php esc_html_e('Afficher', 'tuc-core'); ?></span><select id="tuc-order-filter"><option value="toutes"><?php esc_html_e('Toutes les commandes', 'tuc-core'); ?></option><option value="nouvelle"><?php esc_html_e('À traiter', 'tuc-core'); ?></option><option value="preparation"><?php esc_html_e('En préparation', 'tuc-core'); ?></option><option value="prete"><?php esc_html_e('Prêtes', 'tuc-core'); ?></option><option value="servie"><?php esc_html_e('Terminées', 'tuc-core'); ?></option></select></label>
      <div id="tuc-orders-list" class="tuc-orders-list" aria-live="polite"><div class="tuc-loading"><?php esc_html_e('Chargement des commandes…', 'tuc-core'); ?></div></div>
    </section>
    <?php endif; ?>

    <?php if ($peutGererBoutique) : ?>
    <section id="tuc-panel-settings" class="tuc-pro-panel" data-panel="settings" role="tabpanel" aria-labelledby="tuc-tab-settings" tabindex="0" hidden>
      <div class="tuc-panel-heading"><div><p class="tuc-eyebrow"><?php esc_html_e('PARAMÈTRES', 'tuc-core'); ?></p><h2><?php esc_html_e('Votre boutique', 'tuc-core'); ?></h2></div></div>
      <form id="tuc-store-settings" class="tuc-settings-card">
        <label class="tuc-toggle"><input id="tuc-store-open" type="checkbox" checked><span class="tuc-toggle-ui"></span><span><?php esc_html_e('Accepter les commandes', 'tuc-core'); ?></span></label>
        <div class="tuc-field-row"><label class="tuc-field"><span><?php esc_html_e('Minimum de commande (MAD)', 'tuc-core'); ?></span><input id="tuc-store-minimum" type="text" inputmode="decimal" maxlength="16" autocomplete="off" placeholder="0,00"></label>
          <label class="tuc-field"><span><?php esc_html_e('Frais de livraison (MAD)', 'tuc-core'); ?></span><input id="tuc-store-fee" type="text" inputmode="decimal" maxlength="16" autocomplete="off" placeholder="0,00"></label></div>
        <fieldset class="tuc-mode-settings"><legend><?php esc_html_e('Modes de service', 'tuc-core'); ?></legend>
          <label><input type="checkbox" name="mode" value="livraison"> <?php esc_html_e('Livraison', 'tuc-core'); ?></label>
          <label><input type="checkbox" name="mode" value="emporter"> <?php esc_html_e('À emporter', 'tuc-core'); ?></label>
          <label><input type="checkbox" name="mode" value="table"> <?php esc_html_e('À table', 'tuc-core'); ?></label></fieldset>
        <label class="tuc-field" id="tuc-store-tables-wrap"><span><?php esc_html_e('Nombre de tables', 'tuc-core'); ?></span><input id="tuc-store-tables" type="number" min="0" max="500" step="1" inputmode="numeric"></label>
        <button class="tuc-button tuc-button-primary" type="submit"><?php esc_html_e('Enregistrer la boutique', 'tuc-core'); ?></button>
      </form>
      <section id="tuc-store-location" class="tuc-location-settings" aria-labelledby="tuc-store-location-title">
        <div class="tuc-panel-heading"><div><p class="tuc-eyebrow"><?php esc_html_e('DISTANCES CLIENTS', 'tuc-core'); ?></p><h3 id="tuc-store-location-title"><?php esc_html_e('Localisation de votre établissement', 'tuc-core'); ?></h3></div></div>
        <p class="tuc-location-intro"><?php esc_html_e('Enregistrez le point réel du restaurant pour que les distances affichées aux clients soient calculées correctement. Placez-vous sur place pour utiliser le GPS. La zone actuellement desservie est Casablanca.', 'tuc-core'); ?></p>
        <p id="tuc-store-location-current" class="tuc-location-current" role="status" aria-live="polite"><?php esc_html_e('Aucune localisation enregistrée.', 'tuc-core'); ?></p>
        <div class="tuc-location-method">
          <h4><?php esc_html_e('Option 1 — GPS sur place', 'tuc-core'); ?></h4>
          <p><?php esc_html_e('Votre navigateur demandera l’autorisation après ce clic. Une précision GPS supérieure à 100 mètres devra être réessayée.', 'tuc-core'); ?></p>
          <button id="tuc-capture-location" class="tuc-button tuc-button-soft" type="button"><?php esc_html_e('Capturer ma position actuelle', 'tuc-core'); ?></button>
        </div>
        <div class="tuc-location-method">
          <h4><?php esc_html_e('Option 2 — Lien Google Maps ou Business', 'tuc-core'); ?></h4>
          <label class="tuc-field" for="tuc-store-google-link"><span><?php esc_html_e('Lien Google Maps', 'tuc-core'); ?></span><input id="tuc-store-google-link" type="url" inputmode="url" autocomplete="url" placeholder="https://www.google.com/maps/..." aria-describedby="tuc-store-google-help"></label>
          <p id="tuc-store-google-help" class="tuc-location-help"><?php esc_html_e('Le lien est analysé dans votre navigateur, sans API ni service externe. Les coordonnées peuvent être extraites si elles figurent dans l’URL. Les liens courts (par exemple maps.app.goo.gl) ne les contiennent généralement pas. Dans ce cas, utilisez le GPS sur place ou ouvrez le lieu dans Google Maps et copiez le lien complet avec le repère.', 'tuc-core'); ?></p>
          <button id="tuc-parse-google-link" class="tuc-button tuc-button-soft" type="button"><?php esc_html_e('Extraire les coordonnées du lien', 'tuc-core'); ?></button>
          <label id="tuc-location-confirm-wrap" class="tuc-location-confirm" hidden><input id="tuc-location-confirm" type="checkbox"> <span id="tuc-location-confirm-text"><?php esc_html_e('J’ai vérifié que le repère correspond bien à l’adresse de mon établissement.', 'tuc-core'); ?></span></label>
        </div>
        <div class="tuc-location-method">
          <h4><?php esc_html_e('Option 3 — Coordonnées copiées', 'tuc-core'); ?></h4>
          <p><?php esc_html_e('Si vous ne souhaitez pas utiliser le GPS, copiez la latitude et la longitude du repère de votre restaurant dans Google Maps. Une adresse écrite seule ne suffit pas.', 'tuc-core'); ?></p>
          <div class="tuc-field-row">
            <label class="tuc-field" for="tuc-store-lat"><span><?php esc_html_e('Latitude', 'tuc-core'); ?></span><input id="tuc-store-lat" type="number" min="-90" max="90" step="0.000001" inputmode="decimal" placeholder="33.590000"></label>
            <label class="tuc-field" for="tuc-store-lng"><span><?php esc_html_e('Longitude', 'tuc-core'); ?></span><input id="tuc-store-lng" type="number" min="-180" max="180" step="0.000001" inputmode="decimal" placeholder="-7.628000"></label>
          </div>
          <button id="tuc-use-manual-coordinates" class="tuc-button tuc-button-soft" type="button"><?php esc_html_e('Prévisualiser ces coordonnées', 'tuc-core'); ?></button>
        </div>
        <p id="tuc-store-location-preview" class="tuc-location-preview" aria-live="polite"></p>
        <p id="tuc-store-location-notice" class="tuc-location-notice" role="status" aria-live="polite"></p>
        <button id="tuc-save-location" class="tuc-button tuc-button-primary" type="button" disabled><?php esc_html_e('Enregistrer cette localisation', 'tuc-core'); ?></button>
      </section>
    </section>
    <?php endif; ?>
  </main>
  <?php wp_footer(); ?>
</body>
</html>
