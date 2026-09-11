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

		// Technical taxonomies (item 16 of this phase's brief): they exist purely to make
		// tax_query filtering/search fast (see class-country-sync.php, class-ingredient-sync.php,
		// inc/archive-filters.php). None of them has a real landing page, so none of them
		// gets a public, indexable archive URL — that would just be duplicate content
		// competing with /zeme/{slug}/, and /recepty/?obtiznost=... already covers filtering.
		// atlas_continent is the one exception: it has its own quality landing page
		// (taxonomy-atlas_continent.php) and stays public.
		$technical_taxonomy_args = array(
			'public'             => false,
			'publicly_queryable' => false,
			'show_in_nav_menus'  => false,
			'rewrite'            => false,
			'show_in_rest'       => true,
			'show_admin_column'  => true,
		);

		register_taxonomy(
			'atlas_country_tax',
			array( 'atlas_recipe', 'atlas_glossary' ),
			array_merge(
				$technical_taxonomy_args,
				array(
					'labels'       => array(
						'name'          => __( 'Země (štítek)', 'atlas-chuti' ),
						'singular_name' => __( 'Země', 'atlas-chuti' ),
					),
					'hierarchical' => false,
					'show_ui'      => false, // Managed automatically from the Země CPT, see class-country-sync.php.
				)
			)
		);

		register_taxonomy(
			'atlas_ingredient_tax',
			array( 'atlas_recipe' ),
			array_merge(
				$technical_taxonomy_args,
				array(
					'labels'       => array(
						'name'          => __( 'Ingredience (štítek)', 'atlas-chuti' ),
						'singular_name' => __( 'Ingredience', 'atlas-chuti' ),
					),
					'hierarchical' => false,
					'show_ui'      => false, // Managed automatically from the Ingredience CPT, see class-ingredient-sync.php.
				)
			)
		);

		register_taxonomy(
			'atlas_meal_type',
			array( 'atlas_recipe' ),
			array_merge(
				$technical_taxonomy_args,
				array(
					'labels'       => array(
						'name'          => __( 'Typ jídla', 'atlas-chuti' ),
						'singular_name' => __( 'Typ jídla', 'atlas-chuti' ),
					),
					'hierarchical' => false,
					'show_ui'      => true,
				)
			)
		);

		register_taxonomy(
			'atlas_difficulty',
			array( 'atlas_recipe' ),
			array_merge(
				$technical_taxonomy_args,
				array(
					'labels'       => array(
						'name'          => __( 'Obtížnost', 'atlas-chuti' ),
						'singular_name' => __( 'Obtížnost', 'atlas-chuti' ),
					),
					'hierarchical' => false,
					'show_ui'      => true,
				)
			)
		);

		register_taxonomy(
			'atlas_diet',
			array( 'atlas_recipe' ),
			array_merge(
				$technical_taxonomy_args,
				array(
					'labels'       => array(
						'name'          => __( 'Vhodné pro', 'atlas-chuti' ),
						'singular_name' => __( 'Dieta', 'atlas-chuti' ),
					),
					'hierarchical' => false,
					'show_ui'      => true,
				)
			)
		);

		// Same principle as above: archive-atlas_glossary.php already filters by category
		// via ?kategorie=, so a separate public taxonomy archive would only be duplicate
		// content with no dedicated landing page of its own.
		register_taxonomy(
			'atlas_glossary_category',
			array( 'atlas_glossary' ),
			array_merge(
				$technical_taxonomy_args,
				array(
					'labels'       => array(
						'name'          => __( 'Kategorie slovníčku', 'atlas-chuti' ),
						'singular_name' => __( 'Kategorie', 'atlas-chuti' ),
					),
					'hierarchical' => true,
					'show_ui'      => true,
				)
			)
		);

		$this->maybe_seed_default_terms();
	}

	/**
	 * Fixed vocabularies (6 continents, 3 difficulty levels, 4 glossary categories)
	 * only need to be created once; everything else (meal types, diet, countries) is
	 * open-ended and grows through the JSON importer or admin UI.
	 *
	 * Seeded by STABLE KEY (slug) now, not by Czech name (item 14/15/18/19 of this
	 * phase's brief) — "europe"/"easy"/"technique", never "Evropa"/"Snadné"/
	 * "Kuchařské techniky" as the term's identity. That's what makes it safe to add
	 * English to this SAME WordPress instance later: the stable slug is shared by
	 * both locales' content (a recipe in either language tags into the very same
	 * "easy" term), while the term's `name` — the only thing an old-style seed-by-name
	 * flag could really distinguish — stays whatever the seeding locale wrote, with
	 * Atlas_Chuti_Taxonomy_Labels resolving the OTHER locale's label at display time
	 * instead of requiring a duplicate term. There is no "fresh English WordPress
	 * install" scenario to design for — atlaschuti.com is a locale on this same
	 * install, not a second WordPress instance.
	 */
	private function maybe_seed_default_terms() {
		if ( get_option( 'atlas_chuti_default_terms_seeded_v2' ) ) {
			return;
		}

		foreach ( array( 'atlas_continent', 'atlas_difficulty', 'atlas_diet', 'atlas_meal_type', 'atlas_glossary_category' ) as $taxonomy ) {
			$this->seed_terms_from_labels( $taxonomy );
		}

		update_option( 'atlas_chuti_default_terms_seeded_v2', 1 );
	}

	private function seed_terms_from_labels( $taxonomy ) {
		foreach ( Atlas_Chuti_Taxonomy_Labels::keys( $taxonomy ) as $key ) {
			if ( ! get_term_by( 'slug', $key, $taxonomy ) ) {
				wp_insert_term(
					Atlas_Chuti_Taxonomy_Labels::label( $taxonomy, $key, Atlas_Chuti_I18N::DEFAULT_LOCALE ),
					$taxonomy,
					array( 'slug' => $key )
				);
			}
		}
	}
}
