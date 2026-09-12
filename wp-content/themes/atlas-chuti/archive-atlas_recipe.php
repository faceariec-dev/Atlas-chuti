<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$options = atlas_chuti_get_recipe_filter_options();
$active  = atlas_chuti_active_filters();

?>

<section class="container-narrow" style="padding:var(--space-14) var(--gutter) var(--space-5);">
	<span class="kicker"><?php esc_html_e( 'Recepty', 'atlas-chuti' ); ?></span>
	<h1><?php esc_html_e( 'Recepty ze světa', 'atlas-chuti' ); ?></h1>
	<p class="lede" style="max-width:none;"><?php esc_html_e( 'Procházejte recepty podle země, typu jídla, obtížnosti nebo času přípravy.', 'atlas-chuti' ); ?></p>
	<form class="search-box" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledat recept…', 'atlas-chuti' ); ?>">
	</form>
</section>

<section class="container section" style="padding-top:var(--space-6);">
	<div class="archive-layout">
		<aside class="filter-panel">
			<form data-filter-form action="<?php echo esc_url( get_post_type_archive_link( 'atlas_recipe' ) ); ?>" method="get">
				<div class="filter-group">
					<h4><?php esc_html_e( 'Země', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'zeme', $options['zeme'], $active['zeme'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Světadíl', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'svetadil', $options['svetadil'], $active['svetadil'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Typ jídla', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'typ', $options['typ'], $active['typ'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Obtížnost', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'obtiznost', $options['obtiznost'], $active['obtiznost'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Vhodné pro', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'dieta', $options['dieta'], $active['dieta'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Čas přípravy', 'atlas-chuti' ); ?></h4>
					<?php
					$cas          = $active['cas'] ?? '';
					$time_buckets = array(
						''       => __( 'Vše', 'atlas-chuti' ),
						'do-30'  => __( 'Do 30 min', 'atlas-chuti' ),
						'do-60'  => __( 'Do 60 min', 'atlas-chuti' ),
						'do-90'  => __( 'Do 90 min', 'atlas-chuti' ),
					);
					foreach ( $time_buckets as $val => $label ) {
						printf( '<label><input type="radio" name="cas" value="%1$s" %2$s> %3$s</label>', esc_attr( $val ), checked( $val, $cas, false ), esc_html( $label ) );
					}
					?>
				</div>
				<div class="filter-actions">
					<button type="submit" class="btn btn-accent"><?php esc_html_e( 'Použít filtry', 'atlas-chuti' ); ?></button>
					<?php if ( $active ) : ?>
						<a class="btn btn-outline" href="<?php echo esc_url( get_post_type_archive_link( 'atlas_recipe' ) ); ?>"><?php esc_html_e( 'Zrušit', 'atlas-chuti' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</aside>

		<div>
			<?php if ( $active ) : ?>
				<div class="active-filters">
					<?php foreach ( $active as $key => $value ) : ?>
						<a href="<?php echo esc_url( remove_query_arg( $key ) ); ?>"><?php echo esc_html( $value ); ?> ✕</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( have_posts() ) : ?>
				<div class="card-grid card-grid-3">
					<?php while ( have_posts() ) : the_post(); ?>
						<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => get_the_ID() ) ); ?>
					<?php endwhile; ?>
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
				<p class="empty-state"><?php esc_html_e( 'Pro zvolené filtry jsme nenašli žádné recepty. Zkuste je zjednodušit.', 'atlas-chuti' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>

<?php
wp_reset_postdata();
get_footer();
