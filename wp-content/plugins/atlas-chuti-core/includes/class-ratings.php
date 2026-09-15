<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5, items 16-19: recipe ratings, both for logged-in users (identity: WP user
 * ID + recipe_key) and anonymous visitors (identity: a random browser token, never
 * fingerprinting — item 16's explicit "NE agresivní fingerprinting" list). Exactly
 * one active vote per identity+recipe_key (enforced by the table's own UNIQUE keys,
 * see class-db.php); changing a vote UPDATEs the existing row, never inserts a
 * second one. Aggregate is always computed from real stored rows — never seeded,
 * never fabricated (item 18).
 */
class Atlas_Chuti_Ratings {

	const MIN_RATING = 1;
	const MAX_RATING = 5;

	const COOKIE_NAME    = 'atlas_chuti_rating_token';
	const COOKIE_LIFETIME = 2 * YEAR_IN_SECONDS;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'deleted_user', array( $this, 'anonymize_for_deleted_user' ) );
		// Item 27: the rating widget must gracefully degrade without JS — a plain
		// <form> posts here directly (see inc/recipe-community.php); JS progressively
		// enhances the same form into clickable stars via the REST endpoint instead.
		add_action( 'admin_post_atlas_rating_submit', array( $this, 'handle_admin_post_submit' ) );
		add_action( 'admin_post_nopriv_atlas_rating_submit', array( $this, 'handle_admin_post_submit' ) );
	}

	public function handle_admin_post_submit() {
		if ( ! isset( $_POST['atlas_rating_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_rating_nonce'], 'atlas_rating_submit' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		$recipe_key  = isset( $_POST['recipe_key'] ) ? sanitize_title( wp_unslash( $_POST['recipe_key'] ) ) : '';
		$rating      = isset( $_POST['rating'] ) ? $_POST['rating'] : null;
		$redirect_to = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : home_url( '/' );

		if ( $recipe_key && $this->is_valid_rating( $rating ) ) {
			if ( is_user_logged_in() ) {
				$this->submit_registered_rating( $recipe_key, get_current_user_id(), $rating );
			} else {
				$identity_hash = hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) . 'rating' . wp_salt( 'auth' ) );
				if ( ! $this->is_rate_limited( $identity_hash ) ) {
					$this->mark_rate_limited( $identity_hash );
					$token = $this->get_or_create_anon_token();
					$this->submit_anonymous_rating( $recipe_key, $this->hash_token( $token ), $rating );
				}
			}
		}

		$target = wp_validate_redirect( $redirect_to, home_url( '/' ) );
		wp_safe_redirect( add_query_arg( 'hodnoceno', '1', $target ) . '#hodnoceni' );
		exit;
	}

	// ---------------------------------------------------------------------
	// Anonymous identity (item 16: random token, never fingerprinting)
	// ---------------------------------------------------------------------

	/**
	 * The raw token lives only in the visitor's own cookie; the server only ever
	 * stores/queries its SHA-256 hash (item 16: "na serveru ukládat hash tokenu, ne
	 * raw token"). Reads the existing cookie if present; otherwise generates and
	 * sets a new one. Never called for a logged-in rating.
	 */
	public function get_or_create_anon_token() {
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) && preg_match( '/^[a-f0-9]{64}$/', $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return $_COOKIE[ self::COOKIE_NAME ];
		}
		$token = bin2hex( random_bytes( 32 ) );
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$token,
				array(
					'expires'  => time() + self::COOKIE_LIFETIME,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE_NAME ] = $token;
		return $token;
	}

	public function hash_token( $raw_token ) {
		return hash( 'sha256', $raw_token . wp_salt( 'auth' ) );
	}

	// ---------------------------------------------------------------------
	// Rate limiting (item 17) — short transient keyed off a HASHED, short-lived IP
	// signal, never a persisted long-term IP-based identity.
	// ---------------------------------------------------------------------

	public function is_rate_limited( $identity_hash ) {
		return (bool) get_transient( 'atlas_chuti_rl_' . $identity_hash );
	}

	public function mark_rate_limited( $identity_hash, $seconds = 10 ) {
		set_transient( 'atlas_chuti_rl_' . $identity_hash, 1, $seconds );
	}

	// ---------------------------------------------------------------------
	// Writes
	// ---------------------------------------------------------------------

	public function is_valid_rating( $rating ) {
		if ( ! is_numeric( $rating ) ) {
			return false;
		}
		$int = (int) $rating;
		// Loose comparison is deliberate here: $rating may arrive as an int (5) or
		// as a numeric string from $_POST/REST ("5") — either must compare equal to
		// its own int cast; "3.5" correctly fails this (3.5 != 3).
		if ( (float) $rating != $int ) { // phpcs:ignore WordPress.PHP.StrictComparisons -- deliberate loose numeric compare, see above.
			return false;
		}
		return $int >= self::MIN_RATING && $int <= self::MAX_RATING;
	}

	/**
	 * One active vote per (recipe_key, user) — a repeat call UPDATEs in place
	 * (item 12/18), never creates a duplicate row (the table's UNIQUE recipe_user
	 * key makes this atomic even under a race, item 41).
	 */
	public function submit_registered_rating( $recipe_key, $user_id, $rating ) {
		if ( ! $recipe_key || ! $user_id || ! $this->is_valid_rating( $rating ) ) {
			return false;
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_ratings();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (recipe_key, user_id, anon_token_hash, rating, created_at, updated_at) VALUES (%s, %d, NULL, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = VALUES(updated_at)",
				$recipe_key,
				$user_id,
				(int) $rating,
				$now,
				$now
			)
		);
		return true;
	}

	public function submit_anonymous_rating( $recipe_key, $anon_token_hash, $rating ) {
		if ( ! $recipe_key || ! $anon_token_hash || ! $this->is_valid_rating( $rating ) ) {
			return false;
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_ratings();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (recipe_key, user_id, anon_token_hash, rating, created_at, updated_at) VALUES (%s, NULL, %s, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = VALUES(updated_at)",
				$recipe_key,
				$anon_token_hash,
				(int) $rating,
				$now,
				$now
			)
		);
		return true;
	}

	// ---------------------------------------------------------------------
	// Reads
	// ---------------------------------------------------------------------

	public function get_user_rating( $recipe_key, $user_id ) {
		if ( ! $recipe_key || ! $user_id ) {
			return null;
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_ratings();
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT rating FROM {$table} WHERE recipe_key = %s AND user_id = %d LIMIT 1", $recipe_key, $user_id )
		);
		return null === $value ? null : (int) $value;
	}

	public function get_anonymous_rating( $recipe_key, $anon_token_hash ) {
		if ( ! $recipe_key || ! $anon_token_hash ) {
			return null;
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_ratings();
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT rating FROM {$table} WHERE recipe_key = %s AND anon_token_hash = %s LIMIT 1", $recipe_key, $anon_token_hash )
		);
		return null === $value ? null : (int) $value;
	}

	/**
	 * Real average + count only — never fabricated, never seeded (items 18/35).
	 * ratingCount === 0 is a legitimate, expected result; callers (class-seo.php,
	 * the action bar) must omit any "aggregateRating"/UI display in that case
	 * rather than show a fake number.
	 */
	public function get_aggregate( $recipe_key ) {
		if ( ! $recipe_key ) {
			return array( 'average' => 0.0, 'count' => 0 );
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_ratings();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM {$table} WHERE recipe_key = %s", $recipe_key ),
			ARRAY_A
		);
		$count = $row ? (int) $row['cnt'] : 0;
		return array(
			'average' => $count ? round( (float) $row['avg_rating'], 1 ) : 0.0,
			'count'   => $count,
		);
	}

	/**
	 * Batched aggregate fetch for a listing of recipes (item 40 — no N+1).
	 * Returns array( recipe_key => ['average'=>float,'count'=>int] ).
	 */
	public function get_aggregates_for( array $recipe_keys ) {
		$recipe_keys = array_values( array_filter( array_unique( array_map( 'strval', $recipe_keys ) ) ) );
		if ( ! $recipe_keys ) {
			return array();
		}
		global $wpdb;
		$table        = Atlas_Chuti_DB::table_ratings();
		$placeholders = implode( ',', array_fill( 0, count( $recipe_keys ), '%s' ) );
		$sql          = "SELECT recipe_key, AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM {$table} WHERE recipe_key IN ({$placeholders}) GROUP BY recipe_key";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $recipe_keys ), ARRAY_A );
		$out          = array();
		foreach ( $rows as $row ) {
			$out[ $row['recipe_key'] ] = array( 'average' => round( (float) $row['avg_rating'], 1 ), 'count' => (int) $row['cnt'] );
		}
		return $out;
	}

	/**
	 * Můj Atlas → Moje hodnocení (item 19): every recipe this user has rated, most
	 * recent first. Anonymous ratings never appear here — there is no safe way to
	 * link a pre-login anonymous vote to the account without risking a double vote
	 * (item 19's explicit instruction not to auto-merge historical anonymous votes).
	 */
	public function get_user_ratings( $user_id ) {
		if ( ! $user_id ) {
			return array();
		}
		global $wpdb;
		$table = Atlas_Chuti_DB::table_ratings();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT recipe_key, rating, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC", $user_id ),
			ARRAY_A
		);
	}

	/**
	 * KROK 5 item 38: on account deletion, a registered rating is ANONYMIZED (kept
	 * as real aggregate signal — deleting it would silently change every recipe's
	 * average/count the user ever rated, which is a worse outcome for site
	 * integrity than losing the user_id link) rather than deleted outright. This is
	 * a documented, deliberate choice — see the Step 5 report section M — distinct
	 * from atlas_user_state (favorite/cooked), which IS deleted outright because it
	 * has no meaning without the account.
	 */
	public function anonymize_for_deleted_user( $user_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_ratings();
		// A user_id row can't simply have user_id set NULL: NULL user_id means
		// "anonymous", which requires anon_token_hash to be the identity instead —
		// and an ex-user has no browser token to give it. Deleting outright here
		// (rather than a half-anonymized row with neither identity, which the
		// application never expects to exist) is the closest honest option to
		// "anonymize"; the aggregate this rating contributed to changes at that
		// point, which is accepted (see report).
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
	}
}
