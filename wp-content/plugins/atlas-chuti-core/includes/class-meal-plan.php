<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 8, item 26-28: a basic weekly meal planner (`atlas_meal_plan_items`, see
 * class-db.php) — date + meal slot + `recipe_key` + optional servings
 * override. No nutrition planner (item 26's own explicit scope limit).
 * `meal_slot` is a small, closed, stable-key vocabulary defined here (not a
 * taxonomy — item 26 explicitly allows "jednoduchého stabilního key" for this,
 * and four fixed meal-of-day slots have no reason to grow the way e.g.
 * atlas_recipe_tag does).
 */
class Atlas_Chuti_Meal_Plan {

	const SLOT_BREAKFAST = 'breakfast';
	const SLOT_LUNCH     = 'lunch';
	const SLOT_DINNER    = 'dinner';
	const SLOT_SNACK     = 'snack';

	const VALID_SLOTS = array( self::SLOT_BREAKFAST, self::SLOT_LUNCH, self::SLOT_DINNER, self::SLOT_SNACK );

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

	public function is_valid_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', (string) $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	public function is_valid_slot( $slot ) {
		return in_array( $slot, self::VALID_SLOTS, true );
	}

	private function is_owner( $user_id, $item_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_meal_plan_items();
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $item_id ) );
		return $owner && (int) $owner === (int) $user_id;
	}

	public function add_item( $user_id, $plan_date, $meal_slot, $recipe_key, $servings_override = null ) {
		if ( ! $user_id || ! $this->is_valid_date( $plan_date ) || ! $this->is_valid_slot( $meal_slot ) ) {
			return new WP_Error( 'invalid_plan_item' );
		}
		$recipe_key = sanitize_title( (string) $recipe_key );
		if ( '' === $recipe_key ) {
			return new WP_Error( 'invalid_recipe_key' );
		}
		$servings_override = ( null !== $servings_override && is_numeric( $servings_override ) && $servings_override > 0 )
			? (int) $servings_override
			: null;

		global $wpdb;
		$table = Atlas_Chuti_DB::table_meal_plan_items();
		$data  = array(
			'user_id'    => $user_id,
			'plan_date'  => $plan_date,
			'meal_slot'  => $meal_slot,
			'recipe_key' => $recipe_key,
			'created_at' => current_time( 'mysql', true ),
		);
		$format = array( '%d', '%s', '%s', '%s', '%s' );
		if ( null !== $servings_override ) {
			$data['servings_override'] = $servings_override;
			$format[]                   = '%d';
		}
		// INSERT IGNORE via a raw query (same reasoning as class-user-state.php):
		// the UNIQUE(user_id,plan_date,meal_slot,recipe_key) key makes adding the
		// exact same recipe to the exact same date/slot twice a harmless no-op.
		$columns      = implode( ', ', array_keys( $data ) );
		$placeholders = implode( ', ', $format );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} ({$columns}) VALUES ({$placeholders})", array_values( $data ) ) );
		return (int) $wpdb->insert_id;
	}

	public function update_item( $user_id, $item_id, $servings_override ) {
		if ( ! $this->is_owner( $user_id, $item_id ) ) {
			return new WP_Error( 'not_owner' );
		}
		$servings_override = ( is_numeric( $servings_override ) && $servings_override > 0 ) ? (int) $servings_override : null;
		global $wpdb;
		$wpdb->update(
			Atlas_Chuti_DB::table_meal_plan_items(),
			array( 'servings_override' => $servings_override ),
			array( 'id' => $item_id ),
			array( '%d' ),
			array( '%d' )
		);
		return true;
	}

	public function remove_item( $user_id, $item_id ) {
		if ( ! $this->is_owner( $user_id, $item_id ) ) {
			return new WP_Error( 'not_owner' );
		}
		global $wpdb;
		$wpdb->delete( Atlas_Chuti_DB::table_meal_plan_items(), array( 'id' => $item_id ), array( '%d' ) );
		return true;
	}

	/**
	 * A user's plan for one date range (e.g. the current week), ordered for
	 * direct day-card rendering.
	 */
	public function get_for_range( $user_id, $start_date, $end_date ) {
		if ( ! $user_id || ! $this->is_valid_date( $start_date ) || ! $this->is_valid_date( $end_date ) ) {
			return array();
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_meal_plan_items();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND plan_date BETWEEN %s AND %s ORDER BY plan_date ASC, meal_slot ASC",
				$user_id,
				$start_date,
				$end_date
			)
		);
	}

	/**
	 * item 28: adds every planned recipe in a date range to the shopping
	 * list, respecting each item's own servings_override (or the recipe's
	 * own default when no override was set) — delegates the actual
	 * ingredient-scaling/merge work to Atlas_Chuti_Shopping_List::
	 * add_from_recipe(), the SAME method the single-recipe "add to shopping
	 * list" action uses, so the two paths can never diverge.
	 */
	public function add_range_to_shopping_list( $user_id, $start_date, $end_date ) {
		$items = $this->get_for_range( $user_id, $start_date, $end_date );
		$added = 0;
		foreach ( $items as $item ) {
			$result = Atlas_Chuti_Shopping_List::instance()->add_from_recipe( $user_id, $item->recipe_key, $item->servings_override );
			if ( ! is_wp_error( $result ) ) {
				$added += (int) $result;
			}
		}
		return $added;
	}

	public function get_item( $item_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_meal_plan_items();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $item_id ) );
	}

	public function delete_all_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( Atlas_Chuti_DB::table_meal_plan_items(), array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
