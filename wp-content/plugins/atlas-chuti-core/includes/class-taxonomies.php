<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filterable taxonomies. `atlas_country_tax` mirrors the atlas_country CPT (see
 * class-country-sync.php) so recipes/glossary entries can be tagged with a country
 * and archives can use fast, URL-friendly tax queries instead of meta queries.
 */
class Atlas_Chuti_Taxonomies {

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
		register_taxonomy(
			'atlas_continent',
			array( 'atlas_country', 'atlas_recipe' ),
			array(
				'labels'            => array(
					'name'          => __( 'Světadíly', 'atlas-chuti' ),
					'singular_name' => __( 'Světadíl', 'atlas-chuti' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'svetadil', 'with_front' => false ),
			)
		);

		register_taxonomy(
			'atlas_country_tax',
			array( 'atlas_recipe', 'atlas_glossary' ),
			array(
				'labels'            => array(
					'name'          => __( 'Země (štítek)', 'atlas-chuti' ),
					'singular_name' => __( 'Země', 'atlas-chuti' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_ui'           => false, // Managed automatically from the Země CPT, see class-country-sync.php.
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'kuchyne', 'with_front' => false ),
			)
		);

		register_taxonomy(
			'atlas_meal_type',
			array( 'atlas_recipe' ),
			array(
				'labels'            => array(
					'name'          => __( 'Typ jídla', 'atlas-chuti' ),
					'singular_name' => __( 'Typ jídla', 'atlas-chuti' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'typ-jidla', 'with_front' => false ),
			)
		);

		register_taxonomy(
			'atlas_difficulty',
			array( 'atlas_recipe' ),
			array(
				'labels'            => array(
					'name'          => __( 'Obtížnost', 'atlas-chuti' ),
					'singular_name' => __( 'Obtížnost', 'atlas-chuti' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'obtiznost', 'with_front' => false ),
			)
		);

		register_taxonomy(
			'atlas_diet',
			array( 'atlas_recipe' ),
			array(
				'labels'            => array(
					'name'          => __( 'Vhodné pro', 'atlas-chuti' ),
					'singular_name' => __( 'Dieta', 'atlas-chuti' ),
				),
				'hierarchical'      => false,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'dieta', 'with_front' => false ),
			)
		);

		register_taxonomy(
			'atlas_glossary_category',
			array( 'atlas_glossary' ),
			array(
				'labels'            => array(
					'name'          => __( 'Kategorie slovníčku', 'atlas-chuti' ),
					'singular_name' => __( 'Kategorie', 'atlas-chuti' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'slovnicek-kategorie', 'with_front' => false ),
			)
		);

		$this->maybe_seed_default_terms();
	}

	/**
	 * Fixed vocabularies (6 continents, 3 difficulty levels, glossary categories) only
	 * need to be created once; everything else (meal types, diet, countries) is open-ended
	 * and grows through the JSON importer or admin UI.
	 */
	private function maybe_seed_default_terms() {
		if ( get_option( 'atlas_chuti_default_terms_seeded' ) ) {
			return;
		}

		// Wrapped in __() on purpose, not just for consistency: a fresh WordPress instance
		// installed with an English site locale (the future .com, per item 17 of the
		// brief) and a matching .mo file would seed these terms in English straight
		// from this same code — no separate "English seeding" branch needed.
		$continents = array( __( 'Evropa', 'atlas-chuti' ), __( 'Asie', 'atlas-chuti' ), __( 'Afrika', 'atlas-chuti' ), __( 'Severní Amerika', 'atlas-chuti' ), __( 'Jižní Amerika', 'atlas-chuti' ), __( 'Oceánie', 'atlas-chuti' ) );
		foreach ( $continents as $name ) {
			if ( ! term_exists( $name, 'atlas_continent' ) ) {
				wp_insert_term( $name, 'atlas_continent' );
			}
		}

		$difficulties = array( __( 'Snadné', 'atlas-chuti' ), __( 'Střední', 'atlas-chuti' ), __( 'Náročné', 'atlas-chuti' ) );
		foreach ( $difficulties as $name ) {
			if ( ! term_exists( $name, 'atlas_difficulty' ) ) {
				wp_insert_term( $name, 'atlas_difficulty' );
			}
		}

		$diets = array( __( 'Vegetariánské', 'atlas-chuti' ), __( 'Veganské', 'atlas-chuti' ) );
		foreach ( $diets as $name ) {
			if ( ! term_exists( $name, 'atlas_diet' ) ) {
				wp_insert_term( $name, 'atlas_diet' );
			}
		}

		$glossary_categories = array( __( 'Kuchařské techniky', 'atlas-chuti' ), __( 'Suroviny', 'atlas-chuti' ), __( 'Gastronomické pojmy', 'atlas-chuti' ), __( 'Nádobí a vybavení', 'atlas-chuti' ) );
		foreach ( $glossary_categories as $name ) {
			if ( ! term_exists( $name, 'atlas_glossary_category' ) ) {
				wp_insert_term( $name, 'atlas_glossary_category' );
			}
		}

		update_option( 'atlas_chuti_default_terms_seeded', 1 );
	}
}
