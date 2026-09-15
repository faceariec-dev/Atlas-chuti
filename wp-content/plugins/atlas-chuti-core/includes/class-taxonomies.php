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

		// Controlled public recipe tags (KROK 3, items 5-9): a CLOSED, editorially curated
		// catalog — unlike atlas_meal_type/atlas_diet above, the importer never
		// auto-creates a new term here (see Atlas_Chuti_JSON_Importer::
		// resolve_known_tag_term()); an unknown tag key is a hard validation error. The
		// starter catalog itself lives in Atlas_Chuti_Taxonomy_Labels (seeded below by
		// maybe_seed_default_terms(), same mechanism as atlas_difficulty/atlas_diet/
		// atlas_meal_type), so growing the catalog later means editing one array, not
		// writing migration code. `capabilities` restricts creating/renaming/deleting
		// terms to administrators — assigning EXISTING tags to a recipe stays open to
		// anyone who can edit recipes — so even the wp-admin "add new tag" UI can't grow
		// an uncontrolled tag cloud the way the default WordPress Tags box would.
		// public=>false / rewrite=>false for now, same as meal_type/difficulty/diet
		// above: no public archive template exists yet for any of these technical
		// taxonomies, so turning one on here would need real frontend/SEO work (thin
		// archive pages, indexing strategy) that is out of this step's data-model scope
		// — see docs/implementation-reports/step-03-recipe-data-model-importer.md,
		// section H, for the deferred plan.
		register_taxonomy(
			'atlas_recipe_tag',
			array( 'atlas_recipe' ),
			array_merge(
				$technical_taxonomy_args,
				array(
					'labels'       => array(
						'name'          => __( 'Štítky', 'atlas-chuti' ),
						'singular_name' => __( 'Štítek', 'atlas-chuti' ),
					),
					'hierarchical' => false,
					'show_ui'      => true,
					'capabilities' => array(
						'manage_terms' => 'manage_options',
						'edit_terms'   => 'manage_options',
						'delete_terms' => 'manage_options',
						'assign_terms' => 'edit_posts',
					),
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

		// KROK 6, item 16: Diskuze's closed category catalog — same shared-term/
		// resolved-label mechanism as atlas_glossary_category, and the same
		// admin-only term-management capabilities as atlas_recipe_tag (a plain
		// subscriber can never create/rename/delete a category, only pick one of
		// the seeded set when opening a new topic — see class-discussion.php).
		register_taxonomy(
			'atlas_topic_category',
			array( 'atlas_topic' ),
			array_merge(
				$technical_taxonomy_args,
				array(
					'labels'       => array(
						'name'          => __( 'Kategorie diskuze', 'atlas-chuti' ),
						'singular_name' => __( 'Kategorie', 'atlas-chuti' ),
					),
					'hierarchical' => false,
					'show_ui'      => true,
					'capabilities' => array(
						'manage_terms' => 'manage_options',
						'edit_terms'   => 'manage_options',
						'delete_terms' => 'manage_options',
						'assign_terms' => 'edit_posts',
					),
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
		// Bumped to _v3 (KROK 3, item 6-7): adding atlas_recipe_tag to the loop below
		// wouldn't reach any install that already ran the _v2 seed — this option name
		// is the existing, established way this codebase forces a one-time reseed pass
		// when the seed SET changes. seed_terms_from_labels() itself is idempotent (it
		// skips any key that already has a term), so re-running it for continent/
		// difficulty/diet/meal_type/glossary_category here is a safe no-op — only the
		// new atlas_recipe_tag keys actually get created.
		// Bumped to _v4 (KROK 6): adding atlas_topic_category wouldn't reach any
		// install that already ran _v3 — same one-time-reseed mechanism as the _v2→_v3
		// bump above; seed_terms_from_labels() is idempotent either way.
		if ( get_option( 'atlas_chuti_default_terms_seeded_v4' ) ) {
			return;
		}

		foreach ( array( 'atlas_continent', 'atlas_difficulty', 'atlas_diet', 'atlas_meal_type', 'atlas_recipe_tag', 'atlas_glossary_category', 'atlas_topic_category' ) as $taxonomy ) {
			$this->seed_terms_from_labels( $taxonomy );
		}

		update_option( 'atlas_chuti_default_terms_seeded_v4', 1 );
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
