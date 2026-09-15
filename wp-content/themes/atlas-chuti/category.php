<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * KROK 6, item 9: Magazín category archive — WordPress's own native
 * category.php template hierarchy (/category/{slug}/), reused for EVERY
 * seeded category including Tipy a triky (item 6's "prominentní zobrazení"
 * gets a distinctive header treatment here rather than a whole separate page
 * system — see class-magazine.php for why `category` needs real, Polylang-
 * paired terms instead of this codebase's usual shared-term taxonomy pattern).
 */
get_header();

$term          = get_queried_object();
$is_tips_tricks = $term instanceof WP_Term && $term->slug === Atlas_Chuti_Magazine::tips_tricks_category_slug();
$description   = $term instanceof WP_Term ? term_description( $term ) : '';
?>

<section class="container-narrow <?php echo $is_tips_tricks ? 'bg-blue-tint' : ''; ?>" style="padding:var(--space-14) var(--gutter) var(--space-5);<?php echo $is_tips_tricks ? 'border-radius:var(--radius-lg);' : ''; ?>">
	<span class="kicker <?php echo $is_tips_tricks ? 'is-saffron' : 'is-blue'; ?>"><?php esc_html_e( 'Magazín', 'atlas-chuti' ); ?></span>
	<h1><?php single_cat_title(); ?></h1>
	<?php if ( $description ) : ?>
		<div class="lede" style="max-width:none;"><?php echo wp_kses_post( $description ); ?></div>
	<?php endif; ?>
</section>

<section class="container section" style="padding-top:var(--space-6);">
	<?php if ( have_posts() ) : ?>
		<div class="magazine-strip">
			<?php
			// KROK 7, item 13: one in-feed slot after N articles in the category grid.
			$atlas_ad_after_card = 6;
			$atlas_card_index    = 0;
			while ( have_posts() ) :
				the_post();
				?>
				<a class="magazine-item" href="<?php the_permalink(); ?>">
					<div class="magazine-item-media"><?php echo atlas_chuti_media( get_the_ID(), 'atlas-card', '', 'magazine' ); ?></div>
					<h3><?php the_title(); ?></h3>
					<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 18 ) ); ?></p>
				</a>
				<?php
				++$atlas_card_index;
				if ( $atlas_ad_after_card === $atlas_card_index ) {
					atlas_chuti_render_ad_slot( 'magazine_archive_in_feed' );
				}
			endwhile;
			?>
		</div>
		<div class="pagination">
			<?php
			echo paginate_links(
				array(
					'total'     => $GLOBALS['wp_query']->max_num_pages,
					'current'   => max( 1, get_query_var( 'paged' ) ),
					'prev_text' => '←',
					'next_text' => '→',
				)
			);
			?>
		</div>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'V této kategorii zatím nejsou žádné články.', 'atlas-chuti' ); ?></p>
	<?php endif; ?>
</section>

<?php
wp_reset_postdata();
get_footer();
