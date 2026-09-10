<?php
/**
 * Fallback template (required by WordPress). Every content type in this project has
 * its own dedicated template; this only runs for anything unexpected.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>

<section class="page-content">
	<?php if ( have_posts() ) : ?>
		<?php while ( have_posts() ) : the_post(); ?>
			<h1><?php the_title(); ?></h1>
			<div class="entry-content"><?php the_content(); ?></div>
		<?php endwhile; ?>
	<?php else : ?>
		<p class="empty-state">Zde zatím není žádný obsah.</p>
	<?php endif; ?>
</section>

<?php get_footer(); ?>
