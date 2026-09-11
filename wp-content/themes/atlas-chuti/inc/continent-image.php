<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a continent's photo (item 20 of this phase's brief), set via the
 * atlas_continent term edit screen (see the plugin's class-continent-image.php).
 * Falls back to the existing placeholder — a continent tile always looks
 * intentional, photo or not.
 */
function atlas_chuti_continent_image_html( $term_id ) {
	$attachment_id = (int) get_term_meta( $term_id, 'thumbnail_id', true );
	if ( ! $attachment_id ) {
		return '<div class="placeholder-media"></div>';
	}
	return wp_get_attachment_image( $attachment_id, 'atlas-card-tall', false, array( 'loading' => 'lazy' ) );
}
