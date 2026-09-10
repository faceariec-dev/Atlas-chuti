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
			'Hlavní město' => get_post_meta( $post_id, 'atlas_capital', true ),
			'Světadíl'     => $continent_name,
			'Jazyk'        => implode( ', ', (array) get_post_meta( $post_id, 'atlas_languages', true ) ),
			'Měna'         => get_post_meta( $post_id, 'atlas_currency', true ),
			'Populace'     => get_post_meta( $post_id, 'atlas_population', true ) ? number_format_i18n( get_post_meta( $post_id, 'atlas_population', true ) ) : '',
			'Rozloha'      => get_post_meta( $post_id, 'atlas_area_km2', true ) ? number_format_i18n( get_post_meta( $post_id, 'atlas_area_km2', true ) ) . ' km²' : '',
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
	$recipes      = atlas_chuti_get_recipes_for_country( $post_id, 4 );

	$passport_data = array(
		'slug'      => get_post_field( 'post_name', $post_id ),
		'name'      => get_the_title(),
		'flag'      => atlas_chuti_flag( $post_id ),
		'continent' => $continent_name,
	);
	?>

	<section class="page-hero-photo">
		<?php if ( has_post_thumbnail() ) : the_post_thumbnail( 'atlas-hero' ); else : ?><div class="placeholder-media"></div><?php endif; ?>
		<div class="page-hero-photo-inner">
			<div class="card-eyebrow eyebrow" style="gap:12px;margin-bottom:10px;">
				<span style="font-size:30px;"><?php echo esc_html( atlas_chuti_flag( $post_id ) ); ?></span>
				<span><?php echo esc_html( $continent_name ); ?></span>
			</div>
			<h1><?php the_title(); ?></h1>
			<?php if ( $intro ) : ?><p class="lede"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $intro ), 20 ) ); ?></p><?php endif; ?>
		</div>
	</section>

	<section class="container" style="padding-top:44px;">
		<div class="facts-grid">
			<?php foreach ( $facts as $label => $value ) : ?>
				<div class="fact"><div class="label"><?php echo esc_html( $label ); ?></div><div class="value"><?php echo esc_html( $value ); ?></div></div>
			<?php endforeach; ?>
		</div>
		<div style="margin-top:20px;">
			<button type="button" class="btn btn-outline" data-passport-country-toggle data-country='<?php echo esc_attr( wp_json_encode( $passport_data ) ); ?>'>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle></svg>
				<span class="label">Označit jako ochutnané</span>
			</button>
		</div>
	</section>

	<?php if ( $intro || $taste_intro ) : ?>
	<section class="container-medium section">
		<h2>Jak chutná <?php echo esc_html( get_the_title() ); ?></h2>
		<?php if ( $intro ) : ?><div><?php echo wp_kses_post( wpautop( $intro ) ); ?></div><?php endif; ?>
		<?php if ( $taste_intro ) : ?><div><?php echo wp_kses_post( wpautop( $taste_intro ) ); ?></div><?php endif; ?>
	</section>
	<?php endif; ?>

	<?php if ( $ingredients ) : ?>
	<section class="container section">
		<h2>Typické suroviny</h2>
		<div class="ingredient-chips">
			<?php foreach ( (array) $ingredients as $ing ) : ?>
				<span class="chip chip-static">🌿 <?php echo esc_html( $ing ); ?></span>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $dishes ) : ?>
	<section class="container section">
		<h2>Co se v <?php echo esc_html( get_the_title() ); ?> jí</h2>
		<div class="card-grid card-grid-3">
			<?php foreach ( (array) $dishes as $dish ) : $has_recipe = ! empty( $dish['recipe_id'] ) && 'publish' === get_post_status( $dish['recipe_id'] ); ?>
				<?php if ( $has_recipe ) : ?>
					<a class="dish-row is-link" href="<?php echo esc_url( get_permalink( $dish['recipe_id'] ) ); ?>"><?php echo esc_html( $dish['name'] ); ?><span class="arrow">→</span></a>
				<?php else : ?>
					<div class="dish-row" title="<?php echo esc_attr( $dish['note'] ); ?>"><?php echo esc_html( $dish['name'] ); ?></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $must_try ) : ?>
	<section class="container section">
		<div class="dark-panel">
			<h2>5 jídel, která byste měli v <?php echo esc_html( get_the_title() ); ?> ochutnat</h2>
			<div class="must-try-grid">
				<?php foreach ( (array) $must_try as $i => $item ) : ?>
					<div class="must-try-item">
						<span class="num"><?php echo esc_html( $i + 1 ); ?></span>
						<h3><?php echo esc_html( $item['name'] ); ?></h3>
						<p><?php echo esc_html( $item['note'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $fun_facts ) : ?>
	<section class="container section">
		<h2>Zajímavosti o <?php echo esc_html( get_the_title() ); ?> kuchyni</h2>
		<div class="fun-facts-grid">
			<?php foreach ( (array) $fun_facts as $fact ) : ?>
				<div class="fun-fact"><?php echo esc_html( $fact ); ?></div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $recipes ) : ?>
	<section class="container section">
		<h2><?php echo esc_html( get_the_title() ); ?> recepty</h2>
		<div class="card-grid card-grid-4">
			<?php foreach ( $recipes as $r ) : ?>
				<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $r->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $glossary_ids ) : ?>
	<section class="container section">
		<h2>Z kuchařského slovníčku</h2>
		<div class="flex-wrap-gap">
			<?php foreach ( (array) $glossary_ids as $gid ) : if ( 'publish' !== get_post_status( $gid ) ) { continue; } ?>
				<a class="chip" href="<?php echo esc_url( get_permalink( $gid ) ); ?>"><?php echo esc_html( get_the_title( $gid ) ); ?></a>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $related_ids ) : ?>
	<section class="container section">
		<div class="discover-panel" style="flex-direction:column;align-items:stretch;">
			<h3>Chutná vám <?php echo esc_html( get_the_title() ); ?>? Objevte i další kuchyně.</h3>
			<div class="card-grid card-grid-3" style="margin-top:20px;">
				<?php foreach ( (array) $related_ids as $rid ) : if ( 'publish' !== get_post_status( $rid ) ) { continue; } ?>
					<?php get_template_part( 'template-parts/country-card', null, array( 'post_id' => $rid ) ); ?>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php endif; ?>

<?php endwhile; ?>

<?php get_footer(); ?>
