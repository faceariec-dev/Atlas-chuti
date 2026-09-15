<?php
/**
 * KROK 2 — recipe detail. Layout only: editorial intro → practical metadata →
 * action bar → 2/3 main content + 1/3 sidebar → full-width related content →
 * print-only footer (source line + canonical URL + QR). No content is invented —
 * every block either renders real post-meta/query data or hides itself.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

while ( have_posts() ) :
	the_post();
	$post_id       = get_the_ID();
	$country       = atlas_chuti_get_recipe_primary_country( $post_id );
	$related_terms = wp_get_post_terms( $post_id, 'atlas_country_tax' );
	$canonical_url = get_permalink( $post_id );

	$original_title = get_post_meta( $post_id, 'atlas_original_title', true );
	$excerpt        = get_post_meta( $post_id, 'atlas_excerpt', true );
	$photo_credit   = get_post_meta( $post_id, 'atlas_photo_credit', true );
	$about          = get_post_meta( $post_id, 'atlas_about', true );
	$ingredients    = Atlas_Chuti_Servings::get_scalable_ingredients( $post_id );
	$steps          = get_post_meta( $post_id, 'atlas_steps', true );
	$tips           = get_post_meta( $post_id, 'atlas_tips', true );
	$watch_out      = get_post_meta( $post_id, 'atlas_watch_out', true );
	$variants       = get_post_meta( $post_id, 'atlas_variants', true );
	$origin         = get_post_meta( $post_id, 'atlas_origin_history', true );
	$glossary_ids   = get_post_meta( $post_id, 'atlas_related_glossary', true );
	$recipe_ids     = get_post_meta( $post_id, 'atlas_related_recipes', true );

	$servings_default = (int) get_post_meta( $post_id, 'atlas_servings_default', true ) ?: 4;
	$servings_options  = array( 2, 4, 6, 8 );
	if ( ! in_array( $servings_default, $servings_options, true ) ) {
		$servings_options[] = $servings_default;
		sort( $servings_options );
	}

	$prep       = atlas_chuti_format_time( get_post_meta( $post_id, 'atlas_prep_minutes', true ) );
	$cook       = atlas_chuti_format_time( get_post_meta( $post_id, 'atlas_cook_minutes', true ) );
	$total      = atlas_chuti_format_time( get_post_meta( $post_id, 'atlas_total_minutes', true ) );
	$diff_terms = get_the_terms( $post_id, 'atlas_difficulty' );

	// Keyed by recipe_key (atlas_recipe_key), not slug — a stable,
	// language-independent identifier shared across CZ/EN (KROK 4: recipe_key and
	// translation_group are now separate meta keys with separate roles, see
	// class-json-importer.php's import_recipe() — the Kulinářský pas needs the
	// dish-concept identity, recipe_key, not the Polylang-cross-check field).
	$passport_data = array(
		'recipe_key' => get_post_meta( $post_id, 'atlas_recipe_key', true ) ?: get_post_field( 'post_name', $post_id ),
		'slug'       => get_post_field( 'post_name', $post_id ),
		'title'      => get_the_title(),
		'country'    => $country ? get_the_title( $country ) : '',
		'flag'       => $country ? atlas_chuti_flag( $country->ID ) : '',
		'time'       => $total,
		'difficulty' => $diff_terms && ! is_wp_error( $diff_terms ) ? $diff_terms[0]->name : '',
		'image'      => has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'atlas-card' ) : '',
		'url'        => $canonical_url,
	);

	// Below-recipe content (item 13): similar first, then same-cuisine (excluding
	// whatever similar already showed so the two sections never repeat a recipe),
	// then a standard-posts magazine preview — each hides itself when empty. Computed
	// before the sidebar so "Nové na Atlasu" can in turn avoid repeating either of
	// them (item 12: don't repeat the same recipe in main + sidebar when avoidable).
	$similar_recipes = ! empty( $recipe_ids ) ? array_filter( array_map( 'get_post', (array) $recipe_ids ) ) : array();
	if ( empty( $similar_recipes ) && $related_terms && ! is_wp_error( $related_terms ) ) {
		$similar_recipes = get_posts(
			array(
				'post_type'      => 'atlas_recipe',
				'posts_per_page' => 3,
				'post__not_in'   => array( $post_id ),
				'tax_query'      => array( array( 'taxonomy' => 'atlas_country_tax', 'field' => 'term_id', 'terms' => wp_list_pluck( $related_terms, 'term_id' ) ) ),
			)
		);
	}
	$similar_ids = wp_list_pluck( $similar_recipes, 'ID' );

	$cuisine_recipes = array();
	if ( $related_terms && ! is_wp_error( $related_terms ) ) {
		$cuisine_recipes = get_posts(
			array(
				'post_type'      => 'atlas_recipe',
				'posts_per_page' => 3,
				'post__not_in'   => array_merge( array( $post_id ), $similar_ids ),
				'tax_query'      => array( array( 'taxonomy' => 'atlas_country_tax', 'field' => 'term_id', 'terms' => wp_list_pluck( $related_terms, 'term_id' ) ) ),
			)
		);
	}
	$below_recipe_ids = array_merge( $similar_ids, wp_list_pluck( $cuisine_recipes, 'ID' ) );

	$magazine_posts = atlas_chuti_home_magazine_posts( 3 );

	// Sidebar: real data only, current recipe + anything already shown below the
	// recipe filtered out, nothing invented.
	$sidebar_new = array_values(
		array_filter(
			atlas_chuti_home_new_recipes( 5 + count( $below_recipe_ids ) ),
			function ( $r ) use ( $post_id, $below_recipe_ids ) {
				return $r->ID !== $post_id && ! in_array( $r->ID, $below_recipe_ids, true );
			}
		)
	);
	$sidebar_new = array_slice( $sidebar_new, 0, 4 );

	$today_pick = atlas_chuti_home_featured_recipe();
	if ( $today_pick && ( $today_pick->ID === $post_id || in_array( $today_pick->ID, $below_recipe_ids, true ) ) ) {
		$alt        = get_posts(
			array(
				'post_type'      => 'atlas_recipe',
				'posts_per_page' => 1,
				'post__not_in'   => array_merge( array( $post_id ), $below_recipe_ids ),
				'orderby'        => 'rand',
			)
		);
		$today_pick = $alt ? $alt[0] : null;
	}
	?>

	<section class="container recipe-intro">
		<h1><?php the_title(); ?></h1>
		<?php if ( $original_title ) : ?>
			<p class="recipe-original-title"><?php echo esc_html( $original_title ); ?></p>
		<?php endif; ?>
		<?php if ( $country ) : ?>
			<div class="recipe-origin-meta">
				<a href="<?php echo esc_url( get_permalink( $country ) ); ?>">
					<span aria-hidden="true"><?php echo esc_html( atlas_chuti_flag( $country->ID ) ); ?></span>
					<?php echo esc_html( get_the_title( $country ) ); ?>
				</a>
			</div>
		<?php endif; ?>
		<?php if ( $excerpt ) : ?>
			<p class="recipe-perex"><?php echo esc_html( $excerpt ); ?></p>
		<?php endif; ?>
		<div class="recipe-hero-media" style="aspect-ratio:16/9;">
			<?php echo atlas_chuti_media( $post_id, 'atlas-hero', '', 'recipe', '', true ); ?>
		</div>
		<?php if ( $photo_credit ) : ?>
			<p class="recipe-photo-credit no-print"><?php echo esc_html( $photo_credit ); ?></p>
		<?php endif; ?>
	</section>

	<div class="container">
		<div class="meta-bar">
			<div class="meta-bar-items">
				<?php if ( $prep ) : ?><div class="meta-item"><div class="label"><?php esc_html_e( 'Příprava', 'atlas-chuti' ); ?></div><div class="value"><?php echo esc_html( $prep ); ?></div></div><?php endif; ?>
				<?php if ( $cook ) : ?><div class="meta-item"><div class="label"><?php esc_html_e( 'Vaření', 'atlas-chuti' ); ?></div><div class="value"><?php echo esc_html( $cook ); ?></div></div><?php endif; ?>
				<?php if ( $total ) : ?><div class="meta-item"><div class="label"><?php esc_html_e( 'Celkem', 'atlas-chuti' ); ?></div><div class="value"><?php echo esc_html( $total ); ?></div></div><?php endif; ?>
				<div class="meta-item"><div class="label"><?php esc_html_e( 'Porce', 'atlas-chuti' ); ?></div><div class="value" data-servings-display><?php echo esc_html( $servings_default ); ?></div></div>
				<?php if ( $diff_terms && ! is_wp_error( $diff_terms ) ) : ?><div class="meta-item"><div class="label"><?php esc_html_e( 'Obtížnost', 'atlas-chuti' ); ?></div><div class="value"><?php echo esc_html( $diff_terms[0]->name ); ?></div></div><?php endif; ?>
			</div>
			<a href="#ingredience" class="btn btn-accent no-print"><?php esc_html_e( 'Přejít na recept', 'atlas-chuti' ); ?></a>
		</div>

		<div class="recipe-action-bar no-print">
			<?php if ( is_user_logged_in() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="recipe-action-form" data-state-form data-subject-type="recipe" data-subject-key="<?php echo esc_attr( $passport_data['recipe_key'] ); ?>" data-state="favorite">
					<?php wp_nonce_field( 'atlas_state_toggle', 'atlas_state_nonce' ); ?>
					<input type="hidden" name="action" value="atlas_state_toggle">
					<input type="hidden" name="subject_type" value="recipe">
					<input type="hidden" name="subject_key" value="<?php echo esc_attr( $passport_data['recipe_key'] ); ?>">
					<input type="hidden" name="state" value="favorite">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $canonical_url ); ?>">
					<!-- KROK 5, item 27: always rendered in the NEUTRAL default state — a page
					cache must never bake one visitor's favorite status into HTML another
					visitor could also receive. assets/js/my-atlas.js fetches the real state
					right after load and updates this button in place. -->
					<button type="submit" class="recipe-action-btn" data-favorite-btn aria-pressed="false">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 21s-7-4.35-9.5-8.5C.5 8.5 2.5 5 6 5c2 0 3.5 1 4.5 2.5C11.5 6 13 5 15 5c3.5 0 5.5 3.5 3.5 7.5C19 16.65 12 21 12 21z"></path></svg>
						<span class="label"><?php esc_html_e( 'Oblíbené', 'atlas-chuti' ); ?></span>
					</button>
				</form>
			<?php else : ?>
				<a class="recipe-action-btn" href="<?php echo esc_url( add_query_arg( array( 'sekce' => 'prihlaseni', 'redirect_to' => $canonical_url ), atlas_chuti_system_url( 'account' ) ) ); ?>">
					<?php esc_html_e( 'Oblíbené', 'atlas-chuti' ); ?>
				</a>
			<?php endif; ?>
			<button type="button" class="recipe-action-btn" data-passport-recipe-toggle data-recipe='<?php echo esc_attr( wp_json_encode( $passport_data ) ); ?>' aria-pressed="false">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="4,13 9,18 20,6"></polyline></svg>
				<span class="label"><?php esc_html_e( 'Uvařil/a jsem', 'atlas-chuti' ); ?></span>
			</button>
			<a class="recipe-action-btn" href="#hodnoceni">
				<?php esc_html_e( 'Ohodnotit', 'atlas-chuti' ); ?>
			</a>
			<a class="recipe-action-btn" href="#komentare">
				<?php esc_html_e( 'Komentáře', 'atlas-chuti' ); ?>
			</a>
			<button type="button" class="recipe-action-btn" data-print-trigger>
				<?php esc_html_e( 'Tisk', 'atlas-chuti' ); ?>
			</button>
			<button type="button" class="recipe-action-btn" data-share-trigger data-share-url="<?php echo esc_url( $canonical_url ); ?>" data-share-title="<?php echo esc_attr( get_the_title() ); ?>">
				<span class="label"><?php esc_html_e( 'Sdílet', 'atlas-chuti' ); ?></span>
			</button>
		</div>

		<div class="recipe-layout">
			<div class="recipe-main">

				<?php if ( $about ) : ?>
				<section>
					<h2><?php esc_html_e( 'O receptu', 'atlas-chuti' ); ?></h2>
					<div><?php echo wp_kses_post( wpautop( $about ) ); ?></div>
				</section>
				<?php endif; ?>

				<?php if ( $ingredients || $steps ) : ?>
				<section id="ingredience">
					<div class="recipe-body-grid">
						<?php if ( $ingredients ) : ?>
						<div class="ingredient-panel">
							<div class="section-head" style="margin-bottom:var(--space-5);">
								<h2 style="font-size:var(--fs-h3);margin:0;"><?php esc_html_e( 'Ingredience', 'atlas-chuti' ); ?></h2>
								<div class="pill-group no-print" data-servings-switcher data-default="<?php echo esc_attr( $servings_default ); ?>">
									<?php foreach ( $servings_options as $n ) : ?>
										<button type="button" class="pill<?php echo $n === $servings_default ? ' is-active' : ''; ?>" data-servings="<?php echo esc_attr( $n ); ?>"><?php echo esc_html( $n ); ?></button>
									<?php endforeach; ?>
								</div>
							</div>
							<ul class="ingredient-list" data-ingredient-list data-ingredients='<?php echo esc_attr( wp_json_encode( $ingredients ) ); ?>'>
								<?php
								$prev_group = null;
								foreach ( $ingredients as $i => $ing ) :
									if ( ! empty( $ing['group'] ) && $ing['group'] !== $prev_group ) :
										echo '<li class="group-label">' . esc_html( $ing['group'] ) . '</li>';
										$prev_group = $ing['group'];
									endif;
									?>
									<li class="ingredient-row" data-index="<?php echo esc_attr( $i ); ?>">
										<span class="name"><?php echo esc_html( $ing['display_name'] ); ?><?php echo $ing['note'] ? ' <span style="color:var(--color-muted);">(' . esc_html( $ing['note'] ) . ')</span>' : ''; ?></span>
										<span class="amount"><?php echo esc_html( trim( $ing['quantity'] . ' ' . $ing['unit'] ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
						<?php else : ?>
						<div></div>
						<?php endif; ?>

						<?php if ( $steps ) : ?>
						<div id="postup">
							<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Postup', 'atlas-chuti' ); ?></h2>
							<ol class="steps-list">
								<?php foreach ( $steps as $i => $step ) : ?>
									<li class="step-row">
										<span class="step-num" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
										<p class="step-text"><?php echo esc_html( $step['text'] ); ?></p>
									</li>
								<?php endforeach; ?>
							</ol>
						</div>
						<?php endif; ?>
					</div>
				</section>
				<?php endif; ?>

				<?php
				// KROK 7, item 10: "po smysluplné části receptu" — right after
				// ingredients+instructions, never between a step/ingredient (item 10's
				// own explicit prohibition), and only ever rendered at all once an
				// admin actually turns this slot on (see class-advertising.php).
				atlas_chuti_render_ad_slot( 'recipe_in_content' );
				?>

				<?php if ( $tips ) : ?>
				<section class="bg-sage-tint" style="border-radius:var(--radius-lg);padding:var(--space-6) var(--space-8);margin-top:var(--space-10);">
					<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Tipy', 'atlas-chuti' ); ?></h2>
					<ul style="padding-left:20px;list-style:disc;display:flex;flex-direction:column;gap:10px;">
						<?php foreach ( $tips as $tip ) : ?>
							<li style="font-size:15px;color:var(--color-text);line-height:1.6;"><?php echo esc_html( $tip ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
				<?php endif; ?>

				<?php if ( $watch_out ) : ?>
				<section style="margin-top:var(--space-10);">
					<div class="callout bg-saffron-tint">
						<h3><?php esc_html_e( 'Na co si dát pozor', 'atlas-chuti' ); ?></h3>
						<p><?php echo esc_html( $watch_out ); ?></p>
					</div>
				</section>
				<?php endif; ?>

				<?php if ( $variants ) : ?>
				<section style="margin-top:var(--space-10);">
					<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Varianty receptu', 'atlas-chuti' ); ?></h2>
					<div>
						<?php foreach ( $variants as $variant ) : ?>
							<div class="variant-row"><strong><?php echo esc_html( $variant['name'] ); ?>:</strong> <?php echo esc_html( $variant['note'] ); ?></div>
						<?php endforeach; ?>
					</div>
				</section>
				<?php endif; ?>

				<?php if ( $origin ) : ?>
				<section class="bg-blue-tint" style="border-radius:var(--radius-lg);padding:var(--space-6) var(--space-8);margin-top:var(--space-10);">
					<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Odkud recept pochází', 'atlas-chuti' ); ?></h2>
					<div><?php echo wp_kses_post( wpautop( $origin ) ); ?></div>
				</section>
				<?php endif; ?>

				<?php if ( $glossary_ids ) : ?>
				<section style="margin-top:var(--space-10);">
					<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Pojmy, které se mohou hodit', 'atlas-chuti' ); ?></h2>
					<div class="flex-wrap-gap">
						<?php foreach ( (array) $glossary_ids as $gid ) : if ( 'publish' !== get_post_status( $gid ) ) { continue; } ?>
							<a class="chip" href="<?php echo esc_url( get_permalink( $gid ) ); ?>"><?php echo esc_html( get_the_title( $gid ) ); ?></a>
						<?php endforeach; ?>
					</div>
				</section>
				<?php endif; ?>

				<?php
				// Controlled tag system is Krok 3 scope — this renders nothing until
				// something is hooked, never an empty box (item 13.3).
				atlas_chuti_hook_slot( 'atlas_chuti_recipe_tags', 'recipe-tags-hook', $post_id );
				?>

				<?php
				// KROK 7, item 10: optional "after content" slot — end of the main
				// content column, after everything else, before the sidebar closes.
				atlas_chuti_render_ad_slot( 'recipe_after_content' );
				?>
			</div>

			<aside class="recipe-sidebar no-print">
				<?php atlas_chuti_hook_slot( 'atlas_chuti_recipe_sidebar_ad', 'sidebar-block ad-slot', $post_id ); ?>

				<?php if ( $sidebar_new ) : ?>
				<div class="sidebar-block feed-sidebar-block" style="position:static;">
					<h2 class="feed-sidebar-title"><?php esc_html_e( 'Nové na Atlasu', 'atlas-chuti' ); ?></h2>
					<div class="sidebar-recipe-list">
						<?php foreach ( $sidebar_new as $r ) : ?>
							<a class="sidebar-recipe-item" href="<?php echo esc_url( get_permalink( $r ) ); ?>">
								<div class="sidebar-recipe-media"><?php echo atlas_chuti_media( $r->ID, 'atlas-card', '', 'recipe' ); ?></div>
								<div class="sidebar-recipe-body">
									<h3><?php echo esc_html( get_the_title( $r ) ); ?></h3>
									<div class="sidebar-recipe-meta"><?php echo esc_html( atlas_chuti_recipe_meta_line( $r->ID ) ); ?></div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>

				<?php
				// "Nejlépe hodnocené" is deliberately omitted: no rating data exists yet
				// anywhere in the project, and this step must never show fake ratings.
				?>

				<?php if ( $today_pick ) : ?>
				<div class="sidebar-block feed-sidebar-block" style="position:static;">
					<h2 class="feed-sidebar-title"><?php esc_html_e( 'Ochutnejte dnes', 'atlas-chuti' ); ?></h2>
					<a class="sidebar-featured-card" href="<?php echo esc_url( get_permalink( $today_pick ) ); ?>">
						<div class="card-media"><?php echo atlas_chuti_media( $today_pick->ID, 'atlas-card', '', 'recipe' ); ?></div>
						<h3><?php echo esc_html( get_the_title( $today_pick ) ); ?></h3>
					</a>
				</div>
				<?php endif; ?>
			</aside>
		</div>
	</div>

	<?php if ( $similar_recipes ) : ?>
	<section class="section bg-cream recipe-related-section">
		<div class="container">
			<h2><?php esc_html_e( 'Podobné recepty', 'atlas-chuti' ); ?></h2>
			<div class="card-grid card-grid-3">
				<?php foreach ( $similar_recipes as $r ) : ?>
					<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => is_object( $r ) ? $r->ID : $r ) ); ?>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $cuisine_recipes ) : ?>
	<section class="section recipe-related-section">
		<div class="container">
			<h2><?php echo $country ? esc_html( sprintf( __( 'Další recepty z %s', 'atlas-chuti' ), get_the_title( $country ) ) ) : esc_html__( 'Další recepty z této kuchyně', 'atlas-chuti' ); ?></h2>
			<div class="card-grid card-grid-3">
				<?php foreach ( $cuisine_recipes as $r ) : ?>
					<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $r->ID ) ); ?>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $magazine_posts ) : ?>
	<section class="section bg-cream recipe-related-section">
		<div class="container">
			<h2><?php esc_html_e( 'Přečtěte si', 'atlas-chuti' ); ?></h2>
			<div class="magazine-strip">
				<?php foreach ( $magazine_posts as $p ) : ?>
					<a class="magazine-item" href="<?php echo esc_url( get_permalink( $p ) ); ?>">
						<div class="magazine-item-media"><?php echo atlas_chuti_media( $p->ID, 'atlas-card', '', '' ); ?></div>
						<h3><?php echo esc_html( get_the_title( $p ) ); ?></h3>
						<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $p ) ), 18 ) ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php atlas_chuti_hook_slot( 'atlas_chuti_recipe_community', 'recipe-community-hook container section', $post_id ); ?>

	<div class="print-only recipe-print-footer">
		<div>
			<p><strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong> — <?php the_title(); ?></p>
			<p class="recipe-print-url"><?php echo esc_html( $canonical_url ); ?></p>
		</div>
		<?php echo Atlas_Chuti_QRCode::svg( $canonical_url, 96 ); ?>
	</div>

<?php endwhile; ?>

<?php get_footer(); ?>
