<?php
/**
 * Template Name: Země (landing)
 * Description: Přiřaďte této šabloně stránku se slugem „zeme“ (item 15 of the brief — the design had a continent detail page but no /zeme/ landing).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$continents      = get_terms( array( 'taxonomy' => 'atlas_continent', 'hide_empty' => false ) );
$show_all        = isset( $_GET['vse'] );
$active_continent = isset( $_GET['svetadil'] ) ? sanitize_title( wp_unslash( $_GET['svetadil'] ) ) : '';
$all_countries   = get_posts( array( 'post_type' => 'atlas_country', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );

$countries_to_show = $all_countries;
if ( $active_continent && $continents && ! is_wp_error( $continents ) ) {
	$countries_to_show = array_values(
		array_filter(
			$all_countries,
			function ( $country ) use ( $active_continent ) {
				$terms = get_the_terms( $country->ID, 'atlas_continent' );
				if ( ! $terms || is_wp_error( $terms ) ) {
					return false;
				}
				return in_array( $active_continent, wp_list_pluck( $terms, 'slug' ), true );
			}
		)
	);
}
?>

<section class="container-medium text-center" style="padding:var(--space-14) var(--gutter) var(--space-8);">
	<h1><?php esc_html_e( 'Země světa', 'atlas-chuti' ); ?></h1>
	<?php the_content(); ?>
	<form class="search-box" style="max-width:520px;margin:var(--space-6) auto 0;" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
		<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledat zemi…', 'atlas-chuti' ); ?>">
	</form>
</section>

<?php if ( $continents && ! is_wp_error( $continents ) ) : ?>
<section class="section bg-cream">
	<div class="container">
		<span class="kicker"><?php esc_html_e( 'Objevujte svět', 'atlas-chuti' ); ?></span>
		<h2><?php esc_html_e( 'Šest světadílů', 'atlas-chuti' ); ?></h2>
		<div class="continent-grid" style="margin-top:var(--space-6);">
			<?php foreach ( $continents as $continent ) :
				$count = ( new WP_Query( array( 'post_type' => 'atlas_country', 'tax_query' => array( array( 'taxonomy' => 'atlas_continent', 'terms' => $continent->term_id ) ), 'fields' => 'ids', 'posts_per_page' => -1 ) ) )->found_posts;
				?>
				<a class="continent-tile" href="<?php echo esc_url( get_term_link( $continent ) ); ?>" style="<?php echo 0 === $count ? 'opacity:0.5;' : ''; ?>">
					<?php echo atlas_chuti_continent_image_html( $continent->term_id ); ?>
					<span><?php echo esc_html( $continent->name ); ?><br><small style="font-weight:400;opacity:0.85;"><?php echo esc_html( atlas_chuti_czech_plural( $count, __( 'země', 'atlas-chuti' ), __( 'země', 'atlas-chuti' ), __( 'zemí', 'atlas-chuti' ) ) ); ?></small></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<section class="section">
	<div class="container">
		<div class="section-head">
			<h2><?php esc_html_e( 'Všechny dostupné země', 'atlas-chuti' ); ?></h2>
			<?php if ( ! $show_all && count( $countries_to_show ) > 8 ) : ?>
				<a class="more-link link-arrow" href="<?php echo esc_url( add_query_arg( 'vse', '1' ) ); ?>"><?php echo esc_html( sprintf( __( 'Zobrazit všechny (%d) →', 'atlas-chuti' ), count( $countries_to_show ) ) ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( $continents && ! is_wp_error( $continents ) ) : ?>
			<nav class="tab-bar" style="margin-bottom:var(--space-8);" aria-label="<?php esc_attr_e( 'Filtrovat podle světadílu', 'atlas-chuti' ); ?>">
				<a href="<?php echo esc_url( remove_query_arg( 'svetadil' ) ); ?>" class="<?php echo ! $active_continent ? 'is-active' : ''; ?>"><?php esc_html_e( 'Vše', 'atlas-chuti' ); ?></a>
				<?php foreach ( $continents as $continent ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'svetadil', $continent->slug ) ); ?>" class="<?php echo $active_continent === $continent->slug ? 'is-active' : ''; ?>"><?php echo esc_html( $continent->name ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php if ( $countries_to_show ) : ?>
			<div class="card-grid card-grid-4">
				<?php foreach ( array_slice( $countries_to_show, 0, $show_all ? count( $countries_to_show ) : 8 ) as $country ) : ?>
					<?php get_template_part( 'template-parts/country-card', null, array( 'post_id' => $country->ID ) ); ?>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="empty-state"><?php esc_html_e( 'Pro zvolený světadíl zatím nemáme publikovanou žádnou zemi.', 'atlas-chuti' ); ?></p>
		<?php endif; ?>
	</div>
</section>

<?php get_footer(); ?>
