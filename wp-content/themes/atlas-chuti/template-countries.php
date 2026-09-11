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
$all_countries   = get_posts( array( 'post_type' => 'atlas_country', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
?>

<section class="container-medium text-center" style="padding:64px var(--gutter) 32px;">
	<h1><?php esc_html_e( 'Země světa', 'atlas-chuti' ); ?></h1>
	<?php the_content(); ?>
	<form class="search-box" style="max-width:520px;margin:24px auto 0;" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
		<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledat zemi…', 'atlas-chuti' ); ?>">
	</form>
</section>

<?php if ( $continents && ! is_wp_error( $continents ) ) : ?>
<section class="container section">
	<h2><?php esc_html_e( 'Šest světadílů', 'atlas-chuti' ); ?></h2>
	<div class="card-grid card-grid-6" style="margin-top:20px;">
		<?php foreach ( $continents as $continent ) :
			$count = ( new WP_Query( array( 'post_type' => 'atlas_country', 'tax_query' => array( array( 'taxonomy' => 'atlas_continent', 'terms' => $continent->term_id ) ), 'fields' => 'ids', 'posts_per_page' => -1 ) ) )->found_posts;
			?>
			<a class="continent-tile" href="<?php echo esc_url( get_term_link( $continent ) ); ?>" style="<?php echo 0 === $count ? 'opacity:0.5;' : ''; ?>">
				<?php echo atlas_chuti_continent_image_html( $continent->term_id ); ?>
				<span><?php echo esc_html( $continent->name ); ?><br><small style="font-weight:400;opacity:0.85;"><?php echo esc_html( atlas_chuti_czech_plural( $count, __( 'země', 'atlas-chuti' ), __( 'země', 'atlas-chuti' ), __( 'zemí', 'atlas-chuti' ) ) ); ?></small></span>
			</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<section class="container section">
	<div class="section-head">
		<h2><?php esc_html_e( 'Všechny dostupné země', 'atlas-chuti' ); ?></h2>
		<?php if ( ! $show_all && count( $all_countries ) > 8 ) : ?>
			<a class="more-link" href="<?php echo esc_url( add_query_arg( 'vse', '1' ) ); ?>"><?php echo esc_html( sprintf( __( 'Zobrazit všechny (%d) →', 'atlas-chuti' ), count( $all_countries ) ) ); ?></a>
		<?php endif; ?>
	</div>
	<?php if ( $all_countries ) : ?>
		<div class="card-grid card-grid-4">
			<?php foreach ( array_slice( $all_countries, 0, $show_all ? count( $all_countries ) : 8 ) as $country ) : ?>
				<?php get_template_part( 'template-parts/country-card', null, array( 'post_id' => $country->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'Zatím zde není publikovaná žádná země.', 'atlas-chuti' ); ?></p>
	<?php endif; ?>
</section>

<?php get_footer(); ?>
