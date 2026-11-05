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

  <?php
  // menu du restaurant (module 1 du plugin)
  $items = get_posts([
      'post_type'      => 'tuc_menu_item',
      'posts_per_page' => 60,
      'orderby'        => 'menu_order',
      'order'          => 'ASC',
      'meta_query'     => [['key' => 'tuc_resto_id', 'value' => $id, 'compare' => '=']],
  ]);

  if ($items) : ?>
    <section class="sec">
      <div class="hd"><h2><?php esc_html_e('Le menu', 'tuc'); ?></h2></div>
      <div class="rail">
        <?php foreach ($items as $it) :
            $prix = (float) get_post_meta($it->ID, 'tuc_prix', true); ?>
          <article class="card">
            <div class="im"><?php echo tuc_picture('tacos', $it->post_title, ['width' => 440, 'height' => 330]); ?></div>
            <div class="nm"><?php echo esc_html($it->post_title); ?></div>
            <div class="mt"><b><?php echo esc_html(number_format($prix, 0, ',', ' ')); ?> MAD</b></div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php else : ?>
    <p style="padding:0 var(--px);color:var(--g600);font-size:13px">
      <?php esc_html_e('Le menu sera bientôt disponible.', 'tuc'); ?>
    </p>
  <?php endif; ?>

  <div class="content" style="padding:0 var(--px) 40px">
    <?php the_content(); ?>
  </div>
</main>
<?php endwhile;
get_footer();
