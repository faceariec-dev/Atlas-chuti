<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$continents      = get_terms( array( 'taxonomy' => 'atlas_continent', 'hide_empty' => false ) );
$today_countries = atlas_chuti_home_today_countries( 3 );
$new_recipes     = atlas_chuti_home_new_recipes( 3 );
$cuisines        = atlas_chuti_home_favorite_cuisines( 6 );
$featured_recipe = atlas_chuti_home_featured_recipe();
$glossary_terms  = atlas_chuti_home_glossary_preview( 3 );
$total_countries = atlas_chuti_total_countries();
$hero_image      = atlas_chuti_hero_image_html();
?>

<section class="hero">
	<div class="container hero-grid">
		<div class="hero-copy">
			<span class="kicker"><?php esc_html_e( 'Kulinární atlas světa', 'atlas-chuti' ); ?></span>
			<h1><?php esc_html_e( 'Ochutnejte svět', 'atlas-chuti' ); ?></h1>
			<p class="lede"><?php esc_html_e( 'Objevujte tradiční jídla, recepty a kuchyně ze všech koutů planety.', 'atlas-chuti' ); ?></p>
			<form class="search-box" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" role="search">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
				<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledejte zemi, recept nebo surovinu…', 'atlas-chuti' ); ?>">
				<button type="submit" class="btn btn-accent"><?php esc_html_e( 'Hledat', 'atlas-chuti' ); ?></button>
			</form>
		</div>
		<div class="hero-media">
			<?php if ( $hero_image ) : ?>
				<?php echo $hero_image; ?>
			<?php else : ?>
				<div class="placeholder-media"><span><?php esc_html_e( 'Atlas chutí', 'atlas-chuti' ); ?></span></div>
			<?php endif; ?>
		</div>
	</div>
</section>

<?php if ( $continents && ! is_wp_error( $continents ) ) : ?>
<section class="section bg-cream">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker"><?php esc_html_e( 'Objevujte svět', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Kam se vydáme?', 'atlas-chuti' ); ?></h2>
			</div>
		</div>
		<div class="continent-grid">
			<?php foreach ( $continents as $continent ) : ?>
				<a class="continent-tile" href="<?php echo esc_url( get_term_link( $continent ) ); ?>">
					<?php echo atlas_chuti_continent_image_html( $continent->term_id ); ?>
					<span><?php echo esc_html( $continent->name ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $featured_recipe ) : $fr_country = atlas_chuti_get_recipe_primary_country( $featured_recipe->ID ); ?>
<section class="section bg-saffron-tint">
	<div class="container">
		<a class="featured-banner" href="<?php echo esc_url( get_permalink( $featured_recipe ) ); ?>">
			<div class="featured-banner-media"><?php echo atlas_chuti_media( $featured_recipe->ID, 'atlas-hero' ); ?></div>
			<div class="featured-banner-body">
				<span class="kicker is-saffron"><?php esc_html_e( 'Dnes ochutnejte', 'atlas-chuti' ); ?></span>
				<?php if ( $fr_country ) : ?>
					<div class="card-eyebrow"><span style="font-size:17px;"><?php echo esc_html( atlas_chuti_flag( $fr_country->ID ) ); ?></span><span><?php echo esc_html( get_the_title( $fr_country ) ); ?></span></div>
				<?php endif; ?>
				<h3><?php echo esc_html( get_the_title( $featured_recipe ) ); ?></h3>
				<p><?php echo esc_html( get_post_meta( $featured_recipe->ID, 'atlas_excerpt', true ) ); ?></p>
				<span class="link-arrow"><?php esc_html_e( 'Uvařit recept →', 'atlas-chuti' ); ?></span>
			</div>
		</a>
	</div>
</section>
<?php endif; ?>

<?php if ( $new_recipes ) : ?>
<section class="section">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker"><?php esc_html_e( 'Nové recepty', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Čerstvě z Atlasu', 'atlas-chuti' ); ?></h2>
			</div>
			<a class="more-link link-arrow" href="<?php echo esc_url( get_post_type_archive_link( 'atlas_recipe' ) ); ?>"><?php esc_html_e( 'Všechny recepty →', 'atlas-chuti' ); ?></a>
		</div>
		<div class="card-grid card-grid-3">
			<?php foreach ( $new_recipes as $recipe ) : ?>
				<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $recipe->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $today_countries || $cuisines ) :
	$kitchen_countries = $cuisines ? $cuisines : $today_countries;
	?>
<section class="section bg-sage-tint">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker is-sage"><?php esc_html_e( 'Objevujte kuchyně', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Kuchyně, které stojí za objevování', 'atlas-chuti' ); ?></h2>
			</div>
			<a class="more-link link-arrow" href="<?php echo esc_url( atlas_chuti_system_url( 'countries' ) ); ?>"><?php esc_html_e( 'Všechny země →', 'atlas-chuti' ); ?></a>
		</div>
		<div class="card-grid card-grid-3">
			<?php foreach ( $kitchen_countries as $country ) : ?>
				<?php get_template_part( 'template-parts/country-card', null, array( 'post_id' => $country->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $glossary_terms ) : ?>
<section class="section bg-blue-tint">
	<div class="container">
		<div class="section-head">
			<div>
				<span class="kicker is-blue"><?php esc_html_e( 'Kuchařský slovníček', 'atlas-chuti' ); ?></span>
				<h2><?php esc_html_e( 'Rozumějte kuchyni', 'atlas-chuti' ); ?></h2>
			</div>
			<a class="more-link link-arrow" href="<?php echo esc_url( get_post_type_archive_link( 'atlas_glossary' ) ); ?>"><?php esc_html_e( 'Celý slovníček →', 'atlas-chuti' ); ?></a>
		</div>
		<div class="card-grid card-grid-3">
			<?php foreach ( $glossary_terms as $term ) : ?>
				<?php get_template_part( 'template-parts/glossary-card', null, array( 'post_id' => $term->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<section class="section" data-passport-widget>
	<div class="container">
		<div class="dark-panel">
			<h2><?php esc_html_e( 'Kolik světa už jste ochutnali?', 'atlas-chuti' ); ?></h2>
			<p><?php esc_html_e( 'Označujte recepty, které jste uvařili, a sledujte, jak vaše kulinářská mapa světa roste.', 'atlas-chuti' ); ?></p>
			<div style="margin:var(--space-5) 0 var(--space-3);">
				<span class="passport-count" data-passport-count>
					<?php
					/* translators: %d: number of countries published on the site */
					echo esc_html( sprintf( __( '0 / %d zemí', 'atlas-chuti' ), $total_countries ) );
					?>
				</span>
			</div>
			<div class="passport-flags" data-passport-flags style="margin-bottom:var(--space-8);"></div>
			<a class="btn btn-accent" href="<?php echo esc_url( atlas_chuti_system_url( 'passport' ) ); ?>"><?php esc_html_e( 'Otevřít můj kulinářský pas', 'atlas-chuti' ); ?></a>
		</div>
	</div>
</section>

<section class="section" style="padding-top:0;">
	<div class="container">
		<div class="editorial-cta">
			<div>
				<span class="kicker"><?php esc_html_e( 'Nemůžete se rozhodnout?', 'atlas-chuti' ); ?></span>
				<h3><?php esc_html_e( 'Nechte Atlas vybrat za vás.', 'atlas-chuti' ); ?></h3>
			</div>
			<a class="btn btn-dark" href="<?php echo esc_url( home_url( '/?atlas_random_country=1' ) ); ?>"><?php esc_html_e( 'Vybrat náhodnou zemi →', 'atlas-chuti' ); ?></a>
		</div>
	</div>
</section>

<?php get_footer(); ?>
