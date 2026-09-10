<?php
/**
 * Expects $args['post_id'].
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id  = $args['post_id'];
$category = get_the_terms( $post_id, 'atlas_glossary_category' );
$def      = get_post_meta( $post_id, 'atlas_short_definition', true );
?>
<a class="glossary-card" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
	<div class="glossary-card-head">
		<h3><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		<?php if ( $category && ! is_wp_error( $category ) ) : ?>
			<span class="glossary-cat-badge"><?php echo esc_html( $category[0]->name ); ?></span>
		<?php endif; ?>
	</div>
	<p><?php echo esc_html( wp_trim_words( $def, 20 ) ); ?></p>
</a>
