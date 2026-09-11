<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Homepage hero image (item 19 of this phase's brief): settable through WordPress,
 * never hardcoded. When nothing is set the hero still looks intentional — it just
 * falls back to the warm background gradient already in main.css, no broken image,
 * no empty box.
 */
function atlas_chuti_customize_register( $wp_customize ) {
	$wp_customize->add_section(
		'atlas_chuti_homepage',
		array(
			'title'    => __( 'Atlas chutí – Homepage', 'atlas-chuti' ),
			'priority' => 30,
		)
	);

	$wp_customize->add_setting(
		'atlas_hero_image_id',
		array(
			'type'              => 'theme_mod',
			'sanitize_callback' => 'absint',
		)
	);

	$wp_customize->add_control(
		new WP_Customize_Media_Control(
			$wp_customize,
			'atlas_hero_image_control',
			array(
				'label'       => __( 'Hero fotografie (homepage)', 'atlas-chuti' ),
				'description' => __( 'Volitelné. Pokud nic nevyberete, homepage použije jednobarevné pozadí podle designu.', 'atlas-chuti' ),
				'section'     => 'atlas_chuti_homepage',
				'settings'    => 'atlas_hero_image_id',
				'mime_type'   => 'image',
			)
		)
	);
}
add_action( 'customize_register', 'atlas_chuti_customize_register' );

/**
 * Returns the hero <img> markup, or an empty string when no image is set — callers
 * (front-page.php) must handle the empty case gracefully, never assume a photo exists.
 */
function atlas_chuti_hero_image_html() {
	$attachment_id = get_theme_mod( 'atlas_hero_image_id' );
	if ( ! $attachment_id ) {
		return '';
	}
	return wp_get_attachment_image( $attachment_id, 'atlas-hero', false, array( 'class' => 'hero-photo', 'loading' => 'eager', 'fetchpriority' => 'high' ) );
}
