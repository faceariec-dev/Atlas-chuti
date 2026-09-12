<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

while ( have_posts() ) :
	the_post();
	$post_id     = get_the_ID();
	$continent   = get_the_terms( $post_id, 'atlas_continent' );
	$continent_name = $continent && ! is_wp_error( $continent ) ? $continent[0]->name : '';

	$facts = array_filter(
		array(
			__( 'Hlavní město', 'atlas-chuti' ) => get_post_meta( $post_id, 'atlas_capital', true ),
			__( 'Světadíl', 'atlas-chuti' )     => $continent_name,
			__( 'Jazyk', 'atlas-chuti' )        => implode( ', ', (array) get_post_meta( $post_id, 'atlas_languages', true ) ),
			__( 'Měna', 'atlas-chuti' )         => get_post_meta( $post_id, 'atlas_currency', true ),
			__( 'Populace', 'atlas-chuti' )     => get_post_meta( $post_id, 'atlas_population', true ) ? number_format_i18n( get_post_meta( $post_id, 'atlas_population', true ) ) : '',
			__( 'Rozloha', 'atlas-chuti' )      => get_post_meta( $post_id, 'atlas_area_km2', true ) ? number_format_i18n( get_post_meta( $post_id, 'atlas_area_km2', true ) ) . ' km²' : '',
		)
	);

	$intro        = get_post_meta( $post_id, 'atlas_intro', true );
	$taste_intro  = get_post_meta( $post_id, 'atlas_taste_intro', true );
	$ingredients  = get_post_meta( $post_id, 'atlas_typical_ingredients', true );
	$dishes       = get_post_meta( $post_id, 'atlas_traditional_dishes', true );
	$must_try     = get_post_meta( $post_id, 'atlas_must_try', true );
	$fun_facts    = get_post_meta( $post_id, 'atlas_fun_facts', true );
	$glossary_ids = get_post_meta( $post_id, 'atlas_related_glossary', true );
	$related_ids  = get_post_meta( $post_id, 'atlas_related_countries', true );
	$recipes      = atlas_chuti_get_recipes_for_country( $post_id, 3 );

	// Keyed by ISO code, not slug — a stable, language-independent identifier
	// (item 18 of this phase's brief), so the passport still makes sense once an
	// English version of this same country exists at a different slug.
	$passport_data = array(
		'iso'       => get_post_meta( $post_id, 'atlas_iso_code', true ),
		'slug'      => get_post_field( 'post_name', $post_id ),
		'name'      => get_the_title(),
		'flag'      => atlas_chuti_flag( $post_id ),
		'continent' => $continent_name,
	);
	?>

	<section class="container section" style="padding-top:var(--space-10);padding-bottom:var(--space-6);">
		<div class="hero-grid hero-grid-4060">
			<div class="hero-copy" style="align-items:flex-start;gap:var(--space-3);">
				<?php if ( $continent_name ) : ?><span class="kicker is-sage"><?php echo esc_html( $continent_name ); ?></span><?php endif; ?>
				<h1 style="margin:0;display:flex;align-items:center;gap:14px;">
					<span style="font-size:0.7em;"><?php echo esc_html( atlas_chuti_flag( $post_id ) ); ?></span>
					<?php the_title(); ?>
				</h1>
				<?php if ( $intro ) : ?><p class="lede" style="max-width:none;"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $intro ), 26 ) ); ?></p><?php endif; ?>
				<button type="button" class="btn btn-outline" data-passport-country-toggle data-country='<?php echo esc_attr( wp_json_encode( $passport_data ) ); ?>' style="margin-top:var(--space-2);">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle></svg>
					<span class="label"><?php esc_html_e( 'Označit jako ochutnané', 'atlas-chuti' ); ?></span>
				</button>
			</div>
			<div class="hero-media" style="aspect-ratio:4/3;">
				<?php echo atlas_chuti_media( $post_id, 'atlas-hero', get_the_title() ); ?>
			</div>
		</div>
	</section>

	<?php if ( $facts ) : ?>
	<section class="container">
		<div class="facts-grid">
			<?php foreach ( $facts as $label => $value ) : ?>
				<div class="fact"><div class="label"><?php echo esc_html( $label ); ?></div><div class="value"><?php echo esc_html( $value ); ?></div></div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $intro || $taste_intro ) : ?>
	<section class="section bg-cream">
		<div class="container-medium">
			<span class="kicker is-sage"><?php esc_html_e( 'Jak chutná', 'atlas-chuti' ); ?></span>
			<h2><?php echo esc_html( sprintf( __( 'Jak chutná %s', 'atlas-chuti' ), get_the_title() ) ); ?></h2>
			<?php if ( $intro ) : ?><div><?php echo wp_kses_post( wpautop( $intro ) ); ?></div><?php endif; ?>
			<?php if ( $taste_intro ) : ?><div><?php echo wp_kses_post( wpautop( $taste_intro ) ); ?></div><?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $ingredients ) : ?>
	<section class="container section">
		<h2><?php esc_html_e( 'Typické suroviny', 'atlas-chuti' ); ?></h2>
		<div class="ingredient-chips">
			<?php foreach ( (array) $ingredients as $ing ) : ?>
				<span class="tag"><?php echo esc_html( $ing ); ?></span>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $dishes ) : ?>
	<section class="section bg-sage-tint">
		<div class="container">
			<h2><?php echo esc_html( sprintf( __( 'Co se v %s jí', 'atlas-chuti' ), get_the_title() ) ); ?></h2>
			<div class="dish-list">
				<?php foreach ( (array) $dishes as $dish ) : $has_recipe = ! empty( $dish['recipe_id'] ) && 'publish' === get_post_status( $dish['recipe_id'] ); ?>
					<?php if ( $has_recipe ) : ?>
						<a class="dish-row is-link" href="<?php echo esc_url( get_permalink( $dish['recipe_id'] ) ); ?>"><?php echo esc_html( $dish['name'] ); ?><span class="arrow">→</span></a>
					<?php else : ?>
						<div class="dish-row" title="<?php echo esc_attr( $dish['note'] ); ?>"><?php echo esc_html( $dish['name'] ); ?></div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $must_try ) : ?>
	<section class="container section">
		<div class="dark-panel">
			<h2><?php echo esc_html( sprintf( __( '5 jídel, která byste měli v %s ochutnat', 'atlas-chuti' ), get_the_title() ) ); ?></h2>
			<div class="must-try-list">
				<?php foreach ( (array) $must_try as $i => $item ) : ?>
					<div class="must-try-item">
						<span class="num"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
						<div>
							<h3><?php echo esc_html( $item['name'] ); ?></h3>
							<p><?php echo esc_html( $item['note'] ); ?></p>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $fun_facts ) : ?>
	<section class="section bg-saffron-tint">
		<div class="container">
			<h2><?php echo esc_html( sprintf( __( 'Zajímavosti o %s kuchyni', 'atlas-chuti' ), get_the_title() ) ); ?></h2>
			<div class="fun-facts-grid">
				<?php foreach ( (array) $fun_facts as $fact ) : ?>
					<div class="fun-fact"><?php echo esc_html( $fact ); ?></div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $recipes ) : ?>
	<section class="container section">
		<h2><?php echo esc_html( sprintf( __( '%s recepty', 'atlas-chuti' ), get_the_title() ) ); ?></h2>
		<div class="card-grid card-grid-3">
			<?php foreach ( $recipes as $r ) : ?>
				<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $r->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $glossary_ids ) : ?>
	<section class="container section bg-blue-tint">
		<h2><?php esc_html_e( 'Z kuchařského slovníčku', 'atlas-chuti' ); ?></h2>
		<div class="flex-wrap-gap">
			<?php foreach ( (array) $glossary_ids as $gid ) : if ( 'publish' !== get_post_status( $gid ) ) { continue; } ?>
				<a class="chip" href="<?php echo esc_url( get_permalink( $gid ) ); ?>"><?php echo esc_html( get_the_title( $gid ) ); ?></a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $related_ids ) : ?>
	<section class="container section">
		<h2><?php echo esc_html( sprintf( __( 'Chutná vám %s? Objevte i další kuchyně.', 'atlas-chuti' ), get_the_title() ) ); ?></h2>
		<div class="card-grid card-grid-3" style="margin-top:var(--space-6);">
			<?php foreach ( (array) $related_ids as $rid ) : if ( 'publish' !== get_post_status( $rid ) ) { continue; } ?>
				<?php get_template_part( 'template-parts/country-card', null, array( 'post_id' => $rid ) ); ?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

<?php endwhile; ?>

<?php get_footer(); ?>
