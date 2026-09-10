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

		// Mirror the country's continent onto its own taxonomy term so recipe lookups are one query.
		$continent_terms = wp_get_post_terms( $post_id, 'atlas_continent', array( 'fields' => 'ids' ) );
		if ( ! empty( $continent_terms ) && ! is_wp_error( $continent_terms ) ) {
			update_term_meta( $term_id, 'continent_term_id', $continent_terms[0] );
		}
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
	 */
	public function sync_recipe_continent( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( 'atlas_country_tax' !== $taxonomy || 'atlas_recipe' !== get_post_type( $object_id ) ) {
			return;
		}

		$country_term_ids = wp_get_post_terms( $object_id, 'atlas_country_tax', array( 'fields' => 'ids' ) );
		$continent_ids     = array();

		foreach ( $country_term_ids as $country_term_id ) {
			$continent_id = get_term_meta( $country_term_id, 'continent_term_id', true );
			if ( $continent_id ) {
				$continent_ids[] = (int) $continent_id;
			}
		}

		$continent_ids = array_unique( $continent_ids );
		wp_set_post_terms( $object_id, $continent_ids, 'atlas_continent', false );
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
