<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the four core content entities. The data model intentionally lives
 * here (not in the theme) so it survives a theme switch.
 */
class Atlas_Chuti_Post_Types {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register() {
		$this->register_recipe();
		$this->register_country();
		$this->register_glossary();
		$this->register_ingredient();
	}

	private function register_recipe() {
		register_post_type(
			'atlas_recipe',
			array(
				'labels'             => array(
					'name'          => __( 'Recepty', 'atlas-chuti' ),
					'singular_name' => __( 'Recept', 'atlas-chuti' ),
					'add_new_item'  => __( 'Přidat recept', 'atlas-chuti' ),
					'edit_item'     => __( 'Upravit recept', 'atlas-chuti' ),
					'search_items'  => __( 'Hledat recepty', 'atlas-chuti' ),
					'not_found'     => __( 'Žádné recepty nenalezeny', 'atlas-chuti' ),
				),
				'public'              => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-carrot',
				'menu_position'       => 20,
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author' ),
				'has_archive'         => 'recepty',
				'rewrite'             => array( 'slug' => 'recepty', 'with_front' => false ),
				'exclude_from_search' => false,
				'capability_type'     => 'post',
			)
		);

		register_post_type(
			'atlas_country',
			array(
				'labels'             => array(
					'name'          => __( 'Země', 'atlas-chuti' ),
					'singular_name' => __( 'Země', 'atlas-chuti' ),
					'add_new_item'  => __( 'Přidat zemi', 'atlas-chuti' ),
					'edit_item'     => __( 'Upravit zemi', 'atlas-chuti' ),
					'search_items'  => __( 'Hledat země', 'atlas-chuti' ),
					'not_found'     => __( 'Žádné země nenalezeny', 'atlas-chuti' ),
				),
				'public'              => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-location-alt',
				'menu_position'       => 21,
				'supports'            => array( 'title', 'thumbnail', 'revisions' ),
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'zeme', 'with_front' => false ),
				'capability_type'     => 'post',
			)
		);
	}

	private function register_country() {
		// Registered above alongside the recipe (kept in one place so both slugs are easy to compare).
	}

	private function register_glossary() {
		register_post_type(
			'atlas_glossary',
			array(
				'labels'             => array(
					'name'          => __( 'Slovníček', 'atlas-chuti' ),
					'singular_name' => __( 'Pojem', 'atlas-chuti' ),
					'add_new_item'  => __( 'Přidat pojem', 'atlas-chuti' ),
					'edit_item'     => __( 'Upravit pojem', 'atlas-chuti' ),
					'search_items'  => __( 'Hledat pojmy', 'atlas-chuti' ),
					'not_found'     => __( 'Žádné pojmy nenalezeny', 'atlas-chuti' ),
				),
				'public'              => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-book-alt',
				'menu_position'       => 22,
				'supports'            => array( 'title', 'thumbnail', 'revisions' ),
				'has_archive'         => 'slovnicek',
				'rewrite'             => array( 'slug' => 'slovnicek', 'with_front' => false ),
				'capability_type'     => 'post',
			)
		);
	}

	private function register_ingredient() {
		// Not public: this is the normalized ingredient dictionary ("rajče"/"rajčata"/"rajčat" → one entity)
		// used internally by recipes and, later, by the "Co mám doma?" feature.
		register_post_type(
			'atlas_ingredient',
			array(
				'labels'          => array(
					'name'          => __( 'Ingredience', 'atlas-chuti' ),
					'singular_name' => __( 'Ingredience', 'atlas-chuti' ),
					'add_new_item'  => __( 'Přidat ingredienci', 'atlas-chuti' ),
					'edit_item'     => __( 'Upravit ingredienci', 'atlas-chuti' ),
					'search_items'  => __( 'Hledat ingredience', 'atlas-chuti' ),
					'not_found'     => __( 'Žádné ingredience nenalezeny', 'atlas-chuti' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=atlas_recipe',
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'revisions' ),
				'has_archive'     => false,
				'capability_type' => 'post',
			)
		);
	}
}
