<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5: favorite/cooked (recipe, keyed by the stable atlas_recipe_key — item 11 of
 * the brief) and tasted (country, keyed by stable ISO code) — one normalized table
 * (`atlas_user_state`, see class-db.php), not a serialized user_meta blob. Every
 * write here is a pure presence flag (a row exists, or it doesn't) — there is no
 * "better"/"worse" value to accidentally downgrade, which is what makes the
 * Passport localStorage→account merge (see class-rest-api.php) trivially safe: it
 * only ever INSERTs rows that don't already exist, never deletes or overwrites.
 */
class Atlas_Chuti_User_State {

	const TYPE_RECIPE  = 'recipe';
	const TYPE_COUNTRY = 'country';

	const STATE_FAVORITE = 'favorite';
	const STATE_COOKED   = 'cooked';
	const STATE_TASTED   = 'tasted';

	const VALID_TYPES  = array( self::TYPE_RECIPE, self::TYPE_COUNTRY );
	const VALID_STATES = array( self::STATE_FAVORITE, self::STATE_COOKED, self::STATE_TASTED );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'deleted_user', array( $this, 'delete_all_for_user' ) );
		// Item 12: "bez JS může existovat bezpečný POST fallback" — the action bar's
		// Oblíbené/Uvařeno buttons are real <form>-wrapped submits under the hood;
		// JS progressively enhances them into an async toggle via the REST endpoint
		// instead (see assets/js/my-atlas.js).
		add_action( 'admin_post_atlas_state_toggle', array( $this, 'handle_admin_post_toggle' ) );
	}

	public function handle_admin_post_toggle() {
		if ( ! is_user_logged_in() || ! isset( $_POST['atlas_state_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_state_nonce'], 'atlas_state_toggle' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		$subject_type = isset( $_POST['subject_type'] ) ? sanitize_key( wp_unslash( $_POST['subject_type'] ) ) : '';
		$subject_key  = isset( $_POST['subject_key'] ) ? sanitize_text_field( wp_unslash( $_POST['subject_key'] ) ) : '';
		$state        = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$redirect_to  = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : home_url( '/' );

		if ( self::TYPE_RECIPE === $subject_type ) {
			$subject_key = sanitize_title( $subject_key );
		} elseif ( self::TYPE_COUNTRY === $subject_type ) {
			$subject_key = strtoupper( $subject_key );
		}

		if ( $this->is_valid( $subject_type, $state ) && $subject_key ) {
			$this->toggle( get_current_user_id(), $subject_type, $subject_key, $state );
		}

		$target = wp_validate_redirect( $redirect_to, home_url( '/' ) );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Idempotent add/remove (item 12 of the brief) — race-safe against a double
	 * click / two parallel requests (item 41): the final SELECT after the write is
	 * the single source of truth for the returned state, regardless of which of two
	 * concurrent requests actually performed the INSERT (the UNIQUE key on
	 * user_id+subject_type+subject_key+state makes a second concurrent INSERT a
	 * harmless no-op via INSERT IGNORE, never a duplicate row, never a fatal error).
	 *
	 * Returns true when the subject is now marked with $state, false when it was
	 * just removed.
	 */
	public function toggle( $user_id, $subject_type, $subject_key, $state ) {
		if ( ! $this->is_valid( $subject_type, $state ) || ! $user_id || '' === (string) $subject_key ) {
			return false;
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_user_state();

		if ( $this->is_set( $user_id, $subject_type, $subject_key, $state ) ) {
			$wpdb->delete(
				$table,
				array(
					'user_id'      => $user_id,
					'subject_type' => $subject_type,
					'subject_key'  => $subject_key,
					'state'        => $state,
				),
				array( '%d', '%s', '%s', '%s' )
			);
			return false;
		}

		$now = current_time( 'mysql', true );
		// INSERT IGNORE (via a raw query — $wpdb->insert() has no "ignore" mode) so a
		// genuinely simultaneous second request never fatals on the UNIQUE key; the
		// row exists either way, so returning true is correct regardless of which
		// request's INSERT actually landed.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (user_id, subject_type, subject_key, state, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)",
				$user_id,
				$subject_type,
				$subject_key,
				$state,
				$now,
				$now
			)
		);
		return true;
	}

	public function is_set( $user_id, $subject_type, $subject_key, $state ) {
		if ( ! $user_id || '' === (string) $subject_key ) {
			return false;
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_user_state();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND subject_type = %s AND subject_key = %s AND state = %s LIMIT 1",
				$user_id,
				$subject_type,
				$subject_key,
				$state
			)
		);
		return (bool) $found;
	}

	/**
	 * Batched lookup for a whole page's worth of subjects in one query (item 40:
	 * "při vykreslení seznamu 20 favorites neprováděj desítky zbytečných query").
	 * Returns array( "$subject_key" => true ) for every matching row.
	 */
	public function get_set_map( $user_id, $subject_type, array $subject_keys, $state ) {
		$subject_keys = array_values( array_filter( array_unique( array_map( 'strval', $subject_keys ) ) ) );
		if ( ! $user_id || ! $subject_keys ) {
			return array();
		}
		global $wpdb;
		$table        = Atlas_Chuti_DB::table_user_state();
		$placeholders = implode( ',', array_fill( 0, count( $subject_keys ), '%s' ) );
		$sql          = "SELECT subject_key FROM {$table} WHERE user_id = %d AND subject_type = %s AND state = %s AND subject_key IN ({$placeholders})";
		$args         = array_merge( array( $user_id, $subject_type, $state ), $subject_keys );
		$rows         = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		return array_fill_keys( $rows, true );
	}

	/**
	 * All subject keys a user has marked with $state, newest first — the source
	 * for Můj Atlas → Oblíbené / Uvařené / Kulinářský pas listings.
	 */
	public function get_for_user( $user_id, $subject_type, $state ) {
		if ( ! $user_id ) {
			return array();
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_user_state();
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT subject_key FROM {$table} WHERE user_id = %d AND subject_type = %s AND state = %s ORDER BY updated_at DESC",
				$user_id,
				$subject_type,
				$state
			)
		);
	}

	public function count_for_user( $user_id, $subject_type, $state ) {
		if ( ! $user_id ) {
			return 0;
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_user_state();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND subject_type = %s AND state = %s",
				$user_id,
				$subject_type,
				$state
			)
		);
	}

	/**
	 * Bulk, non-destructive merge (KROK 5 item 15: localStorage → account). Each
	 * entry is array($subject_type, $subject_key, $state); invalid shapes are
	 * silently skipped (validated again at the REST layer against real
	 * recipe_key/ISO identities before this is ever called — see
	 * class-rest-api.php). INSERT IGNORE per row: an already-favorited/cooked/tasted
	 * subject is left completely untouched (never "overwritten by worse data" —
	 * there IS no data to compare, presence is the only value), and a genuinely new
	 * row is added. Returns the number of rows actually newly inserted.
	 */
	public function merge_many( $user_id, array $entries ) {
		if ( ! $user_id || ! $entries ) {
			return 0;
		}
		global $wpdb;
		$table   = Atlas_Chuti_DB::table_user_state();
		$now     = current_time( 'mysql', true );
		$inserted = 0;
		foreach ( $entries as $entry ) {
			list( $subject_type, $subject_key, $state ) = $entry;
			if ( ! $this->is_valid( $subject_type, $state ) || '' === (string) $subject_key ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (user_id, subject_type, subject_key, state, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)",
					$user_id,
					$subject_type,
					$subject_key,
					$state,
					$now,
					$now
				)
			);
			if ( 1 === (int) $wpdb->rows_affected ) {
				++$inserted;
			}
		}
		return $inserted;
	}

	private function is_valid( $subject_type, $state ) {
		return in_array( $subject_type, self::VALID_TYPES, true ) && in_array( $state, self::VALID_STATES, true );
	}

	/**
	 * KROK 5 item 38: WordPress user deletion must never leave dangling rows with an
	 * invalid user_id — account-only state (favorite/cooked/tasted) is deleted
	 * outright (it has no meaning without the account; unlike ratings, there is no
	 * anonymized form of "someone favorited this").
	 */
	public function delete_all_for_user( $user_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_user_state();
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
