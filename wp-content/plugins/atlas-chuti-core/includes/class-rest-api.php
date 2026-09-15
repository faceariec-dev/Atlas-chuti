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

		// KROK 8, item 40-41: collections/shopping-list/meal-plan CRUD — same
		// namespace, same require_login()/nonce pattern as every Step 5 route
		// above, never a parallel API.
		register_rest_route( self::NAMESPACE_, '/collections', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_collections' ), 'permission_callback' => array( $this, 'require_login' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_collection' ), 'permission_callback' => array( $this, 'require_login' ) ),
		) );
		register_rest_route( self::NAMESPACE_, '/collections/(?P<id>\d+)', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'update_collection' ), 'permission_callback' => array( $this, 'require_login' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_collection' ), 'permission_callback' => array( $this, 'require_login' ) ),
		) );
		register_rest_route( self::NAMESPACE_, '/collections/(?P<id>\d+)/items', array(
			'methods' => 'POST', 'callback' => array( $this, 'add_collection_item' ), 'permission_callback' => array( $this, 'require_login' ),
		) );
		register_rest_route( self::NAMESPACE_, '/collections/(?P<id>\d+)/items/(?P<recipe_key>[a-z0-9-]+)', array(
			'methods' => 'DELETE', 'callback' => array( $this, 'remove_collection_item' ), 'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NAMESPACE_, '/shopping-list', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_shopping_list' ), 'permission_callback' => array( $this, 'require_login' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'add_shopping_item' ), 'permission_callback' => array( $this, 'require_login' ) ),
		) );
		register_rest_route( self::NAMESPACE_, '/shopping-list/from-recipe', array(
			'methods' => 'POST', 'callback' => array( $this, 'add_shopping_from_recipe' ), 'permission_callback' => array( $this, 'require_login' ),
		) );
		register_rest_route( self::NAMESPACE_, '/shopping-list/(?P<id>\d+)', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'update_shopping_item' ), 'permission_callback' => array( $this, 'require_login' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_shopping_item' ), 'permission_callback' => array( $this, 'require_login' ) ),
		) );
		register_rest_route( self::NAMESPACE_, '/shopping-list/clear-checked', array(
			'methods' => 'POST', 'callback' => array( $this, 'clear_checked_shopping_items' ), 'permission_callback' => array( $this, 'require_login' ),
		) );

		register_rest_route( self::NAMESPACE_, '/meal-plan', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_meal_plan' ), 'permission_callback' => array( $this, 'require_login' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'add_meal_plan_item' ), 'permission_callback' => array( $this, 'require_login' ) ),
		) );
		register_rest_route( self::NAMESPACE_, '/meal-plan/(?P<id>\d+)', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'update_meal_plan_item' ), 'permission_callback' => array( $this, 'require_login' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_meal_plan_item' ), 'permission_callback' => array( $this, 'require_login' ) ),
		) );
		register_rest_route( self::NAMESPACE_, '/meal-plan/add-to-shopping-list', array(
			'methods' => 'POST', 'callback' => array( $this, 'meal_plan_to_shopping_list' ), 'permission_callback' => array( $this, 'require_login' ),
		) );

		// item 41: recommendation/finder are public read-only — no account data,
		// no write, filters validated and results bounded either way.
		register_rest_route( self::NAMESPACE_, '/recommend', array(
			'methods' => 'GET', 'callback' => array( $this, 'get_recommendation' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE_, '/ingredients/search', array(
			'methods' => 'GET', 'callback' => array( $this, 'search_ingredients' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE_, '/ingredients/match', array(
			'methods' => 'GET', 'callback' => array( $this, 'match_ingredients' ), 'permission_callback' => '__return_true',
		) );
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

	// ---------------------------------------------------------------------
	// Collections (KROK 8, item 20-22)
	// ---------------------------------------------------------------------

	private function wp_error_or( $result, $default_status = 400 ) {
		if ( is_wp_error( $result ) ) {
			$status = 'not_owner' === $result->get_error_code() ? 403 : $default_status;
			$result->add_data( array( 'status' => $status ) );
		}
		return $result;
	}

	public function get_collections( WP_REST_Request $request ) {
		$rows = Atlas_Chuti_Collections::instance()->get_for_user( get_current_user_id() );
		return rest_ensure_response( array_map( fn( $r ) => array( 'id' => (int) $r->id, 'title' => $r->title, 'description' => $r->description, 'item_count' => $r->item_count ), $rows ) );
	}

	public function create_collection( WP_REST_Request $request ) {
		$id = Atlas_Chuti_Collections::instance()->create( get_current_user_id(), $request->get_param( 'title' ), $request->get_param( 'description' ) );
		if ( is_wp_error( $id ) ) {
			return $this->wp_error_or( $id );
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public function update_collection( WP_REST_Request $request ) {
		$result = Atlas_Chuti_Collections::instance()->update( get_current_user_id(), (int) $request['id'], $request->get_param( 'title' ), $request->get_param( 'description' ) );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function delete_collection( WP_REST_Request $request ) {
		$result = Atlas_Chuti_Collections::instance()->delete( get_current_user_id(), (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function add_collection_item( WP_REST_Request $request ) {
		$recipe_key = sanitize_title( $request->get_param( 'recipe_key' ) );
		if ( ! $recipe_key || ! $this->subject_exists( Atlas_Chuti_User_State::TYPE_RECIPE, $recipe_key ) ) {
			return new WP_Error( 'atlas_not_found', __( 'Recept nebyl nalezen.', 'atlas-chuti' ), array( 'status' => 404 ) );
		}
		$result = Atlas_Chuti_Collections::instance()->add_item( get_current_user_id(), (int) $request['id'], $recipe_key );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function remove_collection_item( WP_REST_Request $request ) {
		$result = Atlas_Chuti_Collections::instance()->remove_item( get_current_user_id(), (int) $request['id'], sanitize_title( $request['recipe_key'] ) );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	// ---------------------------------------------------------------------
	// Shopping list (KROK 8, item 23-25)
	// ---------------------------------------------------------------------

	public function get_shopping_list( WP_REST_Request $request ) {
		$rows = Atlas_Chuti_Shopping_List::instance()->get_for_user( get_current_user_id() );
		return rest_ensure_response(
			array_map(
				fn( $r ) => array(
					'id'             => (int) $r->id,
					'ingredient_key' => $r->ingredient_key,
					'display_name'   => $r->display_name,
					'quantity_value' => null !== $r->quantity_value ? (float) $r->quantity_value : null,
					'quantity_text'  => $r->quantity_text,
					'unit_key'       => $r->unit_key,
					'source_recipe_key' => $r->source_recipe_key,
					'checked'        => (bool) $r->checked,
				),
				$rows
			)
		);
	}

	public function add_shopping_item( WP_REST_Request $request ) {
		$result = Atlas_Chuti_Shopping_List::instance()->add_item(
			get_current_user_id(),
			$request->get_param( 'ingredient_key' ),
			$request->get_param( 'display_name' ),
			$request->get_param( 'quantity_value' ),
			$request->get_param( 'quantity_text' ),
			$request->get_param( 'unit_key' ),
			$request->get_param( 'source_recipe_key' )
		);
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'id' => $result ) );
	}

	public function add_shopping_from_recipe( WP_REST_Request $request ) {
		$recipe_key = sanitize_title( $request->get_param( 'recipe_key' ) );
		if ( ! $recipe_key ) {
			return new WP_Error( 'atlas_bad_request', __( 'Neplatný požadavek.', 'atlas-chuti' ), array( 'status' => 400 ) );
		}
		$result = Atlas_Chuti_Shopping_List::instance()->add_from_recipe( get_current_user_id(), $recipe_key, $request->get_param( 'servings' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'atlas_not_found', __( 'Recept nebyl nalezen.', 'atlas-chuti' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'added' => $result ) );
	}

	public function update_shopping_item( WP_REST_Request $request ) {
		$result = Atlas_Chuti_Shopping_List::instance()->set_checked( get_current_user_id(), (int) $request['id'], (bool) $request->get_param( 'checked' ) );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function delete_shopping_item( WP_REST_Request $request ) {
		$result = Atlas_Chuti_Shopping_List::instance()->remove_item( get_current_user_id(), (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function clear_checked_shopping_items( WP_REST_Request $request ) {
		$removed = Atlas_Chuti_Shopping_List::instance()->clear_checked( get_current_user_id() );
		return rest_ensure_response( array( 'removed' => $removed ) );
	}

	// ---------------------------------------------------------------------
	// Meal planner (KROK 8, item 26-28)
	// ---------------------------------------------------------------------

	public function get_meal_plan( WP_REST_Request $request ) {
		$start = sanitize_text_field( (string) $request->get_param( 'start' ) );
		$end   = sanitize_text_field( (string) $request->get_param( 'end' ) );
		$rows  = Atlas_Chuti_Meal_Plan::instance()->get_for_range( get_current_user_id(), $start, $end );
		return rest_ensure_response(
			array_map(
				fn( $r ) => array(
					'id'                => (int) $r->id,
					'plan_date'         => $r->plan_date,
					'meal_slot'         => $r->meal_slot,
					'recipe_key'        => $r->recipe_key,
					'servings_override' => $r->servings_override ? (int) $r->servings_override : null,
				),
				$rows
			)
		);
	}

	public function add_meal_plan_item( WP_REST_Request $request ) {
		$recipe_key = sanitize_title( $request->get_param( 'recipe_key' ) );
		if ( ! $recipe_key || ! $this->subject_exists( Atlas_Chuti_User_State::TYPE_RECIPE, $recipe_key ) ) {
			return new WP_Error( 'atlas_not_found', __( 'Recept nebyl nalezen.', 'atlas-chuti' ), array( 'status' => 404 ) );
		}
		$result = Atlas_Chuti_Meal_Plan::instance()->add_item(
			get_current_user_id(),
			sanitize_text_field( (string) $request->get_param( 'plan_date' ) ),
			sanitize_key( (string) $request->get_param( 'meal_slot' ) ),
			$recipe_key,
			$request->get_param( 'servings_override' )
		);
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'id' => $result ) );
	}

	public function update_meal_plan_item( WP_REST_Request $request ) {
		$result = Atlas_Chuti_Meal_Plan::instance()->update_item( get_current_user_id(), (int) $request['id'], $request->get_param( 'servings_override' ) );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function delete_meal_plan_item( WP_REST_Request $request ) {
		$result = Atlas_Chuti_Meal_Plan::instance()->remove_item( get_current_user_id(), (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->wp_error_or( $result );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function meal_plan_to_shopping_list( WP_REST_Request $request ) {
		$start = sanitize_text_field( (string) $request->get_param( 'start' ) );
		$end   = sanitize_text_field( (string) $request->get_param( 'end' ) );
		$added = Atlas_Chuti_Meal_Plan::instance()->add_range_to_shopping_list( get_current_user_id(), $start, $end );
		return rest_ensure_response( array( 'added' => $added ) );
	}

	// ---------------------------------------------------------------------
	// Recommendation / ingredient finder (KROK 8, item 13-18) — public, read-only.
	// ---------------------------------------------------------------------

	public function get_recommendation( WP_REST_Request $request ) {
		$filters = array();
		foreach ( array( 'zeme', 'svetadil', 'typ', 'obtiznost', 'dieta', 'cas' ) as $key ) {
			$val = $request->get_param( $key );
			if ( $val ) {
				$filters[ $key ] = sanitize_text_field( (string) $val );
			}
		}
		$result = Atlas_Chuti_Recommendations::instance()->find( $filters );
		return rest_ensure_response(
			array(
				'primary'      => $result['primary'] ? array( 'recipe_key' => get_post_meta( $result['primary']->ID, 'atlas_recipe_key', true ), 'url' => get_permalink( $result['primary'] ), 'title' => get_the_title( $result['primary'] ) ) : null,
				'alternatives' => array_map( fn( $p ) => array( 'recipe_key' => get_post_meta( $p->ID, 'atlas_recipe_key', true ), 'url' => get_permalink( $p ), 'title' => get_the_title( $p ) ), $result['alternatives'] ),
				'reason'       => $result['reason'],
			)
		);
	}

	public function search_ingredients( WP_REST_Request $request ) {
		$results = Atlas_Chuti_Ingredient_Finder::instance()->search_ingredients( (string) $request->get_param( 'q' ) );
		return rest_ensure_response( $results );
	}

	public function match_ingredients( WP_REST_Request $request ) {
		$keys_param = (string) $request->get_param( 'keys' );
		$keys       = array_filter( array_map( 'sanitize_title', explode( ',', $keys_param ) ) );
		if ( ! $keys ) {
			return rest_ensure_response( array() );
		}
		$matches = Atlas_Chuti_Ingredient_Finder::instance()->find_matches( $keys );
		return rest_ensure_response(
			array_map(
				fn( $m ) => array(
					'recipe_key'     => get_post_meta( $m['recipe'] instanceof WP_Post ? $m['recipe']->ID : 0, 'atlas_recipe_key', true ),
					'url'            => get_permalink( $m['recipe'] ),
					'title'          => get_the_title( $m['recipe'] ),
					'matched_count'  => $m['matched_count'],
					'total_count'    => $m['total_count'],
					'missing_labels' => $m['missing_labels'],
				),
				$matches
			)
		);
	}
}
