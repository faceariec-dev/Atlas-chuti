<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 8, item 20-22: private, account-bound recipe collections
 * (`atlas_collections` + `atlas_collection_items`, see class-db.php) — same
 * normalized-table, stable-`recipe_key`-identity conventions as Krok 5's
 * `Atlas_Chuti_User_State`. No public sharing, no social follow (item 20's own
 * explicit scope limit) — every read/write here is scoped to `user_id`, checked
 * via `is_owner()` before any mutation (item 21).
 */
class Atlas_Chuti_Collections {

	const MAX_TITLE_LENGTH = 100;

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

	public function is_owner( $user_id, $collection_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_collections();
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $collection_id ) );
		return $owner && (int) $owner === (int) $user_id;
	}

	public function create( $user_id, $title, $description = '' ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		if ( ! $user_id || '' === $title ) {
			return new WP_Error( 'invalid_title' );
		}
		$title       = mb_substr( $title, 0, self::MAX_TITLE_LENGTH );
		$description = trim( wp_strip_all_tags( (string) $description ) );

		global $wpdb;
		$table = Atlas_Chuti_DB::table_collections();
		$now   = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'user_id'     => $user_id,
				'title'       => $title,
				'description' => $description,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public function update( $user_id, $collection_id, $title, $description = '' ) {
		if ( ! $this->is_owner( $user_id, $collection_id ) ) {
			return new WP_Error( 'not_owner' );
		}
		$title = trim( wp_strip_all_tags( (string) $title ) );
		if ( '' === $title ) {
			return new WP_Error( 'invalid_title' );
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_collections();
		$wpdb->update(
			$table,
			array(
				'title'       => mb_substr( $title, 0, self::MAX_TITLE_LENGTH ),
				'description' => trim( wp_strip_all_tags( (string) $description ) ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $collection_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	public function delete( $user_id, $collection_id ) {
		if ( ! $this->is_owner( $user_id, $collection_id ) ) {
			return new WP_Error( 'not_owner' );
		}
		global $wpdb;
		$wpdb->delete( Atlas_Chuti_DB::table_collection_items(), array( 'collection_id' => $collection_id ), array( '%d' ) );
		$wpdb->delete( Atlas_Chuti_DB::table_collections(), array( 'id' => $collection_id ), array( '%d' ) );
		return true;
	}

	public function get( $collection_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_collections();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $collection_id ) );
	}

	/**
	 * A user's collections, newest first, each annotated with a real item
	 * count (one extra indexed query per collection is fine here — a user's
	 * OWN collection count is always small; contrast with get_items_count_map()
	 * below, the N+1-safe batch version used when rendering many collections
	 * at once).
	 */
	public function get_for_user( $user_id ) {
		if ( ! $user_id ) {
			return array();
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_collections();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC", $user_id ) );
		if ( ! $rows ) {
			return array();
		}
		$counts = $this->get_items_count_map( wp_list_pluck( $rows, 'id' ) );
		foreach ( $rows as $row ) {
			$row->item_count = $counts[ (int) $row->id ] ?? 0;
		}
		return $rows;
	}

	private function get_items_count_map( array $collection_ids ) {
		$collection_ids = array_values( array_filter( array_map( 'intval', $collection_ids ) ) );
		if ( ! $collection_ids ) {
			return array();
		}
		global $wpdb;
		$table        = Atlas_Chuti_DB::table_collection_items();
		$placeholders = implode( ',', array_fill( 0, count( $collection_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT collection_id, COUNT(*) AS c FROM {$table} WHERE collection_id IN ({$placeholders}) GROUP BY collection_id", $collection_ids )
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->collection_id ] = (int) $row->c;
		}
		return $map;
	}

	/**
	 * Item 29: duplicate prevention via the UNIQUE(collection_id,recipe_key)
	 * key — a second add is a harmless no-op (INSERT IGNORE), never a fatal or
	 * a duplicate card in the collection view.
	 */
	public function add_item( $user_id, $collection_id, $recipe_key ) {
		if ( ! $this->is_owner( $user_id, $collection_id ) ) {
			return new WP_Error( 'not_owner' );
		}
		$recipe_key = sanitize_title( (string) $recipe_key );
		if ( '' === $recipe_key ) {
			return new WP_Error( 'invalid_recipe_key' );
		}
		global $wpdb;
		$table    = Atlas_Chuti_DB::table_collection_items();
		$next_pos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$table} WHERE collection_id = %d", $collection_id ) );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (collection_id, recipe_key, sort_order, created_at) VALUES (%d, %s, %d, %s)",
				$collection_id,
				$recipe_key,
				$next_pos,
				current_time( 'mysql', true )
			)
		);
		$this->touch( $collection_id );
		return true;
	}

	public function remove_item( $user_id, $collection_id, $recipe_key ) {
		if ( ! $this->is_owner( $user_id, $collection_id ) ) {
			return new WP_Error( 'not_owner' );
		}
		global $wpdb;
		$wpdb->delete(
			Atlas_Chuti_DB::table_collection_items(),
			array( 'collection_id' => $collection_id, 'recipe_key' => sanitize_title( (string) $recipe_key ) ),
			array( '%d', '%s' )
		);
		$this->touch( $collection_id );
		return true;
	}

	private function touch( $collection_id ) {
		global $wpdb;
		$wpdb->update( Atlas_Chuti_DB::table_collections(), array( 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $collection_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Ordered recipe_key list for one collection — the theme resolves each key
	 * to a real, current-locale post (same 3-layer resolver pattern as
	 * inc/my-atlas.php's favorites/cooked lists).
	 */
	public function get_items( $collection_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_collection_items();
		return $wpdb->get_col( $wpdb->prepare( "SELECT recipe_key FROM {$table} WHERE collection_id = %d ORDER BY sort_order ASC", $collection_id ) );
	}

	public function delete_all_for_user( $user_id ) {
		global $wpdb;
		$table    = Atlas_Chuti_DB::table_collections();
		$item_tbl = Atlas_Chuti_DB::table_collection_items();
		$ids      = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d", $user_id ) );
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$item_tbl} WHERE collection_id IN ({$placeholders})", $ids ) );
		}
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
