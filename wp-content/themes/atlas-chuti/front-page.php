<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

/**
 * KROK 1 homepage rebuild: a redakční (editorial) titulka instead of a static
 * hero + repeated card rows. Every section below is real data with a graceful
 * "don't render" fallback when there isn't enough content yet — nothing here is
 * ever invented (no fake ratings/comments/articles), per the brief.
 */

$new_recipes_pool = atlas_chuti_home_new_recipes( 10 );
$lead_main         = $new_recipes_pool ? array_shift( $new_recipes_pool ) : null;

$featured_recipe = atlas_chuti_home_featured_recipe();
if ( $featured_recipe && $lead_main && $featured_recipe->ID === $lead_main->ID ) {
	$featured_recipe = null; // Never show the exact same recipe twice in the lead block.
}
if ( $featured_recipe ) {
	// Nor show it a second time among the lead's smaller text items just below.
	$new_recipes_pool = array_values(
		array_filter(
			$new_recipes_pool,
			function ( $recipe ) use ( $featured_recipe ) {
				return $recipe->ID !== $featured_recipe->ID;
			}
		)
	);
}

$lead_text_items = array_slice( $new_recipes_pool, 0, 2 );
$feed_pool        = array_slice( $new_recipes_pool, 2, 6 );

$continents      = get_terms( array( 'taxonomy' => 'atlas_continent', 'hide_empty' => false ) );
$czech_country   = atlas_chuti_home_czech_country();
$czech_recipes   = $czech_country ? atlas_chuti_get_recipes_for_country( $czech_country->ID, 4 ) : array();
$world_picks     = atlas_chuti_home_world_picks( 3 );
$glossary_terms  = atlas_chuti_home_glossary_preview( 3 );
$magazine_posts  = atlas_chuti_home_magazine_posts( 3 );
$total_countries = atlas_chuti_total_countries();
$recipe_archive  = get_post_type_archive_link( 'atlas_recipe' );

// KROK 6, item 27/28: both blocks below only ever render with REAL data — no
// fake cards, no fake "most discussed" — and simply disappear otherwise.
$tips_tricks_term  = ( $tips_slug = class_exists( 'Atlas_Chuti_Magazine' ) ? Atlas_Chuti_Magazine::tips_tricks_category_slug() : '' ) ? get_term_by( 'slug', $tips_slug, 'category' ) : null;
$tips_tricks_posts = ( $tips_tricks_term && ! is_wp_error( $tips_tricks_term ) )
	? get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 3, 'cat' => $tips_tricks_term->term_id ) )
	: array();
$latest_topics = get_posts( array( 'post_type' => 'atlas_topic', 'post_status' => 'publish', 'posts_per_page' => 4 ) );
?>

<div class="container home-topline">
	<span class="kicker"><?php esc_html_e( 'Kulinární atlas světa', 'atlas-chuti' ); ?></span>
	<h1 class="home-topline-title"><?php esc_html_e( 'Ochutnejte svět: recepty, země a kuchyně z celé planety', 'atlas-chuti' ); ?></h1>
	<form class="search-box" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search">
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledejte zemi, recept nebo surovinu…', 'atlas-chuti' ); ?>">
		<button type="submit" class="btn btn-accent"><?php esc_html_e( 'Hledat', 'atlas-chuti' ); ?></button>
	</form>
</div>

<?php if ( $lead_main ) : $lead_country = atlas_chuti_get_recipe_primary_country( $lead_main->ID ); ?>
<section class="section" style="padding-top:0;">
	<div class="container">
		<div class="lead-grid">
			<a class="lead-story" href="<?php echo esc_url( get_permalink( $lead_main ) ); ?>">
				<div class="lead-story-media">
					<?php
					// Eager/high-priority: this is the homepage's LCP element now that the
					// static hero is gone (item 11 of the brief), so it must never be lazy.
					if ( has_post_thumbnail( $lead_main ) ) {
						echo get_the_post_thumbnail( $lead_main, 'atlas-hero', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'async' ) );
					} else {
						echo atlas_chuti_fallback_image_html( 'recipe', '', get_the_title( $lead_main ), array( 'loading' => 'eager', 'fetchpriority' => 'high' ) );
					}
					?>
				</div>
				<div class="lead-story-body">
					<span class="kicker"><?php esc_html_e( 'Nové na Atlasu', 'atlas-chuti' ); ?></span>
					<?php if ( $lead_country ) : ?>
						<div class="card-eyebrow"><span><?php echo esc_html( atlas_chuti_flag( $lead_country->ID ) ); ?></span><span><?php echo esc_html( get_the_title( $lead_country ) ); ?></span></div>
					<?php endif; ?>
					<h2 class="lead-story-title"><?php echo esc_html( get_the_title( $lead_main ) ); ?></h2>
					<?php $lead_excerpt = get_post_meta( $lead_main->ID, 'atlas_excerpt', true ); ?>
					<?php if ( $lead_excerpt ) : ?>
						<p class="lead-story-excerpt"><?php echo esc_html( $lead_excerpt ); ?></p>
					<?php endif; ?>
					<span class="link-arrow"><?php esc_html_e( 'Uvařit recept →', 'atlas-chuti' ); ?></span>
				</div>
			</a>

			<div class="lead-secondary">
				<?php if ( $featured_recipe ) : $fr_country = atlas_chuti_get_recipe_primary_country( $featured_recipe->ID ); ?>
					<a class="lead-secondary-item is-media" href="<?php echo esc_url( get_permalink( $featured_recipe ) ); ?>">
						<div class="lead-secondary-media"><?php echo atlas_chuti_media( $featured_recipe->ID, 'atlas-card', '', 'recipe' ); ?></div>
						<div>
							<span class="kicker is-saffron"><?php esc_html_e( 'Dnes ochutnejte', 'atlas-chuti' ); ?></span>
							<?php if ( $fr_country ) : ?>
								<div class="card-eyebrow"><span><?php echo esc_html( atlas_chuti_flag( $fr_country->ID ) ); ?></span><span><?php echo esc_html( get_the_title( $fr_country ) ); ?></span></div>
							<?php endif; ?>
							<h3><?php echo esc_html( get_the_title( $featured_recipe ) ); ?></h3>
						</div>
					</a>
				<?php endif; ?>

				<?php foreach ( $lead_text_items as $item ) : $item_country = atlas_chuti_get_recipe_primary_country( $item->ID ); ?>
					<a class="lead-secondary-item is-text" href="<?php echo esc_url( get_permalink( $item ) ); ?>">
						<?php if ( $item_country ) : ?>
							<div class="card-eyebrow"><span><?php echo esc_html( atlas_chuti_flag( $item_country->ID ) ); ?></span><span><?php echo esc_html( get_the_title( $item_country ) ); ?></span></div>
						<?php endif; ?>
						<h3><?php echo esc_html( get_the_title( $item ) ); ?></h3>
						<span class="card-meta"><?php echo esc_html( atlas_chuti_recipe_meta_line( $item->ID ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</section>

<div class="container">
	<div class="trending-strip">
		<span class="trending-label"><?php esc_html_e( 'Právě na Atlasu', 'atlas-chuti' ); ?></span>
		<nav class="trending-links" aria-label="<?php esc_attr_e( 'Rychlé odkazy', 'atlas-chuti' ); ?>">
			<a href="<?php echo esc_url( $recipe_archive ); ?>"><?php esc_html_e( 'Nové recepty', 'atlas-chuti' ); ?></a>
			<a href="<?php echo esc_url( atlas_chuti_system_url( 'countries' ) ); ?>"><?php esc_html_e( 'Kuchyně světa', 'atlas-chuti' ); ?></a>
			<?php if ( $continents && ! is_wp_error( $continents ) ) : ?>
				<?php foreach ( $continents as $continent ) : ?>
					<a href="<?php echo esc_url( get_term_link( $continent ) ); ?>"><?php echo esc_html( $continent->name ); ?></a>
				<?php endforeach; ?>
			<?php endif; ?>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'atlas_glossary' ) ); ?>"><?php esc_html_e( 'Kuchařský slovníček', 'atlas-chuti' ); ?></a>
		</nav>
	</div>
</div>
<?php endif; ?>

<?php do_action( 'atlas_chuti_ad_slot', 'after_lead' ); ?>

<section class="section bg-terracotta-tint">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker"><?php esc_html_e( 'Rozhodování za vás', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Co dnes vařit?', 'atlas-chuti' ); ?></h2>
			</div>
		</div>
		<div class="quick-picks">
			<a class="quick-pick" href="<?php echo esc_url( add_query_arg( 'cas', 'do-30', $recipe_archive ) ); ?>">
				<span class="quick-pick-icon" aria-hidden="true">⏱️</span><span><?php esc_html_e( 'Do 30 minut', 'atlas-chuti' ); ?></span>
			</a>
			<?php if ( $czech_country ) : ?>
				<a class="quick-pick" href="<?php echo esc_url( get_permalink( $czech_country ) ); ?>">
					<span class="quick-pick-icon" aria-hidden="true"><?php echo esc_html( atlas_chuti_flag( $czech_country->ID ) ?: '🍽️' ); ?></span><span><?php esc_html_e( 'Česká kuchyně', 'atlas-chuti' ); ?></span>
				</a>
			<?php endif; ?>
			<a class="quick-pick" href="<?php echo esc_url( add_query_arg( 'dieta', 'vegetarian', $recipe_archive ) ); ?>">
				<span class="quick-pick-icon" aria-hidden="true">🥗</span><span><?php esc_html_e( 'Bez masa', 'atlas-chuti' ); ?></span>
			</a>
			<a class="quick-pick" href="<?php echo esc_url( add_query_arg( 'typ', 'soup', $recipe_archive ) ); ?>">
				<span class="quick-pick-icon" aria-hidden="true">🍲</span><span><?php esc_html_e( 'Polévky', 'atlas-chuti' ); ?></span>
			</a>
			<a class="quick-pick" href="<?php echo esc_url( add_query_arg( 'typ', 'dessert', $recipe_archive ) ); ?>">
				<span class="quick-pick-icon" aria-hidden="true">🍰</span><span><?php esc_html_e( 'Sladké', 'atlas-chuti' ); ?></span>
			</a>
			<a class="quick-pick" href="<?php echo esc_url( home_url( '/?atlas_random_country=1' ) ); ?>">
				<span class="quick-pick-icon" aria-hidden="true">🎲</span><span><?php esc_html_e( 'Překvapte mě', 'atlas-chuti' ); ?></span>
			</a>
		</div>
	</div>
</section>

<?php if ( $czech_country && $czech_recipes ) : $czech_main = array_shift( $czech_recipes ); ?>
<section class="section">
	<div class="container">
		<div class="featured-banner">
			<a class="featured-banner-media" href="<?php echo esc_url( get_permalink( $czech_main ) ); ?>">
				<?php echo atlas_chuti_media( $czech_main->ID, 'atlas-hero', '', 'recipe' ); ?>
			</a>
			<div class="featured-banner-body">
				<span class="kicker"><?php echo esc_html( atlas_chuti_flag( $czech_country->ID ) ); ?> <?php esc_html_e( 'Česká kuchyně', 'atlas-chuti' ); ?></span>
				<h2><a href="<?php echo esc_url( get_permalink( $czech_country ) ); ?>"><?php echo esc_html( get_the_title( $czech_country ) ); ?></a></h2>
				<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_post_meta( $czech_country->ID, 'atlas_intro', true ) ), 26 ) ); ?></p>
				<?php if ( $czech_recipes ) : ?>
					<div class="dish-list">
						<a class="dish-row is-link" href="<?php echo esc_url( get_permalink( $czech_main ) ); ?>"><span><?php echo esc_html( get_the_title( $czech_main ) ); ?></span><span class="arrow">→</span></a>
						<?php foreach ( $czech_recipes as $cr ) : ?>
							<a class="dish-row is-link" href="<?php echo esc_url( get_permalink( $cr ) ); ?>"><span><?php echo esc_html( get_the_title( $cr ) ); ?></span><span class="arrow">→</span></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<a class="link-arrow" href="<?php echo esc_url( get_permalink( $czech_country ) ); ?>"><?php esc_html_e( 'Prozkoumat českou kuchyni →', 'atlas-chuti' ); ?></a>
			</div>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $world_picks['dominant_country'] && $world_picks['dominant_recipe'] ) : $wd = $world_picks; ?>
<section class="section bg-sage-tint">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker is-sage"><?php esc_html_e( 'Signature Atlasu', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Co se vaří ve světě', 'atlas-chuti' ); ?></h2>
			</div>
			<a class="more-link link-arrow" href="<?php echo esc_url( atlas_chuti_system_url( 'countries' ) ); ?>"><?php esc_html_e( 'Všechny země →', 'atlas-chuti' ); ?></a>
		</div>
		<div class="world-feature">
			<a class="world-feature-main" href="<?php echo esc_url( get_permalink( $wd['dominant_recipe'] ) ); ?>">
				<div class="card-media"><?php echo atlas_chuti_media( $wd['dominant_recipe']->ID, 'atlas-hero', '', 'recipe' ); ?></div>
				<div class="card-eyebrow"><span><?php echo esc_html( atlas_chuti_flag( $wd['dominant_country']->ID ) ); ?></span><span><?php echo esc_html( get_the_title( $wd['dominant_country'] ) ); ?></span></div>
				<h3><?php echo esc_html( get_the_title( $wd['dominant_recipe'] ) ); ?></h3>
				<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_post_meta( $wd['dominant_recipe']->ID, 'atlas_excerpt', true ) ), 22 ) ); ?></p>
			</a>
			<?php if ( $wd['others'] ) : ?>
				<div class="world-feature-list">
					<?php foreach ( $wd['others'] as $pick ) : ?>
						<a class="world-feature-item" href="<?php echo esc_url( get_permalink( $pick['recipe'] ) ); ?>">
							<div class="world-feature-item-media"><?php echo atlas_chuti_media( $pick['recipe']->ID, 'atlas-card', '', 'recipe' ); ?></div>
							<div>
								<div class="card-eyebrow"><span><?php echo esc_html( atlas_chuti_flag( $pick['country']->ID ) ); ?></span><span><?php echo esc_html( get_the_title( $pick['country'] ) ); ?></span></div>
								<h4><?php echo esc_html( get_the_title( $pick['recipe'] ) ); ?></h4>
							</div>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $continents && ! is_wp_error( $continents ) && count( $continents ) > 1 ) : ?>
			<div class="continent-grid world-continent-teaser">
				<?php foreach ( array_slice( $continents, 0, 3 ) as $continent ) : ?>
					<a class="continent-tile" href="<?php echo esc_url( get_term_link( $continent ) ); ?>">
						<?php echo atlas_chuti_continent_image_html( $continent->term_id ); ?>
						<span><?php echo esc_html( $continent->name ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
<?php endif; ?>

<?php if ( $feed_pool || $glossary_terms ) : ?>
<section class="section">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker"><?php esc_html_e( 'Čerstvě z Atlasu', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Nejnovější recepty', 'atlas-chuti' ); ?></h2>
			</div>
			<a class="more-link link-arrow" href="<?php echo esc_url( $recipe_archive ); ?>"><?php esc_html_e( 'Všechny recepty →', 'atlas-chuti' ); ?></a>
		</div>
		<div class="<?php echo $feed_pool ? 'feed-layout' : ''; ?>">
			<?php if ( $feed_pool ) : ?>
				<div class="feed-list">
					<?php foreach ( $feed_pool as $recipe ) : $recipe_country = atlas_chuti_get_recipe_primary_country( $recipe->ID ); ?>
						<a class="feed-item" href="<?php echo esc_url( get_permalink( $recipe ) ); ?>">
							<div class="feed-item-media"><?php echo atlas_chuti_media( $recipe->ID, 'atlas-card', '', 'recipe' ); ?></div>
							<div class="feed-item-body">
								<?php if ( $recipe_country ) : ?>
									<div class="card-eyebrow"><span><?php echo esc_html( atlas_chuti_flag( $recipe_country->ID ) ); ?></span><span><?php echo esc_html( get_the_title( $recipe_country ) ); ?></span></div>
								<?php endif; ?>
								<h3><?php echo esc_html( get_the_title( $recipe ) ); ?></h3>
								<?php $excerpt = get_post_meta( $recipe->ID, 'atlas_excerpt', true ); ?>
								<?php if ( $excerpt ) : ?>
									<p class="feed-item-excerpt"><?php echo esc_html( wp_trim_words( $excerpt, 18 ) ); ?></p>
								<?php endif; ?>
								<span class="card-meta"><?php echo esc_html( atlas_chuti_recipe_meta_line( $recipe->ID ) ); ?></span>
							</div>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<aside class="feed-sidebar">
				<?php if ( $glossary_terms ) : ?>
					<div class="feed-sidebar-block">
						<h3 class="feed-sidebar-title"><?php esc_html_e( 'Kuchařský slovníček', 'atlas-chuti' ); ?></h3>
						<div class="dish-list">
							<?php foreach ( $glossary_terms as $term ) : ?>
								<a class="dish-row is-link" href="<?php echo esc_url( get_permalink( $term ) ); ?>"><span><?php echo esc_html( get_the_title( $term ) ); ?></span><span class="arrow">→</span></a>
							<?php endforeach; ?>
						</div>
						<a class="link-arrow" href="<?php echo esc_url( get_post_type_archive_link( 'atlas_glossary' ) ); ?>" style="margin-top:var(--space-4);"><?php esc_html_e( 'Celý slovníček →', 'atlas-chuti' ); ?></a>
					</div>
				<?php endif; ?>
				<?php do_action( 'atlas_chuti_ad_slot', 'feed_sidebar' ); ?>
			</aside>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $magazine_posts ) : ?>
<section class="section bg-blue-tint">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker is-blue"><?php esc_html_e( 'Magazín', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Čtení k tématu', 'atlas-chuti' ); ?></h2>
			</div>
		</div>
		<div class="magazine-strip">
			<?php foreach ( $magazine_posts as $post_item ) : ?>
				<a class="magazine-item" href="<?php echo esc_url( get_permalink( $post_item ) ); ?>">
					<div class="magazine-item-media"><?php echo atlas_chuti_media( $post_item->ID, 'atlas-card', '', '' ); ?></div>
					<h3><?php echo esc_html( get_the_title( $post_item ) ); ?></h3>
					<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_item ) ), 18 ) ); ?></p>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $tips_tricks_posts ) : ?>
<section class="section">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker is-saffron"><?php esc_html_e( 'Magazín', 'atlas-chuti' ); ?></span>
				<h2><?php echo esc_html( $tips_tricks_term->name ); ?></h2>
			</div>
			<a class="more-link" href="<?php echo esc_url( get_term_link( $tips_tricks_term ) ); ?>"><?php esc_html_e( 'Zobrazit vše →', 'atlas-chuti' ); ?></a>
		</div>
		<div class="magazine-strip">
			<?php foreach ( $tips_tricks_posts as $post_item ) : ?>
				<a class="magazine-item" href="<?php echo esc_url( get_permalink( $post_item ) ); ?>">
					<div class="magazine-item-media"><?php echo atlas_chuti_media( $post_item->ID, 'atlas-card', '', '' ); ?></div>
					<h3><?php echo esc_html( get_the_title( $post_item ) ); ?></h3>
					<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_item ) ), 18 ) ); ?></p>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $latest_topics ) : ?>
<section class="section bg-blue-tint">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker is-blue"><?php esc_html_e( 'Komunita', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Nová témata v diskuzi', 'atlas-chuti' ); ?></h2>
			</div>
			<a class="more-link" href="<?php echo esc_url( atlas_chuti_discussion_url() ); ?>"><?php esc_html_e( 'Otevřít diskuzi →', 'atlas-chuti' ); ?></a>
		</div>
		<div class="atlas-topic-list">
			<?php foreach ( $latest_topics as $topic ) : ?>
				<?php get_template_part( 'template-parts/topic-row', null, array( 'post_id' => $topic->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php
/**
 * Seasonal curation (6.9) and video/community (6.10): layout hooks only, per the
 * brief — no listener is registered in KROK 1, so both render nothing today. A
 * later step can add real content without touching this template.
 */
$seasonal = apply_filters( 'atlas_chuti_home_seasonal_pick', null );
if ( $seasonal ) {
	do_action( 'atlas_chuti_home_seasonal_block', $seasonal );
}
do_action( 'atlas_chuti_home_after_feed' );
?>

<?php
/**
 * KROK 5, item 32: this panel no longer presents the Kulinářský pas as its own
 * standalone product — it now promotes the broader Můj Atlas account (item 33:
 * oblíbené/uvařené/pas/hodnocení/komentáře), which gets the primary CTA. The
 * anonymous/localStorage mini-widget (data-passport-widget, unchanged — passport.js
 * still renders it for a visitor without an account) stays as a smaller element.
 */
?>
<section class="section" data-passport-widget>
	<div class="container">
		<div class="dark-panel">
			<h2><?php esc_html_e( 'Váš vlastní Atlas chutí', 'atlas-chuti' ); ?></h2>
			<p><?php esc_html_e( 'Ukládejte oblíbené recepty, označujte uvařené, budujte Kulinářský pas, hodnoťte a komentujte — vše na jednom místě, ve vašem účtu.', 'atlas-chuti' ); ?></p>
			<div style="margin:var(--space-5) 0 var(--space-3);">
				<span class="passport-count" data-passport-count>
					<?php
					/* translators: %d: number of countries published on the site */
					echo esc_html( sprintf( __( '0 / %d zemí ochutnáno', 'atlas-chuti' ), $total_countries ) );
					?>
				</span>
			</div>
			<div class="passport-flags" data-passport-flags style="margin-bottom:var(--space-8);"></div>
			<div style="display:flex;flex-wrap:wrap;gap:var(--space-3);">
				<?php if ( is_user_logged_in() ) : ?>
					<a class="btn btn-accent" href="<?php echo esc_url( atlas_chuti_system_url( 'account' ) ); ?>"><?php esc_html_e( 'Otevřít Můj Atlas', 'atlas-chuti' ); ?></a>
				<?php else : ?>
					<a class="btn btn-accent" href="<?php echo esc_url( add_query_arg( 'sekce', 'registrace', atlas_chuti_system_url( 'account' ) ) ); ?>"><?php esc_html_e( 'Vytvořit Můj Atlas', 'atlas-chuti' ); ?></a>
					<a class="btn btn-outline" style="border-color:rgba(255,255,255,0.4);color:#fff;" href="<?php echo esc_url( atlas_chuti_system_url( 'passport' ) ); ?>"><?php esc_html_e( 'Zkusit bez registrace', 'atlas-chuti' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>

<?php get_footer(); ?>
