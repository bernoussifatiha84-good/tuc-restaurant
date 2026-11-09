<?php
defined('ABSPATH') || exit;
$conditions_page = get_page_by_path('conditions');
$privacy_url = get_privacy_policy_url();
?>
<footer class="ft">
  <div class="ft-in">
    <div class="ft-brand">
      <?php echo tuc_logo(24); ?>
      <p><?php esc_html_e("Le logiciel de commande du restaurant indépendant, offert.", 'tuc'); ?></p>
    </div>
    <nav class="ft-cols" aria-label="<?php esc_attr_e('Liens de navigation du pied de page', 'tuc'); ?>">
      <div>
        <h2><?php esc_html_e('Clients', 'tuc'); ?></h2>
        <ul>
          <li><a href="<?php echo esc_url(home_url('/#s-rest')); ?>"><?php esc_html_e('Restaurants', 'tuc'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/#s-promo')); ?>"><?php esc_html_e('Promotions', 'tuc'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/#hero')); ?>"><?php esc_html_e('Rechercher', 'tuc'); ?></a></li>
        </ul>
      </div>
      <div>
        <h2><?php esc_html_e('Restaurants', 'tuc'); ?></h2>
        <ul>
          <li><a href="<?php echo esc_url(home_url('/espace-pro/')); ?>"><?php esc_html_e('Espace restaurateur', 'tuc'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/devenir-restaurateur/')); ?>"><?php esc_html_e('Rejoindre TUC', 'tuc'); ?></a></li>
          <li><a href="<?php echo esc_url(home_url('/caisse/')); ?>"><?php esc_html_e('Accès caisse', 'tuc'); ?></a></li>
        </ul>
      </div>
      <div>
        <h2><?php esc_html_e('Zone desservie', 'tuc'); ?></h2>
        <p class="ft-zone"><?php esc_html_e('Casablanca', 'tuc'); ?></p>
      </div>
    </nav>
  </div>
  <div class="ft-bot">
    <span>© <?php echo esc_html(date_i18n('Y')); ?> TUC</span>
    <?php if ($conditions_page instanceof WP_Post && $conditions_page->post_status === 'publish') : ?>
      <a href="<?php echo esc_url(get_permalink($conditions_page)); ?>"><?php esc_html_e('Conditions', 'tuc'); ?></a>
    <?php endif; ?>
    <?php if ($privacy_url) : ?>
      <a href="<?php echo esc_url($privacy_url); ?>"><?php esc_html_e('Confidentialité', 'tuc'); ?></a>
    <?php endif; ?>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
