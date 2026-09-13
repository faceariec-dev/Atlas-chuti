<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a continent's photo (item 20 of this phase's brief), set via the
 * atlas_continent term edit screen (see the plugin's class-continent-image.php).
 * Falls back to the matching illustrative continent-* SVG (inc/fallback-images.php),
 * then to the original generic placeholder — a continent tile always looks
 * intentional, photo or not.
 */
function atlas_chuti_continent_image_html( $term_id ) {
	$attachment_id = (int) get_term_meta( $term_id, 'thumbnail_id', true );
	if ( $attachment_id ) {
		return wp_get_attachment_image( $attachment_id, 'atlas-card-tall', false, array( 'loading' => 'lazy' ) );
	}
	$term     = get_term( $term_id, 'atlas_continent' );
	$slug     = ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
	$name     = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	$fallback = atlas_chuti_fallback_image_html( 'continent', $slug, $name );
	return $fallback ? $fallback : '<div class="placeholder-media"></div>';
}
