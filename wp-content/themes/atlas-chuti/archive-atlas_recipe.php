<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$options = atlas_chuti_get_recipe_filter_options();
$active  = atlas_chuti_active_filters();

?>

<section class="container-narrow" style="padding:64px var(--gutter) 20px;">
	<h1>Recepty ze světa</h1>
	<p style="font-size:16px;color:var(--text-body);">Procházejte recepty podle země, typu jídla, obtížnosti nebo času přípravy.</p>
	<form class="search-box" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
		<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="Hledat recept…">
	</form>
</section>

<section class="container section" style="padding-top:24px;">
	<div class="archive-layout">
		<aside class="filter-panel">
			<form data-filter-form action="<?php echo esc_url( get_post_type_archive_link( 'atlas_recipe' ) ); ?>" method="get">
				<div class="filter-group">
					<h4>Země</h4>
					<?php atlas_chuti_radio_group( 'zeme', $options['zeme'], $active['zeme'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4>Světadíl</h4>
					<?php atlas_chuti_radio_group( 'svetadil', $options['svetadil'], $active['svetadil'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4>Typ jídla</h4>
					<?php atlas_chuti_radio_group( 'typ', $options['typ'], $active['typ'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4>Obtížnost</h4>
					<?php atlas_chuti_radio_group( 'obtiznost', $options['obtiznost'], $active['obtiznost'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4>Vhodné pro</h4>
					<?php atlas_chuti_radio_group( 'dieta', $options['dieta'], $active['dieta'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4>Čas přípravy</h4>
					<?php
					$cas = $active['cas'] ?? '';
					foreach ( array( '' => 'Vše', 'do-30' => 'Do 30 min', 'do-60' => 'Do 60 min', 'do-90' => 'Do 90 min' ) as $val => $label ) {
						printf( '<label><input type="radio" name="cas" value="%1$s" %2$s> %3$s</label>', esc_attr( $val ), checked( $val, $cas, false ), esc_html( $label ) );
					}
					?>
				</div>
				<div class="filter-actions">
					<button type="submit" class="btn btn-accent">Použít filtry</button>
					<?php if ( $active ) : ?>
						<a class="btn btn-outline" href="<?php echo esc_url( get_post_type_archive_link( 'atlas_recipe' ) ); ?>">Zrušit</a>
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
				<p class="empty-state">Pro zvolené filtry jsme nenašli žádné recepty. Zkuste je zjednodušit.</p>
			<?php endif; ?>
		</div>
	</div>
</section>

<?php
wp_reset_postdata();
get_footer();
