<?php defined('ABSPATH') || exit; ?>
<footer class="ft">
  <div class="ft-in">
    <div class="ft-brand">
      <?php echo tuc_logo(24); ?>
      <p><?php esc_html_e("Le logiciel de commande du restaurant indépendant, offert.", 'tuc'); ?></p>
    </div>
    <nav class="ft-cols">
      <div>
        <h4><?php esc_html_e('Clients', 'tuc'); ?></h4>
        <ul>
          <li><a href="#"><?php esc_html_e('Comment commander', 'tuc'); ?></a></li>
          <li><a href="#"><?php esc_html_e('Suivre ma commande', 'tuc'); ?></a></li>
          <li><a href="#"><?php esc_html_e('Aide', 'tuc'); ?></a></li>
        </ul>
      </div>
      <div>
        <h4><?php esc_html_e('Restaurants', 'tuc'); ?></h4>
        <ul>
          <li><a href="#"><?php esc_html_e('Les 3 packs', 'tuc'); ?></a></li>
          <li><a href="#"><?php esc_html_e('QR & affiches', 'tuc'); ?></a></li>
          <li><a href="#"><?php esc_html_e('Espace restaurateur', 'tuc'); ?></a></li>
        </ul>
      </div>
      <div>
        <h4><?php esc_html_e('Villes', 'tuc'); ?></h4>
        <ul>
          <li><a href="#">Casablanca</a></li>
          <li><a href="#">Rabat</a></li>
          <li><a href="#">Marrakech</a></li>
        </ul>
      </div>
    </nav>
  </div>
  <div class="ft-bot">
    <span>© <?php echo esc_html(date_i18n('Y')); ?> TUC</span>
    <a href="#"><?php esc_html_e('Conditions', 'tuc'); ?></a>
    <a href="#"><?php esc_html_e('Confidentialité', 'tuc'); ?></a>
  </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
