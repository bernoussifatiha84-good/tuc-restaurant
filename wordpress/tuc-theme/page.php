<?php
/** Static pages render their content (including trusted plugin shortcodes). */
defined('ABSPATH') || exit;
get_header();
?>
<main class="main tuc-static-page">
  <div class="tuc-static-page-wrap">
    <?php while (have_posts()) : the_post(); ?>
      <article <?php post_class('tuc-static-page-article'); ?>>
        <?php if (!has_shortcode((string) get_post_field('post_content', get_the_ID()), 'tuc_demande_restaurant')) : ?>
          <h1 class="tuc-static-page-title"><?php the_title(); ?></h1>
        <?php endif; ?>
        <div class="tuc-static-page-content"><?php the_content(); ?></div>
      </article>
    <?php endwhile; ?>
  </div>
</main>
<?php get_footer(); ?>
