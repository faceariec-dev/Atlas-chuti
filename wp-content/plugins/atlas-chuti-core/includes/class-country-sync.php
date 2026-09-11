<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the rich `atlas_country` CPT post (the real single page) and the lightweight
 * `atlas_country_tax` taxonomy term (used to tag recipes/glossary entries and filter
 * archives) in sync by slug. Also copies the country's continent onto every recipe
 * tagged with that country, so recipe archives can filter by continent without a join.
 */
class Atlas_Chuti_Country_Sync {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post_atlas_country', array( $this, 'sync_term_from_country' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'remove_term_on_delete' ) );
		add_action( 'set_object_terms', array( $this, 'sync_recipe_continent' ), 10, 6 );
	}

	/**
	 * Creates/updates the matching atlas_country_tax term whenever a Země post is saved,
	 * and stores the term_id on the post (and the post_id on the term) for fast lookups.
	 */
	public function sync_term_from_country( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status && 'draft' !== $post->post_status ) {
			return;
		}

		$term_id = (int) get_post_meta( $post_id, '_atlas_country_term_id', true );
		$term    = $term_id ? get_term( $term_id, 'atlas_country_tax' ) : null;

		if ( ! $term || is_wp_error( $term ) ) {
			$existing = get_term_by( 'slug', $post->post_name, 'atlas_country_tax' );
			if ( $existing ) {
				$term_id = $existing->term_id;
			} else {
				$inserted = wp_insert_term( $post->post_title, 'atlas_country_tax', array( 'slug' => $post->post_name ) );
				if ( is_wp_error( $inserted ) ) {
					return;
				}
				$term_id = $inserted['term_id'];
			}
		} else {
			wp_update_term(
				$term_id,
				'atlas_country_tax',
				array(
					'name' => $post->post_title,
					'slug' => $post->post_name,
				)
			);
		}

		update_post_meta( $post_id, '_atlas_country_term_id', $term_id );
		update_term_meta( $term_id, 'country_post_id', $post_id );

		// Deliberately NOT caching the continent here (a term meta cache would only be
		// correct if this hook ran strictly after the continent taxonomy was assigned,
		// which the JSON importer previously violated — see get_continent_ids_for_country_term()
		// below, which always resolves live from the Country CPT's own taxonomy instead).
	}

	public function remove_term_on_delete( $post_id ) {
		if ( 'atlas_country' !== get_post_type( $post_id ) ) {
			return;
		}
		$term_id = (int) get_post_meta( $post_id, '_atlas_country_term_id', true );
		if ( $term_id ) {
			wp_delete_term( $term_id, 'atlas_country_tax' );
		}
	}

	/**
	 * When a recipe gets country term(s) assigned, copy each country's continent onto the
	 * recipe so `taxonomy-atlas_continent.php` / recipe archive filters work directly.
	 * Resolves each country's continent LIVE (from the Country CPT's own atlas_continent
	 * terms) rather than from a cache, so this is correct no matter what order the
	 * importer or an editor happened to save fields in — item 2 of this phase's brief.
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
	 * Live lookup: which atlas_continent term IDs apply to a given atlas_country_tax term,
	 * resolved via the underlying Země CPT post's own continent assignment (single source
	 * of truth — never cached, so there is no ordering dependency to get wrong).
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
	 * Helper: resolve the Země CPT post_id for an atlas_country_tax term.
	 */
	public static function get_country_post_for_term( $term_id ) {
		return (int) get_term_meta( $term_id, 'country_post_id', true );
	}
}
