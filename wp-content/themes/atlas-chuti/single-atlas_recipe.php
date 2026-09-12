<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

while ( have_posts() ) :
	the_post();
	$post_id       = get_the_ID();
	$country       = atlas_chuti_get_recipe_primary_country( $post_id );
	$related_terms = wp_get_post_terms( $post_id, 'atlas_country_tax' );

	$original_title = get_post_meta( $post_id, 'atlas_original_title', true );
	$excerpt      = get_post_meta( $post_id, 'atlas_excerpt', true );
	$about        = get_post_meta( $post_id, 'atlas_about', true );
	$ingredients  = Atlas_Chuti_Servings::get_scalable_ingredients( $post_id );
	$steps        = get_post_meta( $post_id, 'atlas_steps', true );
	$tips         = get_post_meta( $post_id, 'atlas_tips', true );
	$watch_out    = get_post_meta( $post_id, 'atlas_watch_out', true );
	$variants     = get_post_meta( $post_id, 'atlas_variants', true );
	$origin       = get_post_meta( $post_id, 'atlas_origin_history', true );
	$glossary_ids = get_post_meta( $post_id, 'atlas_related_glossary', true );
	$recipe_ids   = get_post_meta( $post_id, 'atlas_related_recipes', true );

	$servings_default = (int) get_post_meta( $post_id, 'atlas_servings_default', true ) ?: 4;
	$servings_options  = array( 2, 4, 6, 8 );
	if ( ! in_array( $servings_default, $servings_options, true ) ) {
		$servings_options[] = $servings_default;
		sort( $servings_options );
	}

	$prep  = atlas_chuti_format_time( get_post_meta( $post_id, 'atlas_prep_minutes', true ) );
	$cook  = atlas_chuti_format_time( get_post_meta( $post_id, 'atlas_cook_minutes', true ) );
	$total = atlas_chuti_format_time( get_post_meta( $post_id, 'atlas_total_minutes', true ) );
	$diff_terms = get_the_terms( $post_id, 'atlas_difficulty' );

	// Keyed by recipe_key (atlas_translation_group), not slug — a stable,
	// language-independent identifier (item 18 of this phase's brief).
	$passport_data = array(
		'recipe_key' => get_post_meta( $post_id, 'atlas_translation_group', true ) ?: get_post_field( 'post_name', $post_id ),
		'slug'       => get_post_field( 'post_name', $post_id ),
		'title'      => get_the_title(),
		'country'    => $country ? get_the_title( $country ) : '',
		'flag'       => $country ? atlas_chuti_flag( $country->ID ) : '',
		'time'       => $total,
		'difficulty' => $diff_terms && ! is_wp_error( $diff_terms ) ? $diff_terms[0]->name : '',
		'image'      => has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'atlas-card' ) : '',
		'url'        => get_permalink(),
	);
	?>

	<section class="container section" style="padding-top:var(--space-10);padding-bottom:var(--space-10);">
		<div class="hero-grid hero-grid-4060">
			<div class="hero-copy" style="align-items:flex-start;gap:var(--space-3);">
				<?php if ( $country ) : ?>
					<a href="<?php echo esc_url( get_permalink( $country ) ); ?>" class="kicker" style="color:inherit;text-decoration:none;">
						<span style="font-size:15px;margin-right:6px;"><?php echo esc_html( atlas_chuti_flag( $country->ID ) ); ?></span><?php echo esc_html( get_the_title( $country ) ); ?>
					</a>
				<?php endif; ?>
				<h1 style="margin:0;"><?php the_title(); ?></h1>
				<?php if ( $original_title ) : ?><p style="margin:0;color:var(--color-muted);font-style:italic;"><?php echo esc_html( $original_title ); ?></p><?php endif; ?>
				<?php if ( $excerpt ) : ?><p class="lede" style="max-width:none;"><?php echo esc_html( $excerpt ); ?></p><?php endif; ?>
			</div>
			<div class="hero-media" style="aspect-ratio:4/3;">
				<?php echo atlas_chuti_media( $post_id, 'atlas-hero' ); ?>
			</div>
		</div>
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
			<a href="#ingredience" class="btn btn-accent"><?php esc_html_e( 'Přejít na recept', 'atlas-chuti' ); ?></a>
		</div>
	</div>

	<?php if ( $about ) : ?>
	<section class="container-narrow section">
		<h2><?php esc_html_e( 'O receptu', 'atlas-chuti' ); ?></h2>
		<div><?php echo wp_kses_post( wpautop( $about ) ); ?></div>
	</section>
	<?php endif; ?>

	<?php if ( $ingredients || $steps ) : ?>
	<section id="ingredience" class="container section">
		<div class="recipe-body-grid">
			<?php if ( $ingredients ) : ?>
			<div class="ingredient-panel">
				<div class="section-head" style="margin-bottom:var(--space-5);">
					<h2 style="font-size:var(--fs-h3);margin:0;"><?php esc_html_e( 'Ingredience', 'atlas-chuti' ); ?></h2>
					<div class="pill-group" data-servings-switcher data-default="<?php echo esc_attr( $servings_default ); ?>">
						<?php foreach ( $servings_options as $n ) : ?>
							<button type="button" class="pill<?php echo $n === $servings_default ? ' is-active' : ''; ?>" data-servings="<?php echo esc_attr( $n ); ?>"><?php echo esc_html( $n ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="ingredient-list" data-ingredient-list data-ingredients='<?php echo esc_attr( wp_json_encode( $ingredients ) ); ?>'>
					<?php
					$prev_group = null;
					foreach ( $ingredients as $i => $ing ) :
						if ( ! empty( $ing['group'] ) && $ing['group'] !== $prev_group ) :
							echo '<div class="group-label">' . esc_html( $ing['group'] ) . '</div>';
							$prev_group = $ing['group'];
						endif;
						?>
						<div class="ingredient-row" data-index="<?php echo esc_attr( $i ); ?>">
							<span class="name"><?php echo esc_html( $ing['display_name'] ); ?><?php echo $ing['note'] ? ' <span style="color:var(--color-muted);">(' . esc_html( $ing['note'] ) . ')</span>' : ''; ?></span>
							<span class="amount"><?php echo esc_html( trim( $ing['quantity'] . ' ' . $ing['unit'] ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php else : ?>
			<div></div>
			<?php endif; ?>

			<?php if ( $steps ) : ?>
			<div id="postup">
				<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Postup', 'atlas-chuti' ); ?></h2>
				<div class="steps-list">
					<?php foreach ( $steps as $i => $step ) : ?>
						<div class="step-row">
							<span class="step-num"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
							<p class="step-text"><?php echo esc_html( $step['text'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $tips ) : ?>
	<section class="section bg-sage-tint">
		<div class="container-narrow">
			<h2><?php esc_html_e( 'Tipy', 'atlas-chuti' ); ?></h2>
			<ul style="padding-left:20px;list-style:disc;display:flex;flex-direction:column;gap:10px;">
				<?php foreach ( $tips as $tip ) : ?>
					<li style="font-size:15px;color:var(--color-text);line-height:1.6;"><?php echo esc_html( $tip ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $watch_out ) : ?>
	<section class="section bg-saffron-tint">
		<div class="container-narrow">
			<div class="callout">
				<h3><?php esc_html_e( 'Na co si dát pozor', 'atlas-chuti' ); ?></h3>
				<p><?php echo esc_html( $watch_out ); ?></p>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $variants ) : ?>
	<section class="section">
		<div class="container-narrow">
			<h2><?php esc_html_e( 'Varianty receptu', 'atlas-chuti' ); ?></h2>
			<div>
				<?php foreach ( $variants as $variant ) : ?>
					<div class="variant-row"><strong><?php echo esc_html( $variant['name'] ); ?>:</strong> <?php echo esc_html( $variant['note'] ); ?></div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $origin ) : ?>
	<section class="section bg-blue-tint">
		<div class="container-narrow">
			<h2><?php esc_html_e( 'Odkud recept pochází', 'atlas-chuti' ); ?></h2>
			<div><?php echo wp_kses_post( wpautop( $origin ) ); ?></div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $glossary_ids ) : ?>
	<section class="container section">
		<h2><?php esc_html_e( 'Pojmy, které se mohou hodit', 'atlas-chuti' ); ?></h2>
		<div class="flex-wrap-gap">
			<?php foreach ( (array) $glossary_ids as $gid ) : if ( 'publish' !== get_post_status( $gid ) ) { continue; } ?>
				<a class="chip" href="<?php echo esc_url( get_permalink( $gid ) ); ?>"><?php echo esc_html( get_the_title( $gid ) ); ?></a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php
	$more_recipes = ! empty( $recipe_ids ) ? array_filter( array_map( 'get_post', (array) $recipe_ids ) ) : array();
	if ( empty( $more_recipes ) && $related_terms && ! is_wp_error( $related_terms ) ) {
		$more_recipes = get_posts(
			array(
				'post_type'      => 'atlas_recipe',
				'posts_per_page' => 3,
				'post__not_in'   => array( $post_id ),
				'tax_query'      => array( array( 'taxonomy' => 'atlas_country_tax', 'field' => 'term_id', 'terms' => wp_list_pluck( $related_terms, 'term_id' ) ) ),
			)
		);
	}
	if ( $more_recipes ) :
		?>
		<section class="section bg-cream">
			<div class="container">
				<h2><?php echo $country ? esc_html( sprintf( __( 'Další recepty z %s', 'atlas-chuti' ), get_the_title( $country ) ) ) : esc_html__( 'Další recepty', 'atlas-chuti' ); ?></h2>
				<div class="card-grid card-grid-3">
					<?php foreach ( $more_recipes as $r ) : ?>
						<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => is_object( $r ) ? $r->ID : $r ) ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<section class="container section text-center">
		<button type="button" class="btn btn-dark" data-passport-recipe-toggle data-recipe='<?php echo esc_attr( wp_json_encode( $passport_data ) ); ?>'>
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><polyline points="4,13 9,18 20,6"></polyline></svg>
			<span class="label"><?php esc_html_e( 'Uvařil/a jsem', 'atlas-chuti' ); ?></span>
		</button>
	</section>

<?php endwhile; ?>

<?php get_footer(); ?>
