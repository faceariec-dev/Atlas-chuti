<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$categories   = get_terms( array( 'taxonomy' => 'atlas_glossary_category', 'hide_empty' => true ) );
$active_cat   = isset( $_GET['kategorie'] ) ? sanitize_title( wp_unslash( $_GET['kategorie'] ) ) : '';
$active_letter = isset( $_GET['pismeno'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['pismeno'] ) ) ) : '';

$args = array(
	'post_type'      => 'atlas_glossary',
	'posts_per_page' => -1,
	'orderby'        => 'title',
	'order'          => 'ASC',
);
if ( $active_cat ) {
	$args['tax_query'] = array( array( 'taxonomy' => 'atlas_glossary_category', 'field' => 'slug', 'terms' => $active_cat ) );
}

$query = new WP_Query( $args );
$terms = $query->posts;

if ( $active_letter ) {
	$terms = array_filter(
		$terms,
		function ( $p ) use ( $active_letter ) {
			return 0 === stripos( $p->post_title, $active_letter );
		}
	);
}

?>

<section class="container-narrow text-center bg-blue-tint" style="padding:var(--space-14) var(--gutter) var(--space-8);">
	<span class="kicker is-blue"><?php esc_html_e( 'Rozumějte kuchyni', 'atlas-chuti' ); ?></span>
	<h1><?php esc_html_e( 'Kuchařský slovníček', 'atlas-chuti' ); ?></h1>
	<p class="lede" style="margin-inline:auto;"><?php esc_html_e( 'Techniky, suroviny a pojmy světové gastronomie na jednom místě.', 'atlas-chuti' ); ?></p>
	<form class="search-box" style="max-width:520px;margin:var(--space-5) auto 0;" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
		<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledat pojem…', 'atlas-chuti' ); ?>">
	</form>
</section>

<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
<section class="container glossary-category-bar" style="padding:var(--space-8) var(--gutter) var(--space-5);">
	<a class="chip <?php echo ! $active_cat ? 'chip-static' : ''; ?>" href="<?php echo atlas_chuti_glossary_filter_url( array( 'kategorie' => false ) ); ?>"><?php esc_html_e( 'Vše', 'atlas-chuti' ); ?></a>
	<?php foreach ( $categories as $cat ) : ?>
		<a class="chip <?php echo $active_cat === $cat->slug ? 'chip-static' : ''; ?>" href="<?php echo atlas_chuti_glossary_filter_url( array( 'kategorie' => $cat->slug ) ); ?>"><?php echo esc_html( $cat->name ); ?></a>
	<?php endforeach; ?>
</section>
<?php endif; ?>

<section class="container glossary-alpha-bar" style="padding-bottom:12px;">
	<a href="<?php echo atlas_chuti_glossary_filter_url( array( 'pismeno' => false ) ); ?>" style="<?php echo ! $active_letter ? 'font-weight:700;color:var(--accent);' : ''; ?>"><?php esc_html_e( 'Vše', 'atlas-chuti' ); ?></a>
	<?php foreach ( str_split( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ) as $letter ) : ?>
		<a href="<?php echo atlas_chuti_glossary_filter_url( array( 'pismeno' => $letter ) ); ?>" style="<?php echo $active_letter === $letter ? 'background:var(--bg-muted);color:var(--accent);' : ''; ?>"><?php echo esc_html( $letter ); ?></a>
	<?php endforeach; ?>
</section>

<section class="container section" style="padding-top:24px;">
	<?php if ( $terms ) : ?>
		<div class="card-grid card-grid-3">
			<?php foreach ( $terms as $term_post ) : ?>
				<?php get_template_part( 'template-parts/glossary-card', null, array( 'post_id' => $term_post->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'Pro zvolený filtr jsme nenašli žádné pojmy.', 'atlas-chuti' ); ?></p>
	<?php endif; ?>
</section>

<?php get_footer(); ?>
