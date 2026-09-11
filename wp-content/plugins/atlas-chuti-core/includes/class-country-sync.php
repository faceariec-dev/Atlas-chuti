<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the rich `atlas_country` CPT posts (the real single pages) and the lightweight
 * `atlas_country_tax` taxonomy term (used to tag recipes/glossary entries and filter
 * archives) in sync. Also copies the country's continent onto every recipe tagged
 * with that country, so recipe archives can filter by continent without a join.
 *
 * One canonical term per ISO code (item 8 of this phase's brief), NOT one term per
 * post: once a second locale exists, "IT" is TWO posts (cs-CZ Itálie, en Italy) in
 * this one database, but recipes/glossary entries should still be tag-filterable by
 * "Italy" as a single concept regardless of which locale is browsing. The term's
 * `iso_code` meta is its real identity; `locale_post_map` meta records which post
 * represents this country in each locale — e.g. { "cs-CZ": 123, "en": 456 }.
 */
class Atlas_Chuti_Country_Sync {

	// Only this locale's variant is allowed to (re)name the shared atlas_country_tax
	// term — see sync_term_from_country() below.
	const DEFAULT_LOCALE_FOR_TERM_NAME = 'cs-CZ';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post_atlas_country', array( $this, 'sync_term_from_country' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'remove_post_on_delete' ) );
		add_action( 'set_object_terms', array( $this, 'sync_recipe_continent' ), 10, 6 );
	}

	/**
	 * Creates/updates the ONE atlas_country_tax term for this country's ISO code
	 * whenever a Země post is saved, and records this locale's post in the term's
	 * locale_post_map. Relies on atlas_iso_code/atlas_locale already being on the post
	 * at this point — the JSON importer sets both via wp_insert_post()'s `meta_input`
	 * (applied before save_post fires), and class-i18n.php's default-locale backfill
	 * covers plain admin saves — so there's no hook-ordering dependency to get wrong.
	 */
	public function sync_term_from_country( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status && 'draft' !== $post->post_status ) {
			return;
		}

		$iso    = strtoupper( (string) get_post_meta( $post_id, 'atlas_iso_code', true ) );
		$locale = Atlas_Chuti_I18N::get_locale( $post_id );

		$term_id = $iso ? self::find_term_id_by_iso( $iso ) : 0;

		if ( ! $term_id ) {
			$inserted = wp_insert_term( $post->post_title, 'atlas_country_tax', array( 'slug' => $iso ? strtolower( $iso ) : $post->post_name ) );
			if ( is_wp_error( $inserted ) ) {
				return;
			}
			$term_id = $inserted['term_id'];
			if ( $iso ) {
				update_term_meta( $term_id, 'iso_code', $iso );
			}
		} elseif ( self::DEFAULT_LOCALE_FOR_TERM_NAME === $locale ) {
			// Only the default-locale (cs-CZ) variant is allowed to rename the shared
			// term — otherwise importing/editing the future English variant would
			// clobber the Czech label every admin list/tax_query display still relies on.
			wp_update_term( $term_id, 'atlas_country_tax', array( 'name' => $post->post_title ) );
		}

		$map               = self::get_locale_post_map( $term_id );
		$map[ $locale ]    = $post_id;
		update_term_meta( $term_id, 'locale_post_map', $map );

		update_post_meta( $post_id, '_atlas_country_term_id', $term_id );

		// Deliberately NOT caching the continent here (a term meta cache would only be
		// correct if this hook ran strictly after the continent taxonomy was assigned,
		// which the JSON importer previously violated — see get_continent_ids_for_country_term()
		// below, which always resolves live from the Country CPT's own taxonomy instead).
	}

	public function remove_post_on_delete( $post_id ) {
		if ( 'atlas_country' !== get_post_type( $post_id ) ) {
			return;
		}
		$term_id = (int) get_post_meta( $post_id, '_atlas_country_term_id', true );
		if ( ! $term_id ) {
			return;
		}
		$map = self::get_locale_post_map( $term_id );
		$locale = Atlas_Chuti_I18N::get_locale( $post_id );
		unset( $map[ $locale ] );
		if ( $map ) {
			update_term_meta( $term_id, 'locale_post_map', $map );
		} else {
			// No locale variant left pointing at this term — it was only ever this one
			// post, so it's safe to remove entirely instead of leaving an orphan term.
			wp_delete_term( $term_id, 'atlas_country_tax' );
		}
	}

	/**
	 * When a recipe gets country term(s) assigned, copy each country's continent onto the
	 * recipe so `taxonomy-atlas_continent.php` / recipe archive filters work directly.
	 * Resolves each country's continent LIVE (from the Country CPT's own atlas_continent
	 * terms) rather than from a cache, so this is correct no matter what order the
	 * importer or an editor happened to save fields in.
	 */
	public function sync_recipe_continent( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( 'atlas_country_tax' !== $taxonomy || 'atlas_recipe' !== get_post_type( $object_id ) ) {
			return;
		}

		$country_term_ids = wp_get_post_terms( $object_id, 'atlas_country_tax', array( 'fields' => 'ids' ) );
		$continent_ids     = array();

		foreach ( $country_term_ids as $country_term_id ) {
			$continent_ids = array_merge( $continent_ids, self::get_continent_ids_for_country_term( $country_term_id ) );
		}

		$continent_ids = array_unique( $continent_ids );
		wp_set_post_terms( $object_id, $continent_ids, 'atlas_continent', false );
	}

	/**
	 * Live lookup: which atlas_continent term IDs apply to a given atlas_country_tax
	 * term, resolved via that term's locale variant post for the CURRENT locale (or,
	 * failing that, any locale variant — the continent is locale-independent, so any
	 * variant's assignment is equally valid).
	 */
	public static function get_continent_ids_for_country_term( $country_term_id ) {
		$country_post_id = self::get_country_post_for_term( $country_term_id );
		if ( ! $country_post_id ) {
			return array();
		}
		$continent_ids = wp_get_post_terms( $country_post_id, 'atlas_continent', array( 'fields' => 'ids' ) );
		return is_wp_error( $continent_ids ) ? array() : array_map( 'intval', $continent_ids );
	}

	/**
	 * Re-runs continent propagation for every recipe currently tagged with this country
	 * term. Called explicitly by the JSON importer/admin save once a country's continent
	 * is known to be final, and safe to call any time (e.g. after an editor changes a
	 * country's continent later) since it always reads the live continent, never a cache.
	 */
	public static function resync_recipes_for_country_term( $country_term_id ) {
		$recipe_ids = get_posts(
			array(
				'post_type'      => 'atlas_recipe',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => array( 'publish', 'draft' ),
				'tax_query'      => array( array( 'taxonomy' => 'atlas_country_tax', 'field' => 'term_id', 'terms' => $country_term_id ) ),
			)
		);
		foreach ( $recipe_ids as $recipe_id ) {
			$country_term_ids = wp_get_post_terms( $recipe_id, 'atlas_country_tax', array( 'fields' => 'ids' ) );
			$continent_ids    = array();
			foreach ( $country_term_ids as $tid ) {
				$continent_ids = array_merge( $continent_ids, self::get_continent_ids_for_country_term( $tid ) );
			}
			wp_set_post_terms( $recipe_id, array_unique( $continent_ids ), 'atlas_continent', false );
		}
	}

	/**
	 * Helper: resolve the atlas_country_tax term_id for a Země CPT post.
	 */
	public static function get_term_id_for_country_post( $post_id ) {
		return (int) get_post_meta( $post_id, '_atlas_country_term_id', true );
	}

	/**
	 * Helper: resolve the Země CPT post_id for an atlas_country_tax term, in a given
	 * locale (defaults to the current request locale). Falls back to the default
	 * locale's post, then to any locale variant, so callers that just need "a" post
	 * for this country (e.g. counting/reading locale-independent facts) still work
	 * before a second locale exists.
	 */
	public static function get_country_post_for_term( $term_id, $locale = null ) {
		$map    = self::get_locale_post_map( $term_id );
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();

		if ( isset( $map[ $locale ] ) ) {
			return (int) $map[ $locale ];
		}
		if ( isset( $map[ Atlas_Chuti_I18N::DEFAULT_LOCALE ] ) ) {
			return (int) $map[ Atlas_Chuti_I18N::DEFAULT_LOCALE ];
		}
		$any = reset( $map );
		return $any ? (int) $any : 0;
	}

	public static function get_locale_post_map( $term_id ) {
		$map = get_term_meta( $term_id, 'locale_post_map', true );
		return is_array( $map ) ? $map : array();
	}

	public static function find_term_id_by_iso( $iso_code ) {
		$iso   = strtoupper( (string) $iso_code );
		if ( ! $iso ) {
			return 0;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'atlas_country_tax',
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array( array( 'key' => 'iso_code', 'value' => $iso, 'compare' => '=' ) ),
			)
		);
		return ( $terms && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
	}
}
