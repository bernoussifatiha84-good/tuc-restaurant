<?php
/**
 * Page d'accueil — reproduit la maquette validée (mobile d'abord).
 */
defined('ABSPATH') || exit;
get_header();

$restos   = tuc_restos();
$promos   = tuc_promos();
$sections = tuc_sections();
$quartier = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
?>

<section class="hero" id="hero">
  <div class="top">
    <button class="burger" id="tuc-menu" aria-label="<?php esc_attr_e('Menu', 'tuc'); ?>">
      <?php echo tuc_icon('menu', 22); ?>
    </button>
    <?php echo tuc_logo(27); ?>
    <a class="connect" href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Connexion', 'tuc'); ?></a>
  </div>

  <button class="addr<?php echo $quartier ? '' : ' todo'; ?>" id="tuc-addr">
    <?php echo tuc_icon('pin', 15); ?>
    <span class="t" id="tuc-addr-txt"><?php echo $quartier ? esc_html($quartier) : esc_html__('Choisir mon quartier', 'tuc'); ?></span>
    <?php echo tuc_icon('chevron', 13); ?>
  </button>

  <div class="cats">
    <div class="crow">
      <?php
      $cats = [
          ['restaurant', __('Restaurant', 'tuc'), 'restaurant'],
          ['mahlaba',    __('Mahlaba', 'tuc'),    'mahlaba'],
      ];
      foreach ($cats as [$slug, $label, $img]) : ?>
        <a class="cat" href="#s-<?php echo esc_attr($slug); ?>">
          <span class="c"><?php echo tuc_picture($img, $label, ['width' => 200, 'height' => 200, 'sizes' => '104px', 'extra' => 'style="width:100%;height:100%;object-fit:cover;border-radius:50%"']); ?></span>
          <span class="n"><?php echo esc_html($label); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="crow">
      <?php
      $cats2 = [
          ['snack',  __('Snack', 'tuc'),       'snack'],
          ['cafe',   __('Café', 'tuc'),        'cafe'],
          ['coffee', __('Coffee shop', 'tuc'), 'coffee'],
      ];
      foreach ($cats2 as [$slug, $label, $img]) : ?>
        <a class="cat" href="#s-<?php echo esc_attr($slug); ?>">
          <span class="c"><?php echo tuc_picture($img, $label, ['width' => 200, 'height' => 200, 'sizes' => '104px', 'extra' => 'style="width:100%;height:100%;object-fit:cover;border-radius:50%"']); ?></span>
          <span class="n"><?php echo esc_html($label); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <form class="search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
    <label>
      <?php echo tuc_icon('search', 17); ?>
      <input type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>"
             placeholder="<?php esc_attr_e('De quoi avez-vous besoin ?', 'tuc'); ?>">
    </label>
    <button type="submit"><?php esc_html_e('Recherche', 'tuc'); ?></button>
  </form>
</section>

<main class="main">
<?php foreach ($sections as $sec) :
    $items = $sec['type'] === 'promos' ? $promos : tuc_pick($restos, $sec);
    if (!$items) continue; ?>
  <section class="sec" id="s-<?php echo esc_attr($sec['id']); ?>">
    <div class="hd">
      <h2><?php echo esc_html($sec['fr']); ?></h2>
      <a class="all" href="#"><?php esc_html_e('Voir tout', 'tuc'); ?></a>
      <div class="nav">
        <button data-prev aria-label="<?php esc_attr_e('Précédent', 'tuc'); ?>"><?php echo tuc_icon('left', 14); ?></button>
        <button data-next aria-label="<?php esc_attr_e('Suivant', 'tuc'); ?>"><?php echo tuc_icon('right', 14); ?></button>
      </div>
    </div>
    <div class="rail">
      <?php if ($sec['type'] === 'promos') : ?>
        <?php foreach ($items as $p) : ?>
          <article class="promo">
            <div class="im">
              <?php echo tuc_picture($p['img'], $p['nm'], ['width'=>440,'height'=>330,'sizes'=>'(max-width:900px) 206px, 300px']); ?>
              <span class="off"><?php echo esc_html($p['big']); ?></span>
            </div>
            <div class="nm"><?php echo esc_html($p['nm']); ?></div>
            <div class="mt"><b><?php echo esc_html(tuc_rating($p['r'])); ?> <?php echo tuc_icon('star', 10); ?></b><span>•</span><span><?php echo esc_html($p['t']); ?></span></div>
            <div class="fee"><?php echo esc_html($p['shop']); ?> · <?php echo esc_html(tuc_fee(null)); ?></div>
          </article>
        <?php endforeach; ?>
      <?php else : ?>
        <?php foreach ($items as $x) : ?>
          <article class="card">
            <div class="im"><?php echo tuc_picture($x['img'], $x['n'], ['width'=>440,'height'=>330,'sizes'=>'(max-width:900px) 158px, 232px']); ?></div>
            <div class="nm"><?php echo esc_html($x['n']); ?></div>
            <div class="cu"><?php echo esc_html($x['cu']); ?></div>
            <div class="mt">
              <b><?php echo esc_html(tuc_rating($x['r'])); ?> <?php echo tuc_icon('star', 10); ?></b>
              <span>•</span><span><?php echo esc_html($x['t']); ?> min</span>
              <span>•</span><span><?php echo esc_html(str_replace('.', ',', (string)$x['d'])); ?> km</span>
            </div>
            <div class="fee"><?php echo esc_html(tuc_fee($x['fee'])); ?></div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>

  <div class="bnr">
    <div class="i"><?php echo tuc_icon('qr', 19); ?></div>
    <div class="tx">
      <b><?php esc_html_e('Vous êtes à table ?', 'tuc'); ?></b>
      <span><?php esc_html_e('Scannez le QR, commandez, payez à la fin.', 'tuc'); ?></span>
    </div>
  </div>
</main>

<?php get_footer(); ?>
