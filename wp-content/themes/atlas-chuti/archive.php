<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Generic archive fallback (WordPress template hierarchy requires one to exist
 * once category.php/archive-atlas_topic.php start being real templates) — reached
 * only by archive types this project doesn't build a dedicated page for (date
 * archives, tag archives; author archives are redirected home, see
 * Atlas_Chuti_Magazine::disable_author_archive()). Same card layout as
 * category.php so it never looks broken if WordPress ever routes here.
 */
get_header();
?>

<section class="container-narrow" style="padding:var(--space-14) var(--gutter) var(--space-5);">
	<span class="kicker is-blue"><?php esc_html_e( 'Magazín', 'atlas-chuti' ); ?></span>
	<h1><?php the_archive_title(); ?></h1>
</section>

<section class="container section" style="padding-top:var(--space-6);">
	<?php if ( have_posts() ) : ?>
		<div class="magazine-strip">
			<?php while ( have_posts() ) : the_post(); ?>
				<a class="magazine-item" href="<?php the_permalink(); ?>">
					<div class="magazine-item-media"><?php echo atlas_chuti_media( get_the_ID(), 'atlas-card', '', 'magazine' ); ?></div>
					<h3><?php the_title(); ?></h3>
					<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 18 ) ); ?></p>
				</a>
			<?php endwhile; ?>
		</div>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'Zde zatím není žádný obsah.', 'atlas-chuti' ); ?></p>
	<?php endif; ?>
</section>

<?php
wp_reset_postdata();
get_footer();
