<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes "search by ingredient" (item 17 of this phase's brief) scale to thousands of
 * recipes. Mirrors the atlas_ingredient dictionary CPT into a hidden technical
 * taxonomy `atlas_ingredient_tax` on atlas_recipe — the exact same pattern already
 * used for country (see class-country-sync.php). A recipe search for "kuře" then
 * becomes: find matching atlas_ingredient posts (a small table — dozens/hundreds of
 * rows, cheap to LIKE-scan) → their term_ids → an indexed tax_query on atlas_recipe
 * (fast at any scale, backed by wp_term_relationships), instead of a LIKE scan over
 * every recipe's serialized ingredients meta.
 */
class Atlas_Chuti_Ingredient_Sync {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'save_post_atlas_ingredient', array( $this, 'sync_term_from_ingredient' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'remove_term_on_delete' ) );
	}

	public function sync_term_from_ingredient( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
			return;
		}

		$term_id = (int) get_post_meta( $post_id, '_atlas_ingredient_term_id', true );
		$term    = $term_id ? get_term( $term_id, 'atlas_ingredient_tax' ) : null;

		if ( ! $term || is_wp_error( $term ) ) {
			$existing = get_term_by( 'slug', $post->post_name, 'atlas_ingredient_tax' );
			if ( $existing ) {
				$term_id = $existing->term_id;
			} else {
				$inserted = wp_insert_term( $post->post_title, 'atlas_ingredient_tax', array( 'slug' => $post->post_name ) );
				if ( is_wp_error( $inserted ) ) {
					return;
				}
				$term_id = $inserted['term_id'];
			}
		} else {
			wp_update_term( $term_id, 'atlas_ingredient_tax', array( 'name' => $post->post_title, 'slug' => $post->post_name ) );
		}

		update_post_meta( $post_id, '_atlas_ingredient_term_id', $term_id );
		update_term_meta( $term_id, 'ingredient_post_id', $post_id );
	}

	public function remove_term_on_delete( $post_id ) {
		if ( 'atlas_ingredient' !== get_post_type( $post_id ) ) {
			return;
		}
		$term_id = (int) get_post_meta( $post_id, '_atlas_ingredient_term_id', true );
		if ( $term_id ) {
			wp_delete_term( $term_id, 'atlas_ingredient_tax' );
		}
	}

	public static function get_term_id_for_ingredient_post( $post_id ) {
		return (int) get_post_meta( $post_id, '_atlas_ingredient_term_id', true );
	}

	/**
	 * Resolves one recipe ingredient row to an atlas_ingredient post: by ingredient_key
	 * first (the stable identity), falling back to a display_name/alias match so rows
	 * imported without an explicit key still tag the recipe correctly where possible.
	 */
	public static function resolve_ingredient_post( $row ) {
		if ( ! empty( $row['ingredient_key'] ) ) {
			$post = Atlas_Chuti_I18N::find_ingredient_by_key( sanitize_title( $row['ingredient_key'] ) );
			if ( $post ) {
				return $post;
			}
		}
		$name = trim( (string) ( $row['display_name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		return self::find_ingredient_by_name_or_alias( $name );
	}

	/**
	 * Small-table LIKE match: title or any alias equals (case-insensitively) the given
	 * text. The ingredient dictionary stays small (normalized entities, not recipes),
	 * so this is cheap even as the recipe count grows into the thousands.
	 */
	public static function find_ingredient_by_name_or_alias( $text ) {
		global $wpdb;
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}

		$exact = get_page_by_title( $text, OBJECT, 'atlas_ingredient' );
		if ( $exact ) {
			return $exact;
		}

		$like = '%' . $wpdb->esc_like( $text ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'atlas_aliases'
				WHERE p.post_type = 'atlas_ingredient' AND p.post_status IN ('publish','draft')
				AND pm.meta_value LIKE %s
				LIMIT 1",
				$like
			)
		);
		return $ids ? get_post( $ids[0] ) : null;
	}

	/**
	 * Tags a recipe with the atlas_ingredient_tax terms for every ingredient row that
	 * could be resolved. Called after a recipe's `atlas_ingredients` meta is saved —
	 * both from the admin meta box and the JSON importer, so search stays in sync
	 * regardless of how the recipe was created.
	 */
	public static function tag_recipe( $recipe_id, $ingredient_rows ) {
		$term_ids = array();
		foreach ( (array) $ingredient_rows as $row ) {
			$ingredient_post = self::resolve_ingredient_post( $row );
			if ( ! $ingredient_post ) {
				continue;
			}
			$term_id = self::get_term_id_for_ingredient_post( $ingredient_post->ID );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}
		wp_set_post_terms( $recipe_id, array_unique( $term_ids ), 'atlas_ingredient_tax', false );
	}

	/**
	 * Ingredient posts whose title or aliases match the search text — used by
	 * class-search.php to widen a keyword search (e.g. "kuře") to every recipe
	 * tagged with the matching normalized ingredient (e.g. "chicken").
	 */
	public static function find_matching_term_ids( $search_text ) {
		global $wpdb;
		$search_text = trim( $search_text );
		if ( '' === $search_text ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $search_text ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'atlas_aliases'
				WHERE p.post_type = 'atlas_ingredient' AND p.post_status = 'publish'
				AND ( p.post_title LIKE %s OR pm.meta_value LIKE %s )",
				$like,
				$like
			)
		);
		$term_ids = array();
		foreach ( $ids as $id ) {
			$term_id = self::get_term_id_for_ingredient_post( $id );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}
		return $term_ids;
	}
}
