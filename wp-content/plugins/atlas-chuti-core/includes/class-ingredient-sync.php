<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes "search by ingredient" scale to thousands of recipes. Mirrors the
 * atlas_ingredient dictionary CPT into a hidden technical taxonomy
 * `atlas_ingredient_tax` on atlas_recipe — the exact same pattern used for country
 * (see class-country-sync.php). A recipe search for "kuře" then becomes: find
 * matching atlas_ingredient posts (a small table — dozens/hundreds of rows, cheap to
 * LIKE-scan) → their term_ids → an indexed tax_query on atlas_recipe (fast at any
 * scale, backed by wp_term_relationships), instead of a LIKE scan over every recipe's
 * serialized ingredients meta.
 *
 * One canonical term per ingredient_key (item 9 of this phase's brief), not one term
 * per post: once a second locale exists, "tomato" is TWO posts (cs-CZ Rajče, en
 * Tomato), but a recipe should stay taggable/filterable by the one shared ingredient
 * concept regardless of locale. The term's `ingredient_key` meta is its identity;
 * `locale_post_map` meta records which dictionary post represents it per locale.
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
		add_action( 'before_delete_post', array( $this, 'remove_post_on_delete' ) );
	}

	public function sync_term_from_ingredient( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
			return;
		}

		$key    = sanitize_title( (string) get_post_meta( $post_id, 'atlas_ingredient_key', true ) );
		$locale = Atlas_Chuti_I18N::get_locale( $post_id );

		$term_id = $key ? self::find_term_id_by_key( $key ) : 0;

		if ( ! $term_id ) {
			$inserted = wp_insert_term( $post->post_title, 'atlas_ingredient_tax', array( 'slug' => $key ?: $post->post_name ) );
			if ( is_wp_error( $inserted ) ) {
				return;
			}
			$term_id = $inserted['term_id'];
			if ( $key ) {
				update_term_meta( $term_id, 'ingredient_key', $key );
			}
		} elseif ( Atlas_Chuti_I18N::DEFAULT_LOCALE === $locale ) {
			wp_update_term( $term_id, 'atlas_ingredient_tax', array( 'name' => $post->post_title ) );
		}

		$map            = self::get_locale_post_map( $term_id );
		$map[ $locale ] = $post_id;
		update_term_meta( $term_id, 'locale_post_map', $map );

		update_post_meta( $post_id, '_atlas_ingredient_term_id', $term_id );
	}

	public function remove_post_on_delete( $post_id ) {
		if ( 'atlas_ingredient' !== get_post_type( $post_id ) ) {
			return;
		}
		$term_id = (int) get_post_meta( $post_id, '_atlas_ingredient_term_id', true );
		if ( ! $term_id ) {
			return;
		}
		$map    = self::get_locale_post_map( $term_id );
		$locale = Atlas_Chuti_I18N::get_locale( $post_id );
		unset( $map[ $locale ] );
		if ( $map ) {
			update_term_meta( $term_id, 'locale_post_map', $map );
		} else {
			wp_delete_term( $term_id, 'atlas_ingredient_tax' );
		}
	}

	public static function get_term_id_for_ingredient_post( $post_id ) {
		return (int) get_post_meta( $post_id, '_atlas_ingredient_term_id', true );
	}

	public static function get_ingredient_post_for_term( $term_id, $locale = null ) {
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

	public static function find_term_id_by_key( $ingredient_key ) {
		$key = sanitize_title( (string) $ingredient_key );
		if ( ! $key ) {
			return 0;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'atlas_ingredient_tax',
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array( array( 'key' => 'ingredient_key', 'value' => $key, 'compare' => '=' ) ),
			)
		);
		return ( $terms && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
	}

	/**
	 * Resolves one recipe ingredient row to an atlas_ingredient post: by ingredient_key
	 * first (the stable identity, scoped to $locale — defaults to the current locale),
	 * falling back to a display_name/alias match so rows imported without an explicit
	 * key still tag the recipe correctly where possible.
	 */
	public static function resolve_ingredient_post( $row, $locale = null ) {
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();
		if ( ! empty( $row['ingredient_key'] ) ) {
			$post = Atlas_Chuti_I18N::find_ingredient_by_key( sanitize_title( $row['ingredient_key'] ), $locale );
			if ( $post ) {
				return $post;
			}
		}
		$name = trim( (string) ( $row['display_name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}
		return self::find_ingredient_by_name_or_alias( $name, $locale );
	}

	/**
	 * Small-table LIKE match: title or any alias equals (case-insensitively) the given
	 * text, scoped to one locale — a Czech search must never match against an English
	 * alias once a second locale exists (item 21 of this phase's brief). The
	 * dictionary stays small (normalized entities, not recipes), so this is cheap even
	 * as the recipe count grows into the thousands.
	 */
	public static function find_ingredient_by_name_or_alias( $text, $locale = null ) {
		global $wpdb;
		$text   = trim( $text );
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();
		if ( '' === $text ) {
			return null;
		}

		$exact = get_posts(
			array(
				'post_type'      => 'atlas_ingredient',
				'title'          => $text,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'meta_query'     => array( array( 'key' => 'atlas_locale', 'value' => $locale ) ),
			)
		);
		if ( $exact ) {
			return $exact[0];
		}

		$like = '%' . $wpdb->esc_like( $text ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'atlas_aliases'
				INNER JOIN {$wpdb->postmeta} pml ON pml.post_id = p.ID AND pml.meta_key = 'atlas_locale' AND pml.meta_value = %s
				WHERE p.post_type = 'atlas_ingredient' AND p.post_status IN ('publish','draft')
				AND pm.meta_value LIKE %s
				LIMIT 1",
				$locale,
				$like
			)
		);
		return $ids ? get_post( $ids[0] ) : null;
	}

	/**
	 * Tags a recipe with the atlas_ingredient_tax terms for every ingredient row that
	 * could be resolved. Called after a recipe's `atlas_ingredients` meta is saved —
	 * both from the admin meta box and the JSON importer, so search stays in sync
	 * regardless of how the recipe was created. $locale defaults to the recipe's own
	 * locale so a recipe always resolves its ingredients against the matching
	 * dictionary variant.
	 */
	public static function tag_recipe( $recipe_id, $ingredient_rows, $locale = null ) {
		$locale   = $locale ?: Atlas_Chuti_I18N::get_locale( $recipe_id );
		$term_ids = array();
		foreach ( (array) $ingredient_rows as $row ) {
			$ingredient_post = self::resolve_ingredient_post( $row, $locale );
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
	 * Ingredient posts whose title or aliases match the search text, scoped to one
	 * locale — used by class-search.php to widen a keyword search (e.g. "kuře") to
	 * every recipe tagged with the matching normalized ingredient (e.g. "chicken").
	 * The returned atlas_ingredient_tax term IDs are shared across locales (one
	 * canonical term per ingredient_key), so they tag recipes regardless of the
	 * recipe's own locale — class-search.php still locale-scopes the RECIPE query
	 * itself (via class-i18n.php's central filter) so results never mix languages.
	 */
	public static function find_matching_term_ids( $search_text, $locale = null ) {
		global $wpdb;
		$search_text = trim( $search_text );
		$locale      = $locale ?: Atlas_Chuti_I18N::current_locale();
		if ( '' === $search_text ) {
			return array();
		}
		$like = '%' . $wpdb->esc_like( $search_text ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pml ON pml.post_id = p.ID AND pml.meta_key = 'atlas_locale' AND pml.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'atlas_aliases'
				WHERE p.post_type = 'atlas_ingredient' AND p.post_status = 'publish'
				AND ( p.post_title LIKE %s OR pm.meta_value LIKE %s )",
				$locale,
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
