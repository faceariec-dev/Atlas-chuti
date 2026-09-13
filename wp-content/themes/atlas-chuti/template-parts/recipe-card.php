<?php
/**
 * Expects $args['post_id'].
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = $args['post_id'];
$country = atlas_chuti_get_recipe_primary_country( $post_id );
?>
<a class="recipe-card" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
	<div class="card-media"><?php echo atlas_chuti_media( $post_id, 'atlas-card', '', 'recipe' ); ?></div>
	<div class="card-body">
		<?php if ( $country ) : ?>
			<div class="card-eyebrow">
				<span><?php echo esc_html( atlas_chuti_flag( $country->ID ) ); ?></span>
				<span><?php echo esc_html( get_the_title( $country ) ); ?></span>
			</div>
		<?php endif; ?>
		<h3 class="card-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		<div class="card-meta"><?php echo esc_html( atlas_chuti_recipe_meta_line( $post_id ) ); ?></div>
	</div>
</a>
