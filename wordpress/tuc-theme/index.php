<?php
/**
 * Gabarit de repli. WordPress exige un index.php dans tout thème.
 * Sur TUC, toutes les pages publiques sont rendues par front-page.php ;
 * ce fichier sert pour la recherche, les archives et les 404.
 */
defined('ABSPATH') || exit;
get_header();
?>
<main class="main" style="padding-top:24px">
  <div style="max-width:860px;margin:0 auto;padding:0 var(--px) 60px">
    <?php if (have_posts()) : ?>
      <?php if (is_search()) : ?>
        <h2 class="hd" style="padding:0;font-size:20px;font-weight:800">
          <?php printf(esc_html__('Résultats pour « %s »', 'tuc'), esc_html(get_search_query())); ?>
        </h2>
      <?php endif; ?>

      <?php while (have_posts()) : the_post(); ?>
        <article style="padding:16px 0;border-bottom:1px solid var(--g200)">
          <h3 style="margin:0;font-size:16px;font-weight:800">
            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
          </h3>
          <div style="margin-top:6px;font-size:13px;color:var(--g600);line-height:1.55">
            <?php the_excerpt(); ?>
          </div>
        </article>
      <?php endwhile; ?>

      <div style="margin-top:22px;display:flex;gap:14px;font-size:13px;font-weight:800">
        <?php previous_posts_link(__('← Précédent', 'tuc')); ?>
        <?php next_posts_link(__('Suivant →', 'tuc')); ?>
      </div>

    <?php else : ?>
      <div style="text-align:center;padding:60px 0">
        <div style="font-size:46px;font-weight:800;color:var(--teal);letter-spacing:-2px">404</div>
        <h2 style="margin:8px 0 0;font-size:19px;font-weight:800">
          <?php esc_html_e("Cette page n'existe pas.", 'tuc'); ?>
        </h2>
        <p style="margin:8px 0 20px;font-size:13.5px;color:var(--g600)">
          <?php esc_html_e('Le restaurant que vous cherchez a peut-être fermé.', 'tuc'); ?>
        </p>
        <a href="<?php echo esc_url(home_url('/')); ?>"
           style="display:inline-flex;align-items:center;height:46px;padding:0 22px;border-radius:99px;
                  background:var(--teal-d);color:#fff;font-size:14px;font-weight:800">
          <?php esc_html_e("Retour à l'accueil", 'tuc'); ?>
        </a>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php get_footer(); ?>
