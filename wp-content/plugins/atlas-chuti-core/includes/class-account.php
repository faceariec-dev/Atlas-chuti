<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5, items 3/4/7: the account SERVICE layer — registration, login, logout
 * redirect, and password reset, all built on real WordPress Users (item 3: "Použij
 * standardní WordPress Users jako zdroj identity... Nevytvářej vlastní paralelní
 * auth systém"). No custom session/token table, no custom password hashing —
 * every write here goes through wp_insert_user()/wp_signon()/reset_password(), the
 * same core functions wp-login.php itself uses. This class only supplies its OWN
 * front-end forms/URLs instead of wp-login.php's, via admin-post.php handlers
 * (`admin_post_nopriv_*` — the standard WP pattern for a logged-out-accessible
 * POST target).
 *
 * One account works for both locales (item 30): nothing here is locale-scoped —
 * WP Users are a single global table, so a CZ registration and an EN login are the
 * exact same account by construction, never a design choice that could diverge.
 */
class Atlas_Chuti_Account {

	const DEFAULT_ROLE = 'subscriber';
	const RATE_LIMIT_ATTEMPTS = 5;
	const RATE_LIMIT_WINDOW   = 10 * MINUTE_IN_SECONDS;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_nopriv_atlas_register', array( $this, 'handle_register' ) );
		add_action( 'admin_post_nopriv_atlas_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_nopriv_atlas_lost_password', array( $this, 'handle_lost_password' ) );
		add_action( 'admin_post_nopriv_atlas_reset_password', array( $this, 'handle_reset_password' ) );
		add_action( 'admin_post_atlas_update_profile', array( $this, 'handle_update_profile' ) );
		add_action( 'admin_post_atlas_delete_account', array( $this, 'handle_delete_account' ) );
		// A logged-in visitor hitting these by mistake (stale tab, back button) — send
		// them to their account instead of a hard error.
		add_action( 'admin_post_atlas_register', array( $this, 'redirect_already_logged_in' ) );
		add_action( 'admin_post_atlas_login', array( $this, 'redirect_already_logged_in' ) );

		// Item: "Můj Atlas link goes to wp-admin's profile screen today" (Step 5 audit
		// finding) — a plain subscriber landing in wp-admin after wp-login.php is a
		// confusing dead end; send anyone without any edit_posts-shaped capability to
		// the front-end account area instead. Editors/admins keep the normal wp-admin
		// redirect.
		add_filter( 'login_redirect', array( $this, 'front_end_login_redirect' ), 10, 3 );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	public function redirect_already_logged_in() {
		wp_safe_redirect( atlas_chuti_system_url( 'account' ) );
		exit;
	}

	public function front_end_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User && ! $user->has_cap( 'edit_posts' ) && empty( $requested_redirect_to ) ) {
			return atlas_chuti_system_url( 'account' );
		}
		return $redirect_to;
	}

	private function client_identity_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}

	/**
	 * Item 7: "rate limiting proti hrubému brute-force u custom endpointu" — a short
	 * transient counter, never a persisted long-term identity (same principle as
	 * the anonymous-rating rate limit in class-ratings.php).
	 */
	private function is_rate_limited( $bucket ) {
		$key   = 'atlas_chuti_rl_' . $bucket . '_' . $this->client_identity_hash();
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_ATTEMPTS ) {
			return true;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return false;
	}

	private function redirect_with_error( $section, $code ) {
		$url = add_query_arg(
			array( 'sekce' => $section, 'chyba' => $code ),
			atlas_chuti_system_url( 'account' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private function safe_redirect_to( $requested ) {
		$requested = (string) $requested;
		if ( $requested && wp_validate_redirect( $requested, '' ) ) {
			return $requested;
		}
		return atlas_chuti_system_url( 'account' );
	}

	// ---------------------------------------------------------------------
	// Registration
	// ---------------------------------------------------------------------

	public function handle_register() {
		if ( $this->is_rate_limited( 'register' ) ) {
			$this->redirect_with_error( 'registrace', 'rate_limited' );
		}
		if ( ! isset( $_POST['atlas_account_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_account_nonce'], 'atlas_register' ) ) {
			$this->redirect_with_error( 'registrace', 'invalid_nonce' );
		}

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$confirm  = isset( $_POST['password_confirm'] ) ? (string) $_POST['password_confirm'] : '';
		$consent  = ! empty( $_POST['consent'] );
		$redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';

		$result = $this->register_user( $email, $password, $confirm, $consent );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( 'registrace', $result->get_error_code() );
		}

		wp_set_current_user( $result );
		wp_set_auth_cookie( $result, ! empty( $_POST['remember'] ) );

		wp_safe_redirect( $this->safe_redirect_to( $redirect ) );
		exit;
	}

	/**
	 * The actual registration logic, isolated from the admin-post request/redirect
	 * plumbing above — returns the new user ID or a WP_Error whose code is one of
	 * the theme's `atlas_chuti_account_error_message()` keys (inc/my-atlas.php),
	 * never calls exit(). This split (request glue vs. real logic) is what makes
	 * registration unit-testable without a live HTTP round-trip — see
	 * tests/harness-step-05.php.
	 */
	public function register_user( $email, $password, $confirm, $consent ) {
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email' );
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'weak_password' );
		}
		if ( $password !== $confirm ) {
			return new WP_Error( 'password_mismatch' );
		}
		if ( ! $consent ) {
			return new WP_Error( 'consent_required' );
		}
		// A taken email must be disclosed here (there is no way to let the visitor
		// register otherwise) — this is the one legitimate, UX-necessary exception to
		// the "no enumeration" rule (item 7), unlike login/password-reset below where
		// silence is both possible and preferred.
		if ( email_exists( $email ) ) {
			return new WP_Error( 'email_taken' );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $this->generate_unique_login( $email ),
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => self::DEFAULT_ROLE,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'server_error' );
		}

		do_action( 'atlas_chuti_user_registered', $user_id );
		return $user_id;
	}

	/**
	 * item 3: "username nebo automaticky bezpečně vytvořený login" — the UX only
	 * ever asks for an email; the login itself is derived from it and never shown
	 * as anything the user has to remember or that could collide.
	 */
	private function generate_unique_login( $email ) {
		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$base  = $base ?: 'user';
		$login = $base;
		$i     = 0;
		while ( username_exists( $login ) ) {
			++$i;
			$login = $base . $i . wp_generate_password( 4, false, false );
			if ( $i > 20 ) { // pathological collision loop guard.
				$login = $base . '-' . wp_generate_password( 10, false, false );
				break;
			}
		}
		return $login;
	}

	// ---------------------------------------------------------------------
	// Login
	// ---------------------------------------------------------------------

	public function handle_login() {
		if ( $this->is_rate_limited( 'login' ) ) {
			$this->redirect_with_error( 'prihlaseni', 'rate_limited' );
		}
		if ( ! isset( $_POST['atlas_account_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_account_nonce'], 'atlas_login' ) ) {
			$this->redirect_with_error( 'prihlaseni', 'invalid_nonce' );
		}

		$login    = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : '';

		$user = $this->authenticate_user( $login, $password, ! empty( $_POST['remember'] ) );
		if ( is_wp_error( $user ) ) {
			$this->redirect_with_error( 'prihlaseni', 'invalid_credentials' );
		}

		wp_safe_redirect( $this->safe_redirect_to( $redirect ) );
		exit;
	}

	/**
	 * The actual authentication logic, isolated from the request/redirect plumbing
	 * (see register_user()'s docblock for why). Wraps wp_signon() — the same core
	 * function wp-login.php itself uses — and collapses every WP core error code
	 * (invalid_username vs incorrect_password) into one generic failure (item 7:
	 * no enumeration via which part of the credentials was wrong).
	 */
	public function authenticate_user( $login, $password, $remember = false ) {
		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			return new WP_Error( 'invalid_credentials' );
		}
		return $user;
	}

	// ---------------------------------------------------------------------
	// Password reset — built on the same low-level core functions wp-login.php
	// itself uses (get_password_reset_key/check_password_reset_key/reset_password,
	// all in wp-includes/user.php, always loaded), just with this project's own
	// front-end form/URL instead of wp-login.php's (item 7: "standardní WordPress
	// password reset").
	// ---------------------------------------------------------------------

	public function handle_lost_password() {
		if ( $this->is_rate_limited( 'lost_password' ) ) {
			$this->redirect_with_error( 'zapomenute-heslo', 'rate_limited' );
		}
		if ( ! isset( $_POST['atlas_account_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_account_nonce'], 'atlas_lost_password' ) ) {
			$this->redirect_with_error( 'zapomenute-heslo', 'invalid_nonce' );
		}

		$login_or_email = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
		$user           = is_email( $login_or_email ) ? get_user_by( 'email', $login_or_email ) : get_user_by( 'login', $login_or_email );

		if ( $user instanceof WP_User ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$this->send_reset_email( $user, $key );
			}
		}
		// Item 7: identical outcome whether or not the account exists — the reset
		// page always shows "pokud tento e-mail existuje, poslali jsme odkaz",
		// never confirms/denies the account's existence.
		$url = add_query_arg( array( 'sekce' => 'zapomenute-heslo', 'odeslano' => '1' ), atlas_chuti_system_url( 'account' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function send_reset_email( WP_User $user, $key ) {
		$reset_url = add_query_arg(
			array(
				'sekce' => 'nove-heslo',
				'key'   => $key,
				'login' => rawurlencode( $user->user_login ),
			),
			atlas_chuti_system_url( 'account' )
		);
		$subject = sprintf( /* translators: %s: site name */ __( '[%s] Obnovení hesla', 'atlas-chuti' ), get_bloginfo( 'name' ) );
		$message = sprintf(
			/* translators: %s: password reset URL */
			__( "Někdo (doufejme, že vy) požádal o obnovení hesla k vašemu účtu.\n\nPro nastavení nového hesla klikněte na odkaz níže:\n%s\n\nPokud jste o obnovení hesla nežádali, tento e-mail můžete ignorovat.", 'atlas-chuti' ),
			$reset_url
		);
		wp_mail( $user->user_email, $subject, $message );
	}

	public function handle_reset_password() {
		if ( $this->is_rate_limited( 'reset_password' ) ) {
			$this->redirect_with_error( 'nove-heslo', 'rate_limited' );
		}
		if ( ! isset( $_POST['atlas_account_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_account_nonce'], 'atlas_reset_password' ) ) {
			$this->redirect_with_error( 'nove-heslo', 'invalid_nonce' );
		}

		$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$login    = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$confirm  = isset( $_POST['password_confirm'] ) ? (string) $_POST['password_confirm'] : '';

		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			$this->redirect_with_error( 'nove-heslo', 'invalid_key' );
		}
		if ( strlen( $password ) < 8 ) {
			$this->redirect_with_error( 'nove-heslo', 'weak_password' );
		}
		if ( $password !== $confirm ) {
			$this->redirect_with_error( 'nove-heslo', 'password_mismatch' );
		}

		reset_password( $user, $password );

		$url = add_query_arg( array( 'sekce' => 'prihlaseni', 'heslo_obnoveno' => '1' ), atlas_chuti_system_url( 'account' ) );
		wp_safe_redirect( $url );
		exit;
	}

	// ---------------------------------------------------------------------
	// Account settings ("Nastavení účtu")
	// ---------------------------------------------------------------------

	public function handle_update_profile() {
		if ( ! is_user_logged_in() || ! isset( $_POST['atlas_account_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_account_nonce'], 'atlas_update_profile' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		$user_id      = get_current_user_id();
		$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$new_password = isset( $_POST['new_password'] ) ? (string) $_POST['new_password'] : '';
		$confirm      = isset( $_POST['new_password_confirm'] ) ? (string) $_POST['new_password_confirm'] : '';
		$current      = isset( $_POST['current_password'] ) ? (string) $_POST['current_password'] : '';

		if ( $display_name ) {
			wp_update_user( array( 'ID' => $user_id, 'display_name' => $display_name ) );
		}

		if ( '' !== $new_password ) {
			$user = get_userdata( $user_id );
			if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
				$this->redirect_with_error( 'nastaveni', 'invalid_credentials' );
			}
			if ( strlen( $new_password ) < 8 ) {
				$this->redirect_with_error( 'nastaveni', 'weak_password' );
			}
			if ( $new_password !== $confirm ) {
				$this->redirect_with_error( 'nastaveni', 'password_mismatch' );
			}
			reset_password( $user, $new_password );
		}

		$url = add_query_arg( array( 'sekce' => 'nastaveni', 'ulozeno' => '1' ), atlas_chuti_system_url( 'account' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Item 38: self-service account deletion — a plain subscriber has no
	 * `delete_users` capability and never needs one; this handler calls
	 * wp_delete_user() programmatically only after confirming the REQUESTING user
	 * IS the target account (their own current password, re-checked here), never
	 * as a general-purpose admin action. Every account-linked custom row (favorite/
	 * cooked/tasted, ratings, photos) is cleaned up via the SAME `deleted_user`
	 * hook real WP admin-initiated deletions already trigger (see
	 * class-user-state.php/class-ratings.php/class-photos.php) — one lifecycle
	 * path, not a second one only for self-service.
	 */
	public function handle_delete_account() {
		if ( ! is_user_logged_in() || ! isset( $_POST['atlas_account_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_account_nonce'], 'atlas_delete_account' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		$current = isset( $_POST['current_password'] ) ? (string) $_POST['current_password'] : '';
		if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
			$this->redirect_with_error( 'nastaveni', 'invalid_credentials' );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_destroy_current_session();
		wp_clear_auth_cookie();
		wp_delete_user( $user_id );

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}
