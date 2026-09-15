<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 8, item 16-18: "Co mám doma?" — ingredient-key based recipe finder.
 * Selection is by `ingredient_key` against the existing normalized ingredient
 * dictionary (`atlas_ingredient` CPT / `atlas_ingredient_tax`, see
 * class-ingredient-sync.php), never free text (item 16's own explicit
 * requirement). Matching is a tag-membership question — "does this recipe use
 * ingredient X" — not an inventory/quantity system (item 18's own explicit
 * scope limit: no expiry dates, no gram-level stock).
 */
class Atlas_Chuti_Ingredient_Finder {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Autocomplete source for the ingredient chip picker — real, current-locale
	 * `atlas_ingredient` posts only, matched by title (locale-scoped, since the
	 * shared term links one post per locale — see class-ingredient-sync.php).
	 * Returns array of ['key'=>ingredient_key, 'label'=>locale display name].
	 */
	public function search_ingredients( $query, $locale = null, $limit = 10 ) {
		$query = trim( wp_strip_all_tags( (string) $query ) );
		if ( '' === $query ) {
			return array();
		}
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();

		global $wpdb;
		$like = '%' . $wpdb->esc_like( $query ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pml ON pml.post_id = p.ID AND pml.meta_key = 'atlas_locale' AND pml.meta_value = %s
				WHERE p.post_type = 'atlas_ingredient' AND p.post_title LIKE %s
				ORDER BY p.post_title ASC
				LIMIT %d",
				$locale,
				$like,
				$limit
			)
		);

		$out = array();
		foreach ( $ids as $id ) {
			$key = get_post_meta( $id, 'atlas_ingredient_key', true );
			if ( $key ) {
				$out[] = array( 'key' => $key, 'label' => get_the_title( $id ) );
			}
		}
		return $out;
	}

	/**
	 * item 18/47's accessible-fallback allowance ("safely simpler accessible
	 * select/search"): the FULL real ingredient dictionary, current locale,
	 * alphabetical — what the theme renders as a plain native
	 * `<select multiple>` so "Co mám doma?" is fully usable (keyboard,
	 * screen reader, no JS) even before ingredient-finder.js enhances it into
	 * a chip/autocomplete UI. Bounded (item 42/47: never an unbounded dump).
	 */
	public function list_ingredients( $locale = null, $limit = 300 ) {
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pml ON pml.post_id = p.ID AND pml.meta_key = 'atlas_locale' AND pml.meta_value = %s
				WHERE p.post_type = 'atlas_ingredient' AND p.post_status = 'publish'
				ORDER BY p.post_title ASC
				LIMIT %d",
				$locale,
				$limit
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$key = get_post_meta( $id, 'atlas_ingredient_key', true );
			if ( $key ) {
				$out[] = array( 'key' => $key, 'label' => get_the_title( $id ) );
			}
		}
		return $out;
	}

	/**
	 * Drops any key that doesn't resolve to a real ingredient term — item 16:
	 * selection is validated against the real dictionary, never trusted as
	 * free text passed straight into a query.
	 */
	public function validate_keys( array $ingredient_keys ) {
		$valid = array();
		foreach ( array_unique( array_map( 'sanitize_title', $ingredient_keys ) ) as $key ) {
			if ( $key && Atlas_Chuti_Ingredient_Sync::find_term_id_by_key( $key ) ) {
				$valid[] = $key;
			}
		}
		return $valid;
	}

	/**
	 * item 17: for each candidate recipe, a real matched/missing count from the
	 * recipe's own `atlas_ingredient_tax` terms — never a fabricated
	 * percentage, never a pantry-staple/optional distinction the data model
	 * doesn't actually have (item 17's own "pokud ji nemá, nevymýšlej").
	 *
	 * Returns a list ordered by "most matched, fewest missing" of:
	 * ['recipe'=>WP_Post, 'matched_count'=>int, 'total_count'=>int,
	 *  'missing_labels'=>string[]].
	 */
	public function find_matches( array $ingredient_keys, $locale = null, $limit = 20 ) {
		$locale         = $locale ?: Atlas_Chuti_I18N::current_locale();
		$ingredient_keys = $this->validate_keys( $ingredient_keys );
		if ( ! $ingredient_keys ) {
			return array();
		}

		$selected_term_ids = array();
		foreach ( $ingredient_keys as $key ) {
			$term_id = Atlas_Chuti_Ingredient_Sync::find_term_id_by_key( $key );
			if ( $term_id ) {
				$selected_term_ids[ $term_id ] = $key;
			}
		}
		if ( ! $selected_term_ids ) {
			return array();
		}

		$candidates = get_posts(
			array(
				'post_type'      => 'atlas_recipe',
				'post_status'    => 'publish',
				'posts_per_page' => 60, // bounded pool (item 42) — a "have at least one selected ingredient" pre-filter, refined below.
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore -- WordPress.DB.SlowDBQuery, same indexed relation class-ingredient-sync.php already maintains.
					array( 'taxonomy' => 'atlas_ingredient_tax', 'field' => 'term_id', 'terms' => array_keys( $selected_term_ids ) ),
				),
			)
		);

		$results = array();
		foreach ( $candidates as $recipe ) {
			$recipe_term_ids = wp_get_post_terms( $recipe->ID, 'atlas_ingredient_tax', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $recipe_term_ids ) || ! $recipe_term_ids ) {
				continue;
			}
			$matched_term_ids = array_intersect( $recipe_term_ids, array_keys( $selected_term_ids ) );
			$missing_term_ids = array_diff( $recipe_term_ids, array_keys( $selected_term_ids ) );

			$results[] = array(
				'recipe'         => $recipe,
				'matched_count'  => count( $matched_term_ids ),
				'total_count'    => count( $recipe_term_ids ),
				'missing_labels' => $this->resolve_term_labels( $missing_term_ids, $locale ),
			);
		}

		usort(
			$results,
			function ( $a, $b ) {
				if ( $a['matched_count'] !== $b['matched_count'] ) {
					return $b['matched_count'] <=> $a['matched_count'];
				}
				return count( $a['missing_labels'] ) <=> count( $b['missing_labels'] );
			}
		);

		return array_slice( $results, 0, $limit );
	}

	private function resolve_term_labels( array $term_ids, $locale ) {
		$labels = array();
		foreach ( $term_ids as $term_id ) {
			$key = get_term_meta( $term_id, 'ingredient_key', true );
			if ( ! $key ) {
				continue;
			}
			$post = Atlas_Chuti_I18N::find_ingredient_by_key( $key, $locale );
			if ( $post ) {
				$labels[] = get_the_title( $post );
			}
		}
		return $labels;
	}
}
