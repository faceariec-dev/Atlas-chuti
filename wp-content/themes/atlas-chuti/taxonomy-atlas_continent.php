<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$continent     = get_queried_object();
$attachment_id = (int) get_term_meta( $continent->term_id, 'thumbnail_id', true );
$countries     = get_posts(
	array(
		'post_type'      => 'atlas_country',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'tax_query'      => array( array( 'taxonomy' => 'atlas_continent', 'field' => 'term_id', 'terms' => $continent->term_id ) ),
	)
);
?>

<?php if ( $attachment_id ) : ?>
	<section class="page-hero-photo">
		<?php echo wp_get_attachment_image( $attachment_id, 'atlas-hero', false, array( 'loading' => 'eager' ) ); ?>
		<div class="page-hero-photo-inner">
			<span class="eyebrow"><?php esc_html_e( 'Světadíl', 'atlas-chuti' ); ?></span>
			<h1><?php echo esc_html( $continent->name ); ?></h1>
			<?php if ( $continent->description ) : ?><p class="lede"><?php echo esc_html( $continent->description ); ?></p><?php endif; ?>
		</div>
	</section>
<?php else : ?>
	<section class="container bg-cream" style="padding:var(--space-14) var(--gutter) var(--space-10);">
		<span class="kicker"><?php esc_html_e( 'Světadíl', 'atlas-chuti' ); ?></span>
		<h1 style="margin-top:8px;"><?php echo esc_html( $continent->name ); ?></h1>
		<?php if ( $continent->description ) : ?>
			<p style="font-size:17px;color:var(--color-text);max-width:680px;line-height:1.6;"><?php echo esc_html( $continent->description ); ?></p>
		<?php endif; ?>
	</section>
<?php endif; ?>

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
