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
 * Returns the hero <img> markup: the real photo when one is set, else the
 * illustrative homepage-hero fallback (inc/fallback-images.php), else an empty
 * string — front-page.php's own placeholder-media branch is the final fallback,
 * so an empty return here can never mean a broken image or an empty box. Eager/
 * high-priority in both the real and fallback case: this is the above-the-fold
 * LCP element, never lazy-loaded.
 */
function atlas_chuti_hero_image_html() {
	$attachment_id = get_theme_mod( 'atlas_hero_image_id' );
	$attrs         = array( 'class' => 'hero-photo', 'loading' => 'eager', 'fetchpriority' => 'high' );
	if ( $attachment_id ) {
		return wp_get_attachment_image( $attachment_id, 'atlas-hero', false, $attrs );
	}
	return atlas_chuti_fallback_image_html( 'homepage-hero', '', __( 'Atlas chutí', 'atlas-chuti' ), $attrs );
}
