<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$continent = get_queried_object();
$countries = get_posts(
	array(
		'post_type'      => 'atlas_country',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'tax_query'      => array( array( 'taxonomy' => 'atlas_continent', 'field' => 'term_id', 'terms' => $continent->term_id ) ),
	)
);
?>

<section class="container" style="padding:64px var(--gutter) 40px;">
	<span style="font-size:13px;font-weight:600;color:var(--accent);text-transform:uppercase;letter-spacing:0.06em;"><?php esc_html_e( 'Světadíl', 'atlas-chuti' ); ?></span>
	<h1 style="margin-top:8px;"><?php echo esc_html( $continent->name ); ?></h1>
	<?php if ( $continent->description ) : ?>
		<p style="font-size:17px;color:var(--text-body);max-width:680px;line-height:1.6;"><?php echo esc_html( $continent->description ); ?></p>
	<?php endif; ?>
</section>

<section class="container section">
	<?php if ( $countries ) : ?>
		<div class="card-grid card-grid-4">
			<?php foreach ( $countries as $country ) : ?>
				<?php get_template_part( 'template-parts/country-card', null, array( 'post_id' => $country->ID ) ); ?>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'Pro tento světadíl zatím nemáme publikovaný obsah.', 'atlas-chuti' ); ?></p>
	<?php endif; ?>
</section>

<?php get_footer(); ?>
