<?php
/**
 * Expects $args['post_id'].
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id     = $args['post_id'];
$description = get_post_meta( $post_id, 'atlas_intro', true );
$count       = atlas_chuti_count_recipes_for_country( $post_id );
?>
<a class="card" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
	<div class="card-media is-tall"><?php echo atlas_chuti_media( $post_id, 'atlas-card-tall' ); ?></div>
	<div class="card-body">
		<div class="card-eyebrow" style="gap:9px;">
			<span style="font-size:20px;"><?php echo esc_html( atlas_chuti_flag( $post_id ) ); ?></span>
			<h3 class="card-title" style="margin:0;"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
		</div>
		<p class="card-desc"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $description ), 18 ) ); ?></p>
		<span class="card-count"><?php echo esc_html( atlas_chuti_czech_plural( $count, 'recept', 'recepty', 'receptů' ) ); ?></span>
	</div>
</a>
