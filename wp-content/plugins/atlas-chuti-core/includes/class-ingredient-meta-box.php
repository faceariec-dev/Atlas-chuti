<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized ingredient dictionary meta box (aliases so "rajče"/"rajčata"/"rajčat"
 * resolve to one canonical ingredient — needed for the future "Co mám doma?" feature).
 */
class Atlas_Chuti_Ingredient_Meta_Box extends Atlas_Chuti_Meta_Box_Base {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	public function post_type() {
		return 'atlas_ingredient';
	}
	public function box_id() {
		return 'atlas_ingredient_details';
	}
	public function box_title() {
		return __( 'Atlas chutí – normalizace ingredience', 'atlas-chuti' );
	}
}
