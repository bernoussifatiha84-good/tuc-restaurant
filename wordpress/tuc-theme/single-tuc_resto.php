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
        <small><?php esc_html_e('Utilisé pour le suivi de votre commande.', 'tuc'); ?></small>
        <label id="tuc-mode-label" for="tuc-order-mode"><?php esc_html_e('Mode de service', 'tuc'); ?></label>
        <select id="tuc-order-mode" name="mode"></select>
        <p id="tuc-checkout-error" class="tuc-form-error" role="alert" hidden></p>
        <button id="tuc-submit-order" class="tuc-client-primary" type="submit"><?php esc_html_e('Confirmer la commande', 'tuc'); ?></button>
      </form>
    </div>
    <div id="tuc-order-success" class="tuc-order-success" hidden></div>
  </dialog>

  <div class="content" style="padding:0 var(--px) 40px">
    <?php the_content(); ?>
  </div>
</main>
<?php endwhile;
get_footer();
