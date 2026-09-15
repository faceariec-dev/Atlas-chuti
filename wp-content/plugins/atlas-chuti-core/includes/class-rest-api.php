<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5, item 26: the one write/read API surface for every interaction this step
 * adds (audit found zero prior AJAX/REST precedent in this project — see the Step
 * 5 report's audit section — so this is a fresh, deliberately narrow namespace,
 * not a generic catch-all). Every logged-in-only route requires BOTH a real WP
 * session (`is_user_logged_in()`) AND a valid `X-WP-Nonce` (WP core's own REST
 * cookie-auth CSRF check, via the `restNonce` this project localizes into
 * `AtlasChutiUser` — see functions.php). The one genuinely public route (anonymous
 * rating) still requires that same nonce, checked explicitly in its own callback,
 * as a CSRF guard even though no login is required (item 17).
 */
class Atlas_Chuti_REST_API {

	const NAMESPACE_ = 'atlas-chuti/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_state' ),
				'permission_callback' => array( $this, 'require_login' ),
				'args'                => array(
					'type' => array( 'required' => true ),
					'key'  => array( 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/state/toggle',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'toggle_state' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/rating',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_rating' ),
					'permission_callback' => array( $this, 'require_public_nonce' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'submit_rating' ),
					'permission_callback' => array( $this, 'require_public_nonce' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/passport/merge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'merge_passport' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/photos',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_photo' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/photos/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_photo' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_,
			'/photos/(?P<id>\d+)/moderate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'moderate_photo' ),
				'permission_callback' => array( $this, 'require_moderation_capability' ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permission callbacks
	// ---------------------------------------------------------------------

	public function require_login() {
		return is_user_logged_in();
	}

	public function require_moderation_capability() {
		return current_user_can( Atlas_Chuti_Photos::MODERATE_CAPABILITY );
	}

	/**
	 * The nonce this project's own pages localize (see functions.php's
	 * `AtlasChutiUser.restNonce`) — required even for the logged-out-accessible
	 * rating routes, as a CSRF guard (item 17: "nonce / same-site protection").
	 * `wp_verify_nonce()` works the same whether the current user is 0 (guest) or a
	 * real ID, so this is meaningful even without a login.
	 */
	public function require_public_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	// ---------------------------------------------------------------------
	// Favorite / cooked / tasted state
	// ---------------------------------------------------------------------

	private function sanitize_subject_type( $type ) {
		return in_array( $type, Atlas_Chuti_User_State::VALID_TYPES, true ) ? $type : '';
	}

	private function sanitize_state_for_type( $type, $state ) {
		$allowed = Atlas_Chuti_User_State::TYPE_RECIPE === $type
			? array( Atlas_Chuti_User_State::STATE_FAVORITE, Atlas_Chuti_User_State::STATE_COOKED )
			: array( Atlas_Chuti_User_State::STATE_TASTED );
		return in_array( $state, $allowed, true ) ? $state : '';
	}

	public function get_state( WP_REST_Request $request ) {
		$type = $this->sanitize_subject_type( $request->get_param( 'type' ) );
		$key  = $this->sanitize_subject_key( $type, $request->get_param( 'key' ) );
		if ( ! $type || ! $key ) {
			return new WP_Error( 'atlas_bad_request', __( 'Neplatný požadavek.', 'atlas-chuti' ), array( 'status' => 400 ) );
		}
		$user_id = get_current_user_id();
		$service = Atlas_Chuti_User_State::instance();
		$out     = array();
		foreach ( $this->states_for_type( $type ) as $state ) {
			$out[ $state ] = $service->is_set( $user_id, $type, $key, $state );
		}
		return rest_ensure_response( $out );
	}

	private function states_for_type( $type ) {
		return Atlas_Chuti_User_State::TYPE_RECIPE === $type
			? array( Atlas_Chuti_User_State::STATE_FAVORITE, Atlas_Chuti_User_State::STATE_COOKED )
			: array( Atlas_Chuti_User_State::STATE_TASTED );
	}

	private function sanitize_subject_key( $type, $key ) {
		$key = (string) $key;
		if ( Atlas_Chuti_User_State::TYPE_COUNTRY === $type ) {
			$key = strtoupper( sanitize_text_field( $key ) );
			return preg_match( '/^[A-Z]{2,3}$/', $key ) ? $key : '';
		}
		return sanitize_title( $key );
	}

	public function toggle_state( WP_REST_Request $request ) {
		$type  = $this->sanitize_subject_type( $request->get_param( 'type' ) );
		$key   = $this->sanitize_subject_key( $type, $request->get_param( 'key' ) );
		$state = $type ? $this->sanitize_state_for_type( $type, $request->get_param( 'state' ) ) : '';
		if ( ! $type || ! $key || ! $state ) {
			return new WP_Error( 'atlas_bad_request', __( 'Neplatný požadavek.', 'atlas-chuti' ), array( 'status' => 400 ) );
		}
		// The concept must be real (item 11 — never let a client invent an arbitrary
		// recipe_key/ISO and silently create orphaned rows).
		if ( ! $this->subject_exists( $type, $key ) ) {
			return new WP_Error( 'atlas_not_found', __( 'Nenalezeno.', 'atlas-chuti' ), array( 'status' => 404 ) );
		}
		$now_set = Atlas_Chuti_User_State::instance()->toggle( get_current_user_id(), $type, $key, $state );
		return rest_ensure_response( array( 'state' => $now_set ) );
	}

	private function subject_exists( $type, $key ) {
		if ( Atlas_Chuti_User_State::TYPE_COUNTRY === $type ) {
			foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $locale ) {
				if ( Atlas_Chuti_I18N::find_country_by_iso( $key, $locale ) ) {
					return true;
				}
			}
			return false;
		}
		foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $locale ) {
			if ( Atlas_Chuti_I18N::find_by_recipe_key( $key, $locale ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------
	// Ratings
	// ---------------------------------------------------------------------

	public function get_rating( WP_REST_Request $request ) {
		$recipe_key = sanitize_title( $request->get_param( 'recipe_key' ) );
		if ( ! $recipe_key ) {
			return new WP_Error( 'atlas_bad_request', __( 'Neplatný požadavek.', 'atlas-chuti' ), array( 'status' => 400 ) );
		}
		$ratings   = Atlas_Chuti_Ratings::instance();
		$aggregate = $ratings->get_aggregate( $recipe_key );
		$mine      = null;
		if ( is_user_logged_in() ) {
			$mine = $ratings->get_user_rating( $recipe_key, get_current_user_id() );
		} elseif ( ! empty( $_COOKIE[ Atlas_Chuti_Ratings::COOKIE_NAME ] ) ) {
			$mine = $ratings->get_anonymous_rating( $recipe_key, $ratings->hash_token( $_COOKIE[ Atlas_Chuti_Ratings::COOKIE_NAME ] ) );
		}
		return rest_ensure_response( array( 'aggregate' => $aggregate, 'mine' => $mine ) );
	}

	public function submit_rating( WP_REST_Request $request ) {
		$recipe_key = sanitize_title( $request->get_param( 'recipe_key' ) );
		$rating     = $request->get_param( 'rating' );
		$ratings    = Atlas_Chuti_Ratings::instance();

		if ( ! $recipe_key || ! $this->subject_exists( Atlas_Chuti_User_State::TYPE_RECIPE, $recipe_key ) ) {
			return new WP_Error( 'atlas_not_found', __( 'Recept nebyl nalezen.', 'atlas-chuti' ), array( 'status' => 404 ) );
		}
		if ( ! $ratings->is_valid_rating( $rating ) ) {
			return new WP_Error( 'atlas_invalid_rating', __( 'Hodnocení musí být 1 až 5.', 'atlas-chuti' ), array( 'status' => 400 ) );
		}

		if ( is_user_logged_in() ) {
			$ratings->submit_registered_rating( $recipe_key, get_current_user_id(), $rating );
			$mine = (int) $rating;
		} else {
			$identity_hash = hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) . 'rating' . wp_salt( 'auth' ) );
			if ( $ratings->is_rate_limited( $identity_hash ) ) {
				return new WP_Error( 'atlas_rate_limited', __( 'Příliš mnoho pokusů, zkuste to prosím za chvíli.', 'atlas-chuti' ), array( 'status' => 429 ) );
			}
			$ratings->mark_rate_limited( $identity_hash );
			$token = $ratings->get_or_create_anon_token();
			$ratings->submit_anonymous_rating( $recipe_key, $ratings->hash_token( $token ), $rating );
			$mine = (int) $rating;
		}

		return rest_ensure_response( array( 'mine' => $mine, 'aggregate' => $ratings->get_aggregate( $recipe_key ) ) );
	}

	// ---------------------------------------------------------------------
	// Passport merge (item 15)
	// ---------------------------------------------------------------------

	/**
	 * Body: { recipes: { "<recipe_key_or_slug>": {...} }, countries: { "<iso_or_slug>": {...} } }
	 * — the exact shape AtlasPassport's localStorage objects already use. Every key
	 * is re-validated against a REAL recipe_key/ISO before anything is written
	 * (item 15: "pouze stabilní keys") — an entry whose key doesn't resolve to a
	 * real post in any supported locale is silently skipped, never trusted blindly
	 * from client-supplied JSON.
	 */
	public function merge_passport( WP_REST_Request $request ) {
		$body      = $request->get_json_params();
		$recipes   = is_array( $body['recipes'] ?? null ) ? $body['recipes'] : array();
		$countries = is_array( $body['countries'] ?? null ) ? $body['countries'] : array();

		$entries = array();
		foreach ( array_keys( $recipes ) as $raw_key ) {
			$key = sanitize_title( $raw_key );
			if ( $key && $this->subject_exists( Atlas_Chuti_User_State::TYPE_RECIPE, $key ) ) {
				$entries[] = array( Atlas_Chuti_User_State::TYPE_RECIPE, $key, Atlas_Chuti_User_State::STATE_COOKED );
			}
		}
		foreach ( array_keys( $countries ) as $raw_iso ) {
			$iso = strtoupper( sanitize_text_field( $raw_iso ) );
			if ( preg_match( '/^[A-Z]{2,3}$/', $iso ) && $this->subject_exists( Atlas_Chuti_User_State::TYPE_COUNTRY, $iso ) ) {
				$entries[] = array( Atlas_Chuti_User_State::TYPE_COUNTRY, $iso, Atlas_Chuti_User_State::STATE_TASTED );
			}
		}

		$merged = Atlas_Chuti_User_State::instance()->merge_many( get_current_user_id(), $entries );
		return rest_ensure_response( array( 'merged' => $merged, 'considered' => count( $entries ) ) );
	}

	// ---------------------------------------------------------------------
	// Photos
	// ---------------------------------------------------------------------

	public function upload_photo( WP_REST_Request $request ) {
		$recipe_key = sanitize_title( $request->get_param( 'recipe_key' ) );
		$files       = $request->get_file_params();
		if ( empty( $files['photo'] ) ) {
			return new WP_Error( 'atlas_photo_no_file', __( 'Žádný soubor nebyl odeslán.', 'atlas-chuti' ), array( 'status' => 400 ) );
		}
		$result = Atlas_Chuti_Photos::instance()->upload( get_current_user_id(), $recipe_key, $files['photo'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return rest_ensure_response( array( 'id' => $result, 'status' => Atlas_Chuti_Photos::STATUS_PENDING ) );
	}

	public function delete_photo( WP_REST_Request $request ) {
		$deleted = Atlas_Chuti_Photos::instance()->delete_own( (int) $request['id'], get_current_user_id() );
		if ( ! $deleted ) {
			return new WP_Error( 'atlas_not_found', __( 'Fotografie nebyla nalezena.', 'atlas-chuti' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function moderate_photo( WP_REST_Request $request ) {
		$action = $request->get_param( 'action' );
		$photos = Atlas_Chuti_Photos::instance();
		if ( 'approve' === $action ) {
			$ok = $photos->approve( (int) $request['id'], get_current_user_id() );
		} elseif ( 'reject' === $action ) {
			$ok = $photos->reject( (int) $request['id'], get_current_user_id() );
		} else {
			return new WP_Error( 'atlas_bad_request', __( 'Neplatná akce.', 'atlas-chuti' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => (bool) $ok ) );
	}
}
