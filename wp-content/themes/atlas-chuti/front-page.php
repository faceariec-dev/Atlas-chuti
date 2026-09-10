<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$continents      = get_terms( array( 'taxonomy' => 'atlas_continent', 'hide_empty' => false ) );
$today_countries = atlas_chuti_home_today_countries( 3 );
$new_recipes     = atlas_chuti_home_new_recipes( 4 );
$cuisines        = atlas_chuti_home_favorite_cuisines( 6 );
$featured_recipe = atlas_chuti_home_featured_recipe();
$glossary_terms  = atlas_chuti_home_glossary_preview( 3 );
$total_countries = atlas_chuti_total_countries();
?>

<section class="hero">
	<h1>Ochutnejte svět</h1>
	<p>Objevujte tradiční jídla, recepty a kuchyně ze všech koutů planety.</p>
	<form class="search-box" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search">
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="Hledat recept, zemi, jídlo nebo surovinu…">
		<button type="submit" class="btn btn-accent">Hledat</button>
	</form>
</section>

<?php if ( $continents && ! is_wp_error( $continents ) ) : ?>
<section class="section container">
	<div class="card-grid card-grid-6">
		<?php foreach ( $continents as $continent ) : ?>
			<a class="continent-tile" href="<?php echo esc_url( get_term_link( $continent ) ); ?>">
				<div class="placeholder-media"></div>
				<span><?php echo esc_html( $continent->name ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<?php if ( $today_countries ) : ?>
<section class="section container">
	<div class="section-head">
		<h2>Dnes ochutnejte</h2>
		<a class="more-link" href="<?php echo esc_url( home_url( '/zeme/' ) ); ?>">Všechny země →</a>
	</div>
	<div class="card-grid card-grid-3">
		<?php foreach ( $today_countries as $country ) : ?>
			<?php get_template_part( 'template-parts/country-card', null, array( 'post_id' => $country->ID ) ); ?>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<?php if ( $new_recipes ) : ?>
<section class="section container">
	<div class="section-head">
		<h2>Nové recepty ze světa</h2>
		<a class="more-link" href="<?php echo esc_url( get_post_type_archive_link( 'atlas_recipe' ) ); ?>">Všechny recepty →</a>
	</div>
	<div class="card-grid card-grid-4">
		<?php foreach ( $new_recipes as $recipe ) : ?>
			<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $recipe->ID ) ); ?>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<?php if ( $cuisines ) : ?>
<section class="section container">
	<h2>Oblíbené kuchyně</h2>
	<div class="flex-wrap-gap" style="margin-top:24px;">
		<?php foreach ( $cuisines as $country ) : ?>
			<a class="chip" href="<?php echo esc_url( get_permalink( $country ) ); ?>">
				<span><?php echo esc_html( atlas_chuti_flag( $country->ID ) ); ?></span>
				<?php echo esc_html( get_the_title( $country ) ); ?>
			</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<?php if ( $featured_recipe ) : $fr_country = atlas_chuti_get_recipe_primary_country( $featured_recipe->ID ); ?>
<section class="section container">
	<h2>Co dnes uvařit?</h2>
	<a class="featured-banner" href="<?php echo esc_url( get_permalink( $featured_recipe ) ); ?>" style="margin-top:24px;">
		<div class="featured-banner-media"><?php echo atlas_chuti_media( $featured_recipe->ID, 'atlas-hero' ); ?></div>
		<div class="featured-banner-body">
			<?php if ( $fr_country ) : ?>
				<div class="card-eyebrow"><span style="font-size:17px;"><?php echo esc_html( atlas_chuti_flag( $fr_country->ID ) ); ?></span><span><?php echo esc_html( get_the_title( $fr_country ) ); ?></span></div>
			<?php endif; ?>
			<h3><?php echo esc_html( get_the_title( $featured_recipe ) ); ?></h3>
			<p><?php echo esc_html( get_post_meta( $featured_recipe->ID, 'atlas_excerpt', true ) ); ?></p>
			<div class="card-meta"><?php echo esc_html( atlas_chuti_recipe_meta_line( $featured_recipe->ID ) ); ?></div>
			<span class="cta">Zobrazit recept →</span>
		</div>
	</a>
</section>
<?php endif; ?>

<?php if ( $glossary_terms ) : ?>
<section class="section container">
	<div class="section-head">
		<h2>Kuchařský slovníček</h2>
		<a class="more-link" href="<?php echo esc_url( get_post_type_archive_link( 'atlas_glossary' ) ); ?>">Celý slovníček →</a>
	</div>
	<div class="card-grid card-grid-3">
		<?php foreach ( $glossary_terms as $term ) : ?>
			<?php get_template_part( 'template-parts/glossary-card', null, array( 'post_id' => $term->ID ) ); ?>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<section class="section container" data-passport-widget>
	<div class="dark-panel">
		<h2>Kulinářský pas</h2>
		<p>Kolik zemí už jste ochutnali? Označujte recepty, které jste uvařili, a sledujte, jak vaše kulinářská mapa světa roste.</p>
		<div style="margin-bottom:18px;">
			<span class="passport-count" data-passport-count>0 / <?php echo esc_html( $total_countries ); ?> zemí</span>
		</div>
		<div class="passport-flags" data-passport-flags style="margin-bottom:26px;"></div>
		<a class="btn btn-accent" href="<?php echo esc_url( home_url( '/kulinarsky-pas/' ) ); ?>">Otevřít můj kulinářský pas</a>
	</div>
</section>

<section class="section container">
	<div class="discover-panel">
		<div>
			<h3>Kam dnes za chutí?</h3>
			<p>Necháte náhodu vybrat vaši další kulinářskou destinaci.</p>
		</div>
		<a class="btn btn-dark" href="<?php echo esc_url( home_url( '/?atlas_random_country=1' ) ); ?>">Vybrat náhodnou zemi</a>
	</div>
</section>

<?php get_footer(); ?>
