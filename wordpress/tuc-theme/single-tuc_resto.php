<?php
/**
 * Fiche restaurant — la page qu'ouvre le QR de table.
 * Le GABARIT est dans le thème (c'est du rendu), les DONNÉES sont dans le plugin.
 */
defined('ABSPATH') || exit;
get_header();

while (have_posts()) : the_post();
    $id    = get_the_ID();
    $note  = (float) get_post_meta($id, 'tuc_note', true);
    $tmin  = (int) get_post_meta($id, 'tuc_temps_min', true);
    $tmax  = (int) get_post_meta($id, 'tuc_temps_max', true);
    $frais = (int) get_post_meta($id, 'tuc_frais', true);
    $cu    = (string) get_post_meta($id, 'tuc_cuisine', true);
    $table = isset($_GET['t']) ? absint($_GET['t']) : 0;
?>
<main class="resto">
  <div class="cover">
    <?php if (has_post_thumbnail()) {
        the_post_thumbnail('tuc-banner', ['loading' => 'eager', 'fetchpriority' => 'high']);
    } ?>
  </div>

  <div class="head">
    <h1><?php the_title(); ?></h1>
    <div class="meta">
      <?php if ($note > 0) : ?><b><?php echo esc_html(tuc_rating($note)); ?> <?php echo tuc_icon('star', 11); ?></b><span>•</span><?php endif; ?>
      <span><?php echo esc_html($tmin . '–' . $tmax); ?> min</span>
      <span>•</span>
      <span><?php echo esc_html(tuc_fee($frais === 0 ? null : $frais)); ?></span>
    </div>
    <?php if ($cu !== '') : ?><div class="cu"><?php echo esc_html($cu); ?></div><?php endif; ?>
  </div>

  <?php if ($table > 0) : ?>
    <div class="bnr" style="margin:0 var(--px) 18px">
      <div class="i"><?php echo tuc_icon('qr', 19); ?></div>
      <div class="tx">
        <b><?php printf(esc_html__('Vous êtes à la table %d', 'tuc'), $table); ?></b>
        <span><?php esc_html_e('Commandez ici, vous payez à la fin.', 'tuc'); ?></span>
      </div>
    </div>
  <?php endif; ?>

  <section class="sec tuc-order-section" aria-labelledby="tuc-menu-heading">
    <div class="hd"><h2 id="tuc-menu-heading"><?php esc_html_e('Le menu', 'tuc'); ?></h2><span class="tuc-order-caption"><?php esc_html_e('Choisissez vos plats, puis confirmez votre commande.', 'tuc'); ?></span></div>
    <div id="tuc-client-menu" data-resto="<?php echo esc_attr((string) get_post_field('post_name', $id)); ?>" data-table="<?php echo esc_attr((string) $table); ?>">
      <div id="tuc-client-status" class="tuc-client-status" role="status" aria-live="polite"><?php esc_html_e('Chargement du menu…', 'tuc'); ?></div>
      <button type="button" id="tuc-open-saved-tracking" class="tuc-track-saved" hidden><?php esc_html_e('Suivre ma commande en cours', 'tuc'); ?></button>
      <div id="tuc-client-items" class="tuc-client-items"></div>
    </div>
  </section>

  <div id="tuc-cart-bar" class="tuc-cart-bar" hidden>
    <div><strong id="tuc-cart-count">0 article</strong><span id="tuc-cart-total">0,00 MAD</span></div>
    <button type="button" id="tuc-open-cart"><?php esc_html_e('Voir le panier', 'tuc'); ?> <span aria-hidden="true">→</span></button>
  </div>

  <dialog id="tuc-customize-dialog" class="tuc-client-dialog" aria-labelledby="tuc-customize-title">
    <form id="tuc-customize-form" method="dialog">
      <div class="tuc-dialog-head"><div><p class="tuc-order-kicker"><?php esc_html_e('PERSONNALISER', 'tuc'); ?></p><h2 id="tuc-customize-title"></h2></div><button type="button" class="tuc-dialog-close" data-close-customize aria-label="Fermer">×</button></div>
      <div id="tuc-customize-groups" class="tuc-customize-groups"></div>
      <p id="tuc-customize-error" class="tuc-form-error" role="alert" hidden></p>
      <button class="tuc-client-primary" type="submit"><?php esc_html_e('Ajouter au panier', 'tuc'); ?> <span id="tuc-customize-price"></span></button>
    </form>
  </dialog>

  <dialog id="tuc-cart-dialog" class="tuc-client-dialog tuc-cart-dialog" aria-labelledby="tuc-cart-title">
    <div class="tuc-dialog-head"><div><p class="tuc-order-kicker"><?php esc_html_e('VOTRE COMMANDE', 'tuc'); ?></p><h2 id="tuc-cart-title"><?php esc_html_e('Votre panier', 'tuc'); ?></h2></div><button type="button" class="tuc-dialog-close" data-close-cart aria-label="Fermer">×</button></div>
    <div id="tuc-cart-lines" class="tuc-cart-lines"></div>
    <div id="tuc-checkout-area">
      <div class="tuc-cart-summary"><span><?php esc_html_e('Sous-total', 'tuc'); ?></span><strong id="tuc-checkout-subtotal">0,00 MAD</strong></div>
      <div class="tuc-cart-summary tuc-fee-row" id="tuc-fee-row" hidden><span><?php esc_html_e('Frais de livraison', 'tuc'); ?></span><strong id="tuc-checkout-fee">0,00 MAD</strong></div>
      <div class="tuc-cart-summary tuc-cart-grand-total"><span><?php esc_html_e('Total', 'tuc'); ?></span><strong id="tuc-checkout-total">0,00 MAD</strong></div>
      <p id="tuc-minimum-notice" class="tuc-minimum-notice" hidden></p>
      <form id="tuc-checkout-form" class="tuc-checkout-form">
        <label for="tuc-customer-phone"><?php esc_html_e('Téléphone mobile', 'tuc'); ?></label>
        <input id="tuc-customer-phone" name="tel" type="tel" inputmode="tel" autocomplete="tel" required minlength="8" maxlength="20" placeholder="06 12 34 56 78">
        <small id="tuc-phone-help"><?php esc_html_e('Utilisé pour le suivi de votre commande.', 'tuc'); ?></small>
        <label id="tuc-mode-label" for="tuc-order-mode"><?php esc_html_e('Mode de service', 'tuc'); ?></label>
        <select id="tuc-order-mode" name="mode"></select>
        <section id="tuc-location-panel" class="tuc-location-panel" hidden aria-labelledby="tuc-location-title">
          <strong id="tuc-location-title"><?php esc_html_e('Position de livraison', 'tuc'); ?></strong>
          <p><?php esc_html_e('Pour la livraison, partagez votre position avec le restaurant. Elle ne sera transmise qu’avec cette commande.', 'tuc'); ?></p>
          <button type="button" id="tuc-get-location" class="tuc-client-secondary"><?php esc_html_e('Utiliser ma position', 'tuc'); ?></button>
          <span id="tuc-location-status" role="status" aria-live="polite"></span>
        </section>
        <p id="tuc-checkout-error" class="tuc-form-error" role="alert" hidden></p>
        <button id="tuc-submit-order" class="tuc-client-primary" type="submit"><?php esc_html_e('Confirmer la commande', 'tuc'); ?></button>
      </form>
    </div>
    <div id="tuc-order-success" class="tuc-order-success" hidden></div>
  </dialog>

  <dialog id="tuc-account-dialog" class="tuc-client-dialog tuc-account-dialog" aria-labelledby="tuc-account-title">
    <div class="tuc-dialog-head"><div><p class="tuc-order-kicker"><?php esc_html_e('COMPTE SÉCURISÉ', 'tuc'); ?></p><h2 id="tuc-account-title"><?php esc_html_e('Continuer avec WhatsApp', 'tuc'); ?></h2></div><button type="button" class="tuc-dialog-close" data-close-account aria-label="Fermer">×</button></div>
    <div class="tuc-account-content">
      <p><?php esc_html_e('Envoyez le message prérempli depuis votre WhatsApp. Nous créerons ou retrouverons votre compte à partir du numéro expéditeur, puis vous recevrez un lien magique pour reprendre ce panier.', 'tuc'); ?></p>
      <button type="button" id="tuc-start-whatsapp" class="tuc-client-primary"><?php esc_html_e('Préparer mon message WhatsApp', 'tuc'); ?></button>
      <a id="tuc-open-whatsapp" class="tuc-client-secondary" href="#" target="_blank" rel="noopener noreferrer" hidden><?php esc_html_e('Ouvrir WhatsApp et envoyer le message', 'tuc'); ?></a>
      <p id="tuc-account-status" role="status" aria-live="polite"></p>
      <a id="tuc-wa-resume" class="tuc-client-primary" href="#" hidden><?php esc_html_e('Reprendre mon panier en toute sécurité', 'tuc'); ?></a>
      <small><?php esc_html_e('Le lien de connexion est à usage unique et expire après 15 minutes. Aucun mot de passe n’est envoyé dans WhatsApp.', 'tuc'); ?></small>
    </div>
  </dialog>

  <dialog id="tuc-tracking-dialog" class="tuc-client-dialog tuc-tracking-dialog" aria-labelledby="tuc-tracking-title">
    <div class="tuc-dialog-head"><div><p class="tuc-order-kicker"><?php esc_html_e('SUIVI SÉCURISÉ', 'tuc'); ?></p><h2 id="tuc-tracking-title"><?php esc_html_e('Votre commande', 'tuc'); ?></h2></div><button type="button" class="tuc-dialog-close" data-close-tracking aria-label="Fermer">×</button></div>
    <div id="tuc-tracking-content" aria-live="polite"><p><?php esc_html_e('Chargement du statut…', 'tuc'); ?></p></div>
    <div class="tuc-tracking-actions"><button type="button" class="tuc-client-secondary" data-refresh-tracking><?php esc_html_e('Actualiser le statut', 'tuc'); ?></button><button type="button" class="tuc-track-forget" data-forget-tracking><?php esc_html_e('Effacer ce lien sur cet appareil', 'tuc'); ?></button></div>
  </dialog>

  <div class="content" style="padding:0 var(--px) 40px">
    <?php the_content(); ?>
  </div>
</main>
<?php endwhile;
get_footer();
