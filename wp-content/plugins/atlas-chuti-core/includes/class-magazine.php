<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 6, items 3/5: the Magazín uses standard WordPress `post` — no new CPT (item
 * 3) — and standard WordPress `category` for its starter catalog (item 5), NOT one
 * of this plugin's own technical taxonomies. That distinction matters for how
 * cross-locale pairing works: `category` is a taxonomy Polylang manages NATIVELY,
 * and Polylang's whole model for a taxonomy it manages is "one real term per
 * locale, linked via a translation relation" — the OPPOSITE of the
 * Atlas_Chuti_Taxonomy_Labels pattern this codebase uses for its own technical
 * taxonomies (atlas_recipe_tag, atlas_glossary_category, …), where one shared term
 * gets a locale-resolved label. Using the shared-term pattern for `category` would
 * fight Polylang rather than work with it — so this class creates TWO real terms
 * per catalog entry (one per supported locale) and links them via
 * Atlas_Chuti_Polylang_Bridge::link_term_translations(), exactly mirroring how
 * class-json-importer.php links two recipe POSTS, just for terms instead.
 *
 * Only the 8 CZ + 8 EN starter categories from the brief are seeded — no article
 * content (item: "NEGENERUJ ani NEPLŇ desítky článků").
 */
class Atlas_Chuti_Magazine {

	const SEEDED_OPTION = 'atlas_chuti_magazine_categories_seeded_v1';

	/**
	 * One entry per catalog concept — `key` is this project's OWN stable identity
	 * for "which category is this, regardless of locale" (used by
	 * category_slug_for_key() below so template code can ask for e.g. "the Tips &
	 * Tricks category in the current locale" without hardcoding a CZ-only slug).
	 */
	const CATEGORIES = array(
		array(
			'key' => 'tips_tricks',
			'cs'  => array( 'slug' => 'tipy-a-triky', 'name' => 'Tipy a triky' ),
			'en'  => array( 'slug' => 'tips-tricks', 'name' => 'Tips & Tricks' ),
		),
		array(
			'key' => 'techniques',
			'cs'  => array( 'slug' => 'techniky', 'name' => 'Techniky' ),
			'en'  => array( 'slug' => 'techniques', 'name' => 'Techniques' ),
		),
		array(
			'key' => 'ingredients',
			'cs'  => array( 'slug' => 'suroviny', 'name' => 'Suroviny' ),
			'en'  => array( 'slug' => 'ingredients', 'name' => 'Ingredients' ),
		),
		array(
			'key' => 'world_cuisines',
			'cs'  => array( 'slug' => 'kuchyne-sveta', 'name' => 'Kuchyně světa' ),
			'en'  => array( 'slug' => 'world-cuisines', 'name' => 'World Cuisines' ),
		),
		array(
			'key' => 'czech_cuisine',
			'cs'  => array( 'slug' => 'ceska-kuchyne', 'name' => 'Česká kuchyně' ),
			'en'  => array( 'slug' => 'czech-cuisine', 'name' => 'Czech Cuisine' ),
		),
		array(
			'key' => 'seasonal_cooking',
			'cs'  => array( 'slug' => 'sezonni-vareni', 'name' => 'Sezónní vaření' ),
			'en'  => array( 'slug' => 'seasonal-cooking', 'name' => 'Seasonal Cooking' ),
		),
		array(
			'key' => 'food_stories',
			'cs'  => array( 'slug' => 'pribehy-a-historie-jidel', 'name' => 'Příběhy a historie jídel' ),
			'en'  => array( 'slug' => 'food-stories-history', 'name' => 'Food Stories & History' ),
		),
		array(
			'key' => 'practical_guides',
			'cs'  => array( 'slug' => 'prakticke-navody', 'name' => 'Praktické návody' ),
			'en'  => array( 'slug' => 'practical-guides', 'name' => 'Practical Guides' ),
		),
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_seed_categories' ), 25 );
		add_action( 'template_redirect', array( $this, 'disable_author_archive' ) );
	}

	/**
	 * KROK 6, item 10: "Nevytvářej veřejnou author archive/profil stránku, pokud
	 * by byla thin nebo nevhodná." An article's author is shown on its own detail
	 * page as a plain display name (never a link to get_author_posts_url()); this
	 * closes the other half — WordPress still generates /author/{nicename}/ for
	 * every user by default, and nothing in this project's templates would fill
	 * that page with anything but a thin auto-generated post list. Redirect home
	 * rather than 404, matching how is_page_template('template-my-atlas.php')
	 * elsewhere in this codebase treats "route exists but must never be a public
	 * destination" (noindex there; here there's no page to noindex, so a redirect
	 * is the equivalent "don't let this become a real destination" move).
	 */
	public function disable_author_archive() {
		if ( is_author() ) {
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
	}

	public function maybe_seed_categories() {
		if ( get_option( self::SEEDED_OPTION ) ) {
			return;
		}
		foreach ( self::CATEGORIES as $entry ) {
			$cs_id = $this->ensure_term( $entry['cs']['slug'], $entry['cs']['name'], 'cs-CZ' );
			$en_id = $this->ensure_term( $entry['en']['slug'], $entry['en']['name'], 'en' );
			if ( $cs_id && $en_id ) {
				Atlas_Chuti_Polylang_Bridge::link_term_translations( array( 'cs-CZ' => $cs_id, 'en' => $en_id ) );
			}
		}
		update_option( self::SEEDED_OPTION, 1 );
	}

	private function ensure_term( $slug, $name, $locale ) {
		$existing = get_term_by( 'slug', $slug, 'category' );
		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}
		$result = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		$term_id = (int) $result['term_id'];
		Atlas_Chuti_Polylang_Bridge::assign_term_language( $term_id, 'category', $locale );
		return $term_id;
	}

	/**
	 * Item: template code needs "the Tips & Tricks category in THIS locale"
	 * without hardcoding a CZ-only slug (item 6's own prominent-display
	 * requirement) — resolves via the stable `key`, current locale by default.
	 */
	public static function category_slug_for_key( $key, $locale = null ) {
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();
		$lang   = 0 === strpos( $locale, 'cs' ) ? 'cs' : 'en';
		foreach ( self::CATEGORIES as $entry ) {
			if ( $entry['key'] === $key ) {
				return $entry[ $lang ]['slug'] ?? '';
			}
		}
		return '';
	}

	public static function tips_tricks_category_slug( $locale = null ) {
		return self::category_slug_for_key( 'tips_tricks', $locale );
	}
}
