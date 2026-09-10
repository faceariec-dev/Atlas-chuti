<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Glossary entry meta box. Category is the default `atlas_glossary_category` box.
 */
class Atlas_Chuti_Glossary_Meta_Box extends Atlas_Chuti_Meta_Box_Base {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	public function post_type() {
		return 'atlas_glossary';
	}
	public function box_id() {
		return 'atlas_glossary_details';
	}
	public function box_title() {
		return __( 'Atlas chutí – detaily pojmu', 'atlas-chuti' );
	}
}
