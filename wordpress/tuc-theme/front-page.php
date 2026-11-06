<?php
/**
 * Page d'accueil — reproduit la maquette validée (mobile d'abord).
 */
defined('ABSPATH') || exit;
get_header();

$restos   = tuc_restos();
$promos   = tuc_promos();
$sections = tuc_sections();
$raw_quartier = isset($_GET['q']) && is_scalar($_GET['q']) ? (string) wp_unslash($_GET['q']) : '';
$quartier = sanitize_title($raw_quartier);
$quartier_term = $quartier && taxonomy_exists('tuc_quartier_cat') ? get_term_by('slug', $quartier, 'tuc_quartier_cat') : false;
$quartier_restos = $quartier_term instanceof WP_Term ? get_posts([
    'post_type' => 'tuc_resto', 'post_status' => 'publish', 'posts_per_page' => 40,
    'no_found_rows' => true, 'ignore_sticky_posts' => true,
    'tax_query' => [[ 'taxonomy' => 'tuc_quartier_cat', 'field' => 'term_id', 'terms' => (int) $quartier_term->term_id ]],
]) : [];
$quartiers_disponibles = taxonomy_exists('tuc_quartier_cat') ? get_terms([
    'taxonomy' => 'tuc_quartier_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC',
]) : [];
if (is_wp_error($quartiers_disponibles)) $quartiers_disponibles = [];
$quartiers_geo = class_exists('TUC_Geo') ? TUC_Geo::quartiers() : [];
$quartiers_geo = array_column($quartiers_geo, null, 'slug');
?>

<header class="hero" id="hero">
  <div class="top">
    <?php echo tuc_logo(27); ?>
    <a class="connect" href="<?php echo esc_url(home_url('/espace-pro/')); ?>"><?php esc_html_e('Espace pro', 'tuc'); ?></a>
  </div>

  <button type="button" class="addr<?php echo $quartier ? '' : ' todo'; ?>" id="tuc-addr" aria-haspopup="dialog" aria-controls="tuc-location-dialog">
    <?php echo tuc_icon('pin', 15); ?>
    <span class="t" id="tuc-addr-txt"><?php echo $quartier_term instanceof WP_Term ? esc_html(sprintf(__('Quartier : %s', 'tuc'), $quartier_term->name)) : esc_html__('Choisir mon quartier', 'tuc'); ?></span>
    <?php echo tuc_icon('chevron', 13); ?>
  </button>

  <h1 class="hero-title"><?php if ($quartier_term instanceof WP_Term) { printf(esc_html__('Restaurants près de %s', 'tuc'), esc_html($quartier_term->name)); } else { esc_html_e('Découvrez les restaurants de Casablanca', 'tuc'); } ?></h1>

  <dialog id="tuc-location-dialog" class="tuc-location-dialog" aria-labelledby="tuc-location-title"
          data-rest-url="<?php echo esc_url(rest_url('tuc/v1/quartiers')); ?>"
          data-search-url="<?php echo esc_url(home_url('/')); ?>">
    <div class="tuc-location-dialog-head">
      <div><h2 id="tuc-location-title"><?php esc_html_e('Choisir une zone de recherche', 'tuc'); ?></h2>
        <p><?php esc_html_e('La position sert uniquement à estimer le quartier le plus proche. Vos coordonnées restent sur cet appareil.', 'tuc'); ?></p></div>
      <button type="button" id="tuc-location-close" aria-label="<?php esc_attr_e('Fermer', 'tuc'); ?>">×</button>
    </div>
    <?php if (!empty($quartiers_geo)) : ?>
      <button type="button" id="tuc-use-location" class="tuc-location-action"><?php esc_html_e('Utiliser ma position', 'tuc'); ?></button>
    <?php endif; ?>
    <p id="tuc-location-message" role="status" aria-live="polite"></p>
    <h3><?php esc_html_e('Ou choisir un quartier', 'tuc'); ?></h3>
    <?php if ($quartiers_disponibles) : ?>
      <ul class="tuc-quartier-list">
        <?php foreach ($quartiers_disponibles as $term) :
          if (!($term instanceof WP_Term)) continue; ?>
          <li><a href="<?php echo esc_url(add_query_arg('q', $term->slug, home_url('/'))); ?>"
                 data-quartier="<?php echo esc_attr($term->slug); ?>"
                 data-center-lat="<?php echo esc_attr((string) ($quartiers_geo[$term->slug]['lat'] ?? '')); ?>"
                 data-center-lng="<?php echo esc_attr((string) ($quartiers_geo[$term->slug]['lng'] ?? '')); ?>"><?php echo esc_html($term->name); ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php else : ?>
      <p><?php esc_html_e('Aucun quartier de recherche n’est encore configuré.', 'tuc'); ?></p>
    <?php endif; ?>
  </dialog>

  <?php if ($quartier === '') : ?>
  <nav class="cats" aria-label="<?php esc_attr_e('Parcourir les restaurants par catégorie', 'tuc'); ?>">
    <div class="crow">
      <?php
      $cats = [
          ['restaurant', __('Restaurant', 'tuc'), 'restaurant'],
          ['mahlaba',    __('Mahlaba', 'tuc'),    'mahlaba'],
      ];
      foreach ($cats as [$slug, $label, $img]) :
          $target = ['restaurant' => 'rest', 'mahlaba' => 'maha'][$slug] ?? $slug; ?>
        <a class="cat" href="#s-<?php echo esc_attr($target); ?>">
          <span class="c"><?php echo tuc_picture($img, '', ['width' => 200, 'height' => 200, 'sizes' => '104px', 'extra' => 'style="width:100%;height:100%;object-fit:cover;border-radius:50%"']); ?></span>
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
          <span class="c"><?php echo tuc_picture($img, '', ['width' => 200, 'height' => 200, 'sizes' => '104px', 'extra' => 'style="width:100%;height:100%;object-fit:cover;border-radius:50%"']); ?></span>
          <span class="n"><?php echo esc_html($label); ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>
  <?php endif; ?>

  <form class="search" role="search" aria-label="<?php esc_attr_e('Rechercher un restaurant', 'tuc'); ?>" method="get" action="<?php echo esc_url(home_url('/')); ?>">
    <label>
      <?php echo tuc_icon('search', 17); ?>
      <input type="search" name="s" aria-label="<?php esc_attr_e('Rechercher un restaurant ou un plat', 'tuc'); ?>" value="<?php echo esc_attr(get_search_query()); ?>"
             placeholder="<?php esc_attr_e('De quoi avez-vous besoin ?', 'tuc'); ?>">
    </label>
    <button type="submit"><?php esc_html_e('Recherche', 'tuc'); ?></button>
  </form>
</header>

<main class="main">
<?php if ($quartier !== '') : ?>
  <section class="sec" id="s-rest" aria-labelledby="tuc-quartier-results-title">
    <div class="hd"><h2 id="tuc-quartier-results-title"><?php echo $quartier_term instanceof WP_Term ? esc_html(sprintf(__('Restaurants à %s', 'tuc'), $quartier_term->name)) : esc_html__('Quartier non reconnu', 'tuc'); ?></h2></div>
    <?php if (!($quartier_term instanceof WP_Term)) : ?>
      <p class="tuc-location-empty"><?php esc_html_e('Cette zone n’existe pas. Choisissez un quartier proposé ou cherchez par nom.', 'tuc'); ?></p>
    <?php elseif (!$quartier_restos) : ?>
      <p class="tuc-location-empty"><?php esc_html_e('Aucun restaurant n’est encore référencé dans ce quartier.', 'tuc'); ?></p>
    <?php else : ?>
      <div class="quartier-results">
        <?php foreach ($quartier_restos as $quartier_resto) :
          $resto_id = (int) $quartier_resto->ID;
          $cuisine = (string) get_post_meta($resto_id, 'tuc_cuisine', true);
          $fee_raw = get_post_meta($resto_id, 'tuc_frais', true);
          $fee = max(0, (int) $fee_raw);
          $fee_label = !metadata_exists('post', $resto_id, 'tuc_frais') ? __('Frais à confirmer', 'tuc')
              : ($fee === 0 ? __('Livraison offerte', 'tuc') : sprintf(__('Livraison : %s MAD', 'tuc'), number_format_i18n($fee, 0)));
          $tmin = max(0, (int) get_post_meta($resto_id, 'tuc_temps_min', true));
          $tmax = max($tmin, (int) get_post_meta($resto_id, 'tuc_temps_max', true)); ?>
          <article class="quartier-card">
            <a href="<?php echo esc_url(get_permalink($resto_id)); ?>">
              <?php if (has_post_thumbnail($resto_id)) echo get_the_post_thumbnail($resto_id, 'tuc-card', ['alt' => '', 'loading' => 'lazy']); ?>
              <div class="quartier-card-copy">
                <h3><?php echo esc_html(get_the_title($resto_id)); ?></h3>
                <?php if ($cuisine !== '') : ?><p><?php echo esc_html($cuisine); ?></p><?php endif; ?>
                <?php if ($tmin > 0) : ?><span><?php echo esc_html(sprintf(__('%1$d–%2$d min', 'tuc'), $tmin, $tmax)); ?></span><?php endif; ?>
                <span><?php echo esc_html($fee_label); ?></span>
              </div>
            </a>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php else : ?>
<?php foreach ($sections as $sec) :
    $items = $sec['type'] === 'promos' ? $promos : tuc_pick($restos, $sec);
    if (!$items) continue; ?>
  <section class="sec" id="s-<?php echo esc_attr($sec['id']); ?>">
    <div class="hd">
      <h2><?php echo esc_html($sec['fr']); ?></h2>
      <div class="nav">
        <button data-prev aria-label="<?php esc_attr_e('Précédent', 'tuc'); ?>"><?php echo tuc_icon('left', 14); ?></button>
        <button data-next aria-label="<?php esc_attr_e('Suivant', 'tuc'); ?>"><?php echo tuc_icon('right', 14); ?></button>
      </div>
    </div>
    <div class="rail" role="region" aria-roledescription="carousel" aria-label="<?php echo esc_attr($sec['fr']); ?>" tabindex="0">
      <?php if ($sec['type'] === 'promos') : ?>
        <?php foreach ($items as $p) : ?>
          <article class="promo">
            <div class="im">
              <?php echo tuc_picture($p['img'], '', ['width'=>440,'height'=>330,'sizes'=>'(max-width:900px) 206px, 300px']); ?>
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
            <div class="im"><?php echo tuc_picture($x['img'], '', ['width'=>440,'height'=>330,'sizes'=>'(max-width:900px) 158px, 232px']); ?></div>
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
<?php endif; ?>

  <div class="bnr">
    <div class="i"><?php echo tuc_icon('qr', 19); ?></div>
    <div class="tx">
      <b><?php esc_html_e('Vous êtes à table ?', 'tuc'); ?></b>
      <span><?php esc_html_e('Scannez le QR, commandez, payez à la fin.', 'tuc'); ?></span>
    </div>
  </div>
</main>

<?php get_footer(); ?>
