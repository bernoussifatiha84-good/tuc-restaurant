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
$raw_ville = isset($_GET['ville']) && is_scalar($_GET['ville']) ? (string) wp_unslash($_GET['ville']) : '';
$ville = sanitize_title($raw_ville) === 'casablanca' ? 'casablanca' : '';
$recherche_localisation = $quartier !== '' || $ville !== '';
if ($quartier_term instanceof WP_Term) {
    $quartier_restos = get_posts([
        'post_type' => 'tuc_resto', 'post_status' => 'publish', 'posts_per_page' => 100,
        'no_found_rows' => true, 'ignore_sticky_posts' => true,
        'tax_query' => [[ 'taxonomy' => 'tuc_quartier_cat', 'field' => 'term_id', 'terms' => (int) $quartier_term->term_id ]],
    ]);
} elseif ($ville === 'casablanca') {
    $quartier_restos = get_posts([
        'post_type' => 'tuc_resto', 'post_status' => 'publish', 'posts_per_page' => 100,
        'no_found_rows' => true, 'ignore_sticky_posts' => true, 'orderby' => 'title', 'order' => 'ASC',
    ]);
} else {
    $quartier_restos = [];
}
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

  <button type="button" class="addr<?php echo $recherche_localisation ? '' : ' todo'; ?>" id="tuc-addr" aria-haspopup="dialog" aria-controls="tuc-location-dialog">
    <?php echo tuc_icon('pin', 15); ?>
    <span class="t" id="tuc-addr-txt"><?php if ($quartier_term instanceof WP_Term) { echo esc_html(sprintf(__('Quartier : %s', 'tuc'), $quartier_term->name)); } elseif ($ville === 'casablanca') { esc_html_e('Ville : Casablanca', 'tuc'); } else { esc_html_e('Choisir ma ville ou mon quartier', 'tuc'); } ?></span>
    <?php echo tuc_icon('chevron', 13); ?>
  </button>

  <h1 class="hero-title" id="tuc-home-title"><?php if ($quartier_term instanceof WP_Term) { printf(esc_html__('Restaurants dans le quartier %s', 'tuc'), esc_html($quartier_term->name)); } elseif ($ville === 'casablanca') { esc_html_e('Restaurants à Casablanca', 'tuc'); } else { esc_html_e('Découvrez les restaurants de Casablanca', 'tuc'); } ?></h1>

  <dialog id="tuc-location-dialog" class="tuc-location-dialog" aria-labelledby="tuc-location-title" aria-describedby="tuc-location-description"
          data-search-url="<?php echo esc_url(home_url('/')); ?>">
    <div class="tuc-location-dialog-head">
      <div><h2 id="tuc-location-title"><?php esc_html_e('Où souhaitez-vous commander ?', 'tuc'); ?></h2>
        <p id="tuc-location-description"><?php esc_html_e('Partagez votre position pour classer les restaurants de Casablanca du plus proche au plus éloigné. Vous pouvez aussi choisir une ville ou un quartier.', 'tuc'); ?></p></div>
      <button type="button" id="tuc-location-close" aria-label="<?php esc_attr_e('Fermer', 'tuc'); ?>">×</button>
    </div>
    <button type="button" id="tuc-use-location" class="tuc-location-action"><?php esc_html_e('Partager ma localisation', 'tuc'); ?></button>
    <p id="tuc-location-message" role="status" aria-live="polite"></p>
    <div id="tuc-location-intro">
      <button type="button" id="tuc-location-choose-manual" class="tuc-location-secondary"><?php esc_html_e('Choisir une ville ou un quartier', 'tuc'); ?></button>
      <p class="tuc-location-privacy"><?php esc_html_e('Le GPS n’est demandé qu’après votre clic. Pour cette recherche, votre position reste temporairement dans cet onglet (30 minutes maximum) afin de calculer les distances; elle n’est pas envoyée à notre serveur.', 'tuc'); ?></p>
    </div>
    <div id="tuc-manual-location" hidden>
      <h3><?php esc_html_e('Choisir une ville', 'tuc'); ?></h3>
      <ul class="tuc-city-list"><li><a href="<?php echo esc_url(add_query_arg('ville', 'casablanca', home_url('/'))); ?>" data-city="casablanca"><?php esc_html_e('Casablanca', 'tuc'); ?></a></li></ul>
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
        <p><?php esc_html_e('Aucun quartier n’est encore configuré.', 'tuc'); ?></p>
      <?php endif; ?>
      <button type="button" id="tuc-location-back" class="tuc-location-back"><?php esc_html_e('Retour', 'tuc'); ?></button>
    </div>
  </dialog>

  <?php if (!$recherche_localisation) : ?>
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
<?php if ($recherche_localisation) : ?>
  <section class="sec" id="s-rest" aria-labelledby="tuc-quartier-results-title">
    <div class="hd"><h2 id="tuc-quartier-results-title"><?php if ($quartier !== '' && !($quartier_term instanceof WP_Term)) { esc_html_e('Quartier non reconnu', 'tuc'); } elseif ($quartier_term instanceof WP_Term) { echo esc_html(sprintf(__('Restaurants dans le quartier %s', 'tuc'), $quartier_term->name)); } else { esc_html_e('Restaurants à Casablanca', 'tuc'); } ?></h2></div>
    <p id="tuc-location-nearest" class="tuc-location-nearest" hidden></p>
    <?php if ($quartier !== '' && !($quartier_term instanceof WP_Term)) : ?>
      <p class="tuc-location-empty"><?php esc_html_e('Cette zone n’existe pas. Choisissez un quartier proposé ou cherchez par nom.', 'tuc'); ?></p>
    <?php elseif (!$quartier_restos) : ?>
      <p class="tuc-location-empty"><?php if ($quartier_term instanceof WP_Term) { esc_html_e('Aucun restaurant n’est encore référencé dans ce quartier.', 'tuc'); } else { esc_html_e('Aucun restaurant n’est encore référencé à Casablanca.', 'tuc'); } ?></p>
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
          $tmax = max($tmin, (int) get_post_meta($resto_id, 'tuc_temps_max', true));
          $restaurant_lat = get_post_meta($resto_id, 'tuc_lat', true);
          $restaurant_lng = get_post_meta($resto_id, 'tuc_lng', true);
          $has_coordinates = is_numeric($restaurant_lat) && (float) $restaurant_lat >= -90 && (float) $restaurant_lat <= 90
              && is_numeric($restaurant_lng) && (float) $restaurant_lng >= -180 && (float) $restaurant_lng <= 180;
          $restaurant_terms = wp_get_post_terms($resto_id, 'tuc_quartier_cat');
          $restaurant_neighborhood = !is_wp_error($restaurant_terms) && !empty($restaurant_terms) ? $restaurant_terms[0]->name : ''; ?>
          <article class="quartier-card"<?php if ($has_coordinates) : ?> data-lat="<?php echo esc_attr((string) $restaurant_lat); ?>" data-lng="<?php echo esc_attr((string) $restaurant_lng); ?>"<?php endif; ?>>
            <a href="<?php echo esc_url(get_permalink($resto_id)); ?>">
              <?php if (has_post_thumbnail($resto_id)) echo get_the_post_thumbnail($resto_id, 'tuc-card', ['alt' => '', 'loading' => 'lazy']); ?>
              <div class="quartier-card-copy">
                <h3><?php echo esc_html(get_the_title($resto_id)); ?></h3>
                <?php if ($restaurant_neighborhood !== '') : ?><p class="quartier-neighborhood"><?php echo esc_html($restaurant_neighborhood); ?></p><?php endif; ?>
                <?php if ($cuisine !== '') : ?><p><?php echo esc_html($cuisine); ?></p><?php endif; ?>
                <?php if ($tmin > 0) : ?><span><?php echo esc_html(sprintf(__('%1$d–%2$d min', 'tuc'), $tmin, $tmax)); ?></span><?php endif; ?>
                <span><?php echo esc_html($fee_label); ?></span>
                <span class="quartier-distance" hidden></span>
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
