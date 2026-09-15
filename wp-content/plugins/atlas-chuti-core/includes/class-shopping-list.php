<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 8, item 23-25: a private, account-bound shopping list
 * (`atlas_shopping_list_items`, see class-db.php). Logged-in only in this
 * first version — the Step 7/audit-established "decide after audit, document
 * in report" call (item 25) landed on logged-in-only because an anonymous
 * localStorage shopping list that ALSO needs safe cross-device unit-merge
 * logic would duplicate this entire class client-side for a feature most
 * users reach from an already-authenticated Můj Atlas flow (adding a recipe's
 * ingredients while planning); see the Step 8 report, section H, for the full
 * reasoning.
 *
 * Merge rule (item 24) is deliberately conservative: two rows merge into one
 * SUMMED quantity only when they share the SAME ingredient_key AND the SAME
 * canonical unit_key AND both have a real parsed numeric quantity_value. Any
 * other combination (different unit_key, a NULL unit_key, a non-numeric
 * quantity like "podle chuti") is kept as its own separate line item — never
 * a risky cross-unit conversion (item 24's own "Nevytvářej riskantní
 * univerzální conversion engine").
 */
class Atlas_Chuti_Shopping_List {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'deleted_user', array( $this, 'delete_all_for_user' ) );
	}

	private function is_owner( $user_id, $item_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_shopping_list_items();
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $item_id ) );
		return $owner && (int) $owner === (int) $user_id;
	}

	/**
	 * $quantity_value: float|null (a real parsed numeric amount, or null for
	 * free-text-only quantities like "podle chuti"). $unit_key: a
	 * Atlas_Chuti_Units canonical key, or null if the unit couldn't be
	 * normalized. Returns the item id (new or merged-into) or WP_Error.
	 */
	public function add_item( $user_id, $ingredient_key, $display_name, $quantity_value, $quantity_text, $unit_key, $source_recipe_key = '' ) {
		$display_name = trim( wp_strip_all_tags( (string) $display_name ) );
		if ( ! $user_id || '' === $display_name ) {
			return new WP_Error( 'invalid_item' );
		}
		$ingredient_key = sanitize_key( (string) $ingredient_key );
		$unit_key       = $unit_key ? sanitize_key( $unit_key ) : null;
		$quantity_value = ( null !== $quantity_value && is_numeric( $quantity_value ) ) ? (float) $quantity_value : null;
		$quantity_text  = mb_substr( trim( wp_strip_all_tags( (string) $quantity_text ) ), 0, 50 );
		$source_recipe_key = $source_recipe_key ? sanitize_title( $source_recipe_key ) : null;

		global $wpdb;
		$table = Atlas_Chuti_DB::table_shopping_list_items();
		$now   = current_time( 'mysql', true );

		if ( $ingredient_key && $unit_key && null !== $quantity_value ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, quantity_value FROM {$table} WHERE user_id = %d AND ingredient_key = %s AND unit_key = %s AND quantity_value IS NOT NULL AND checked = 0 LIMIT 1",
					$user_id,
					$ingredient_key,
					$unit_key
				)
			);
			if ( $existing ) {
				$merged = (float) $existing->quantity_value + $quantity_value;
				$wpdb->update(
					$table,
					array( 'quantity_value' => $merged, 'updated_at' => $now ),
					array( 'id' => $existing->id ),
					array( '%f', '%s' ),
					array( '%d' )
				);
				return (int) $existing->id;
			}
		}

		$data   = array(
			'user_id'           => $user_id,
			'ingredient_key'    => $ingredient_key,
			'display_name'      => $display_name,
			'quantity_text'     => $quantity_text,
			'unit_key'          => $unit_key,
			'source_recipe_key' => $source_recipe_key,
			'checked'           => 0,
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
		// quantity_value is only added when it's a real number — omitted entirely
		// so the column's own NULL default applies, never a coerced "0" (item 52:
		// "invalid quantity/unit" must never silently become a wrong value).
		if ( null !== $quantity_value ) {
			$data['quantity_value'] = $quantity_value;
			$format[]                = '%f';
		}
		$wpdb->insert( $table, $data, $format );
		return (int) $wpdb->insert_id;
	}

	public function set_checked( $user_id, $item_id, $checked ) {
		if ( ! $this->is_owner( $user_id, $item_id ) ) {
			return new WP_Error( 'not_owner' );
		}
		global $wpdb;
		$wpdb->update(
			Atlas_Chuti_DB::table_shopping_list_items(),
			array( 'checked' => $checked ? 1 : 0, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $item_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return true;
	}

	public function remove_item( $user_id, $item_id ) {
		if ( ! $this->is_owner( $user_id, $item_id ) ) {
			return new WP_Error( 'not_owner' );
		}
		global $wpdb;
		$wpdb->delete( Atlas_Chuti_DB::table_shopping_list_items(), array( 'id' => $item_id ), array( '%d' ) );
		return true;
	}

	public function clear_checked( $user_id ) {
		if ( ! $user_id ) {
			return 0;
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_shopping_list_items();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d AND checked = 1", $user_id ) );
	}

	/**
	 * KROK 8, item 28: "Přidat ingredience z receptu/plánu do nákupního
	 * seznamu" — the ONE shared implementation both the recipe action bar and
	 * the meal-planner bridge call. Reuses Atlas_Chuti_Servings' own scaling
	 * (the SAME parser/ratio logic the recipe page's own portion switcher
	 * uses) — never re-derives quantities by hand, and NEVER writes back to
	 * the recipe post itself (item 28: "Nikdy nepřepisuj původní recipe
	 * data" — this only ever reads it).
	 */
	public function add_from_recipe( $user_id, $recipe_key, $servings = null, $locale = null ) {
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();
		$post   = Atlas_Chuti_I18N::find_by_recipe_key( $recipe_key, $locale );
		if ( ! $post ) {
			return new WP_Error( 'recipe_not_found' );
		}
		$ingredients = Atlas_Chuti_Servings::get_scalable_ingredients( $post->ID );
		if ( ! $ingredients ) {
			return new WP_Error( 'no_ingredients' );
		}
		$default_servings = (int) get_post_meta( $post->ID, 'atlas_servings_default', true ) ?: 1;
		$target_servings  = ( $servings && is_numeric( $servings ) && $servings > 0 ) ? (float) $servings : $default_servings;
		$ratio            = $default_servings > 0 ? $target_servings / $default_servings : 1;

		$added = 0;
		foreach ( $ingredients as $ing ) {
			$quantity_value = null;
			$quantity_text  = $ing['quantity'];
			if ( $ing['scalable'] && null !== $ing['base_amount'] ) {
				$quantity_value = round( $ing['base_amount'] * $ratio, 3 );
				$quantity_text  = Atlas_Chuti_Servings::format_amount( $quantity_value );
			}
			$result = $this->add_item(
				$user_id,
				$ing['ingredient_key'],
				$ing['display_name'],
				$quantity_value,
				$quantity_text,
				$ing['unit_key'],
				$recipe_key
			);
			if ( ! is_wp_error( $result ) ) {
				++$added;
			}
		}
		return $added;
	}

	public function get_for_user( $user_id ) {
		if ( ! $user_id ) {
			return array();
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_shopping_list_items();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY checked ASC, created_at DESC", $user_id ) );
	}

	public function delete_all_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( Atlas_Chuti_DB::table_shopping_list_items(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
