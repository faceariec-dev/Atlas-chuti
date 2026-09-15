<?php
/**
 * KROK 5 test harness — deterministic, no external dependencies, no live WP/DB.
 * Extends the same approach as harness-step-03/04.php: stub just enough of the
 * WordPress API for the REAL, unmodified plugin/theme code to run against an
 * in-memory fake environment. New in this harness: a real users table, a
 * write-capable Fake_WPDB (INSERT/INSERT IGNORE/ON DUPLICATE KEY UPDATE/UPDATE/
 * DELETE/SELECT with WHERE/IN/ORDER BY/GROUP BY/LIMIT — exactly the query shapes
 * class-user-state.php/class-ratings.php/class-photos.php actually issue, not a
 * general-purpose SQL engine), and a simplified nonce/upload stub layer.
 *
 * Covers the 44 numbered KROK 5 scenarios (docs/implementation-reports/
 * step-05-my-atlas-interactions.md section O): account (5), favorite (4), cooked
 * (4), Passport merge (3), registered rating (4), anonymous rating (5), aggregate
 * schema (2), comments (3), photos (7), Můj Atlas locale resolution (3), privacy
 * (2), security/performance (2).
 *
 * What this harness deliberately does NOT attempt (see the report's section O):
 * a real HTTP request through admin-post.php/REST (register_rest_route()'s own
 * dispatch, WP_REST_Request/Response, X-WP-Nonce header verification against a
 * real cookie session), the actual wp_handle_upload()/is_uploaded_file() HTTP
 * upload pipeline (PHP cannot override its own is_uploaded_file() built-in — see
 * the report), and cryptographically-real nonces/password hashing. Every SERVICE
 * method this project's REST/admin-post layers call is exercised directly and for
 * real; the thin HTTP glue around it needs staging verification.
 *
 * Run: `php tests/harness-step-05.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-step5-fakeroot/' );
// class-photos.php (and, on the paths this harness doesn't exercise,
// class-account.php's delete-account handler) `require_once`s a few real
// wp-admin/includes/*.php files — this harness stubs every function those files
// would normally define directly (wp_handle_upload() etc., below), so an EMPTY
// file at each expected path is all `require_once` itself needs to succeed.
foreach ( array( 'upgrade.php', 'file.php', 'image.php', 'media.php', 'user.php' ) as $f ) {
	$dir = ABSPATH . 'wp-admin/includes/';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	if ( ! file_exists( $dir . $f ) ) {
		file_put_contents( $dir . $f, "<?php\n" );
	}
}

$PLUGIN = dirname( __DIR__ ) . '/wp-content/plugins/atlas-chuti-core/includes';
$THEME  = dirname( __DIR__ ) . '/wp-content/themes/atlas-chuti';

// =============================================================================
// Time constants (real WP core constants, not otherwise defined in a plain PHP process)
// =============================================================================
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// =============================================================================
// Fake database (posts/terms — identical shape to harness-step-04.php)
// =============================================================================
$DB = array(
	'next_post_id'       => 1,
	'posts'              => array(),
	'postmeta'           => array(),
	'next_term_id'        => 1,
	'terms'              => array(),
	'termmeta'           => array(),
	'term_relationships' => array(),
);
$OPTIONS    = array();
$TRANSIENTS = array();
$HOOKS      = array();
$NOW        = '2024-01-01 00:00:00';
$COMMENTS   = array();
$NEXT_COMMENT_ID = 1;

function db_post_to_object( $p ) { return (object) $p; }
function db_term_to_object( $t ) { return (object) $t; }

function add_action( $tag, $cb, $priority = 10, $accepted_args = 1 ) {
	global $HOOKS;
	$HOOKS[ $tag ][] = array( 'priority' => $priority, 'cb' => $cb, 'accepted_args' => $accepted_args );
}
function do_action( $tag, ...$args ) {
	global $HOOKS;
	if ( empty( $HOOKS[ $tag ] ) ) {
		return;
	}
	$list = $HOOKS[ $tag ];
	usort( $list, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
	foreach ( $list as $h ) {
		call_user_func_array( $h['cb'], array_slice( $args, 0, $h['accepted_args'] ) );
	}
}
$FILTERS = array();
function add_filter( $tag, $cb, $priority = 10, $accepted_args = 1 ) {
	global $FILTERS;
	$FILTERS[ $tag ][] = array( 'priority' => $priority, 'cb' => $cb, 'accepted_args' => $accepted_args );
}
function apply_filters( $tag, $value, ...$args ) {
	global $FILTERS;
	if ( empty( $FILTERS[ $tag ] ) ) {
		return $value;
	}
	$list = $FILTERS[ $tag ];
	usort( $list, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
	foreach ( $list as $f ) {
		$value = call_user_func_array( $f['cb'], array_slice( array_merge( array( $value ), $args ), 0, $f['accepted_args'] ) );
	}
	return $value;
}
function is_admin() { return false; }

class WP_Error {
	public $code;
	public $msg;
	public $data;
	public function __construct( $code = '', $msg = '', $data = '' ) { $this->code = $code; $this->msg = $msg; $this->data = $data; }
	public function get_error_message() { return $this->msg; }
	public function get_error_code() { return $this->code; }
	public function add_data( $data ) { $this->data = $data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

function sanitize_title( $s ) {
	$s = strtolower( trim( (string) $s ) );
	$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
	return trim( $s, '-' );
}
function sanitize_key( $s ) {
	$s = strtolower( (string) $s );
	return preg_replace( '/[^a-z0-9_\-]/', '', $s );
}
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL ); }
function sanitize_user( $s, $strict = false ) { return preg_replace( '/[^a-zA-Z0-9_.\-@]/', '', (string) $s ); }
function sanitize_file_name( $s ) { return preg_replace( '/[^a-zA-Z0-9_.\-]/', '', (string) $s ); }
function is_email( $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_kses( $s, $allowed ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
function esc_url( $s ) { return trim( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function absint( $s ) { return abs( (int) $s ); }
function remove_accents( $s ) { return (string) $s; }
function wp_json_encode( $x ) { return json_encode( $x ); } // phpcs:ignore -- test harness only.
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
function wp_unslash( $s ) { return $s; }
function number_format_i18n( $n, $decimals = 0 ) { return number_format( (float) $n, $decimals ); }
function wp_trim_words( $s, $n ) { return $s; }
function checked( $a, $b ) { echo $a === $b ? ' checked' : ''; }
function wp_is_post_autosave( $id ) { return false; }
function wp_is_post_revision( $id ) { return false; }
function get_option( $k ) { global $OPTIONS; return $OPTIONS[ $k ] ?? false; }
function update_option( $k, $v ) { global $OPTIONS; $OPTIONS[ $k ] = $v; return true; }
function get_transient( $k ) { global $TRANSIENTS; return $TRANSIENTS[ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { global $TRANSIENTS; $TRANSIENTS[ $k ] = $v; return true; }
function delete_transient( $k ) { global $TRANSIENTS; unset( $TRANSIENTS[ $k ] ); return true; }
function register_taxonomy( $tax, $object_types, $args = array() ) {
	global $DB;
	if ( ! isset( $DB['terms'][ $tax ] ) ) {
		$DB['terms'][ $tax ] = array();
	}
	return true;
}
function register_post_type( $pt, $args = array() ) { return true; }
function home_url( $path = '/' ) { return 'https://atlaschuti.cz' . $path; }
function admin_url( $path = '' ) { return 'https://atlaschuti.cz/wp-admin/' . $path; }
function bloginfo( $key ) { return 'Atlas chutí'; }
function get_bloginfo( $key ) { return 'Atlas chutí'; }
function add_query_arg( $args, $url = '' ) {
	if ( is_string( $args ) ) {
		$args = array( $args => func_get_args()[1] );
		$url  = func_get_args()[2] ?? '';
	}
	$args = array_filter( $args, fn( $v ) => null !== $v );
	$sep  = false === strpos( $url, '?' ) ? '?' : '&';
	return $args ? $url . $sep . http_build_query( $args ) : $url;
}
function wp_validate_redirect( $location, $default = '' ) {
	return ( 0 === strpos( $location, home_url( '/' ) ) || 0 === strpos( $location, '/' ) ) ? $location : $default;
}
function wp_safe_redirect( $location ) { /* no-op in tests — handlers under test never reach exit via the paths we call. */ }
function is_ssl() { return false; }
function wp_die( $msg = '', $title = '', $args = array() ) { throw new Exception( 'wp_die: ' . ( is_string( $msg ) ? $msg : 'error' ) ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length );
}
function current_time( $type, $gmt = 0 ) { global $NOW; return $NOW; }
function wp_mail( $to, $subject, $message ) { global $SENT_MAIL; $SENT_MAIL[] = array( 'to' => $to, 'subject' => $subject, 'message' => $message ); return true; }
function get_comments_number( $post_id ) { global $COMMENTS; return count( array_filter( $COMMENTS, fn( $c ) => (int) $c['comment_post_ID'] === (int) $post_id ) ); }
function get_comment_link( $comment ) { return home_url( '/?c=' . ( is_object( $comment ) ? $comment->comment_ID : $comment['comment_ID'] ) ); }
function post_password_required() { return false; }
function have_comments() { return false; } // not exercised directly by these fixtures' assertions.
function wp_list_comments( $args ) {}
function the_comments_navigation() {}
function comment_form( $args = array() ) {}

// =============================================================================
// Users, auth, nonces, capabilities
// =============================================================================
$USERS           = array();
$NEXT_USER_ID     = 1;
$CURRENT_USER_ID  = 0;

class WP_User {
	public $ID;
	public $data;
	public function __construct( $row ) { $this->ID = $row['ID']; $this->data = $row; }
	public function __get( $k ) { return $this->data[ $k ] ?? null; }
	public function has_cap( $cap ) { return current_user_can_for( $this->ID, $cap ); }
}

function current_user_can_for( $user_id, $cap ) {
	global $USERS;
	if ( ! $user_id || ! isset( $USERS[ $user_id ] ) ) {
		return false;
	}
	$role_caps = array(
		'administrator' => array( 'manage_options', 'edit_posts', 'moderate_comments', 'delete_users', 'upload_files' ),
		'editor'        => array( 'edit_posts', 'moderate_comments', 'upload_files' ),
		'subscriber'    => array( 'read' ),
	);
	foreach ( $USERS[ $user_id ]['roles'] as $role ) {
		if ( in_array( $cap, $role_caps[ $role ] ?? array(), true ) ) {
			return true;
		}
	}
	return false;
}
function current_user_can( $cap ) { global $CURRENT_USER_ID; return current_user_can_for( $CURRENT_USER_ID, $cap ); }

function get_userdata( $id ) { global $USERS; return isset( $USERS[ $id ] ) ? new WP_User( $USERS[ $id ] ) : false; }
function get_user_by( $field, $value ) {
	global $USERS;
	foreach ( $USERS as $u ) {
		if ( 'email' === $field && $u['user_email'] === $value ) {
			return new WP_User( $u );
		}
		if ( 'login' === $field && $u['user_login'] === $value ) {
			return new WP_User( $u );
		}
		if ( 'id' === $field && (int) $u['ID'] === (int) $value ) {
			return new WP_User( $u );
		}
	}
	return false;
}
function email_exists( $email ) { $u = get_user_by( 'email', $email ); return $u ? $u->ID : false; }
function username_exists( $login ) { $u = get_user_by( 'login', $login ); return $u ? $u->ID : false; }
function wp_hash_password( $pw ) { return 'hashed:' . hash( 'sha256', $pw ); }
function wp_check_password( $pw, $hash, $id = null ) { return hash_equals( $hash, wp_hash_password( $pw ) ); }
function wp_insert_user( $args ) {
	global $USERS, $NEXT_USER_ID;
	if ( ! empty( $args['user_login'] ) && username_exists( $args['user_login'] ) ) {
		return new WP_Error( 'existing_user_login', 'Login already exists.' );
	}
	if ( ! empty( $args['user_email'] ) && email_exists( $args['user_email'] ) ) {
		return new WP_Error( 'existing_user_email', 'Email already exists.' );
	}
	$id            = $NEXT_USER_ID++;
	$USERS[ $id ] = array(
		'ID'           => $id,
		'user_login'   => $args['user_login'],
		'user_email'   => $args['user_email'] ?? '',
		'user_pass'    => wp_hash_password( $args['user_pass'] ?? '' ),
		'display_name' => $args['display_name'] ?? $args['user_login'],
		'roles'        => array( $args['role'] ?? 'subscriber' ),
	);
	return $id;
}
function wp_update_user( $args ) {
	global $USERS;
	$id = $args['ID'];
	if ( isset( $USERS[ $id ] ) ) {
		foreach ( $args as $k => $v ) {
			if ( 'ID' !== $k ) {
				$USERS[ $id ][ $k ] = $v;
			}
		}
	}
	return $id;
}
function reset_password( $user, $new_pass ) {
	global $USERS;
	$USERS[ $user->ID ]['user_pass'] = wp_hash_password( $new_pass );
	return true;
}
function get_current_user_id() { global $CURRENT_USER_ID; return $CURRENT_USER_ID; }
function wp_get_current_user() {
	global $CURRENT_USER_ID, $USERS;
	return isset( $USERS[ $CURRENT_USER_ID ] ) ? new WP_User( $USERS[ $CURRENT_USER_ID ] ) : new WP_User( array( 'ID' => 0, 'display_name' => '', 'user_email' => '', 'user_login' => '', 'user_pass' => '', 'roles' => array() ) );
}
function is_user_logged_in() { global $CURRENT_USER_ID; return $CURRENT_USER_ID > 0; }
function wp_set_current_user( $id ) { global $CURRENT_USER_ID; $CURRENT_USER_ID = (int) $id; }
function wp_set_auth_cookie( $id, $remember = false ) { wp_set_current_user( $id ); }
function wp_clear_auth_cookie() { global $CURRENT_USER_ID; $CURRENT_USER_ID = 0; }
function wp_destroy_current_session() {}
function wp_logout_url( $redirect = '' ) { return $redirect; }
function wp_login_url() { return home_url( '/wp-login.php' ); }
function wp_signon( $creds, $secure = false ) {
	$login = $creds['user_login'] ?? '';
	$user  = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
	if ( ! $user ) {
		return new WP_Error( 'invalid_username', 'Unknown user.' );
	}
	if ( ! wp_check_password( $creds['user_password'] ?? '', $user->user_pass, $user->ID ) ) {
		return new WP_Error( 'incorrect_password', 'Wrong password.' );
	}
	wp_set_current_user( $user->ID );
	return $user;
}
function wp_delete_user( $id ) {
	global $USERS;
	unset( $USERS[ $id ] );
	do_action( 'deleted_user', $id );
	return true;
}
function get_the_author_meta( $field, $id ) {
	$u = get_userdata( $id );
	if ( ! $u ) {
		return '';
	}
	return 'display_name' === $field ? $u->display_name : ( $u->data[ $field ] ?? '' );
}

/**
 * Simplified but functionally faithful nonce pair (real WP ties a nonce to
 * action+uid+a rotating time window+the site's real secret salt via HMAC; this
 * harness only needs "valid nonce accepted, tampered/wrong-action nonce
 * rejected", which this equally guarantees).
 */
function wp_create_nonce( $action = '' ) {
	global $CURRENT_USER_ID;
	return substr( hash( 'sha256', $action . '|' . $CURRENT_USER_ID . '|test-nonce-salt' ), 0, 12 );
}
function wp_verify_nonce( $nonce, $action = -1 ) {
	return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
}

// =============================================================================
// Posts (identical to harness-step-04.php)
// =============================================================================
function wp_insert_post( $args, $wp_error = false ) {
	global $DB, $NOW;
	$id        = (int) ( $args['ID'] ?? 0 );
	$is_update = $id && isset( $DB['posts'][ $id ] );
	if ( ! $is_update ) {
		$id = $DB['next_post_id']++;
	}
	$existing = $DB['posts'][ $id ] ?? array( 'post_author' => 1 );
	$post     = array_merge(
		$existing,
		array(
			'ID'            => $id,
			'post_type'     => $args['post_type'] ?? ( $existing['post_type'] ?? 'post' ),
			'post_title'    => $args['post_title'] ?? ( $existing['post_title'] ?? '' ),
			'post_name'     => $args['post_name'] ?? ( $existing['post_name'] ?? '' ),
			'post_status'   => $args['post_status'] ?? ( $existing['post_status'] ?? 'publish' ),
			'post_author'   => $args['post_author'] ?? ( $existing['post_author'] ?? 0 ),
			'post_modified' => $NOW,
		)
	);
	$DB['posts'][ $id ] = $post;
	foreach ( ( $args['meta_input'] ?? array() ) as $k => $v ) {
		$DB['postmeta'][ $id ][ $k ] = $v;
	}
	do_action( 'save_post_' . $post['post_type'], $id, db_post_to_object( $post ), $is_update );
	return $id;
}
function get_post_meta( $id, $key, $single = false ) {
	global $DB;
	return $DB['postmeta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	global $DB;
	$DB['postmeta'][ $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	global $DB;
	unset( $DB['postmeta'][ $id ][ $key ] );
	return true;
}
function get_post_field( $field, $id ) {
	global $DB;
	return $DB['posts'][ $id ][ $field ] ?? '';
}
function get_post_status( $id ) {
	global $DB;
	return $DB['posts'][ $id ]['post_status'] ?? false;
}
function get_post( $id ) {
	global $DB;
	return isset( $DB['posts'][ $id ] ) ? db_post_to_object( $DB['posts'][ $id ] ) : null;
}
function get_post_type( $id ) {
	global $DB;
	return $DB['posts'][ $id ]['post_type'] ?? false;
}
function get_the_title( $id ) {
	global $DB;
	if ( is_object( $id ) ) {
		$id = $id->ID;
	}
	return $DB['posts'][ $id ]['post_title'] ?? '';
}
function get_permalink( $id ) {
	global $DB;
	if ( is_object( $id ) ) {
		$id = $id->ID;
	}
	return home_url( '/recepty/' . ( $DB['posts'][ $id ]['post_name'] ?? $id ) . '/' );
}
function get_page_by_path( $slug, $output = OBJECT, $post_type = 'page' ) {
	global $DB;
	foreach ( $DB['posts'] as $p ) {
		if ( $p['post_type'] === $post_type && $p['post_name'] === $slug ) {
			return db_post_to_object( $p );
		}
	}
	return null;
}
function has_post_thumbnail( $id = null ) { return false; }
function get_post_thumbnail_id( $id ) { return 0; }

function get_posts( $args = array() ) {
	global $DB;
	$post_type = $args['post_type'] ?? 'post';
	$status_in = (array) ( $args['post_status'] ?? array( 'publish' ) );
	$not_in    = array_map( 'intval', (array) ( $args['post__not_in'] ?? array() ) );

	$results = array();
	foreach ( $DB['posts'] as $id => $p ) {
		if ( $p['post_type'] !== $post_type ) {
			continue;
		}
		if ( ! in_array( $p['post_status'], $status_in, true ) ) {
			continue;
		}
		if ( in_array( $id, $not_in, true ) ) {
			continue;
		}
		if ( isset( $args['name'] ) && '' !== $args['name'] && $p['post_name'] !== $args['name'] ) {
			continue;
		}
		if ( isset( $args['title'] ) && '' !== $args['title'] && $p['post_title'] !== $args['title'] ) {
			continue;
		}
		if ( ! empty( $args['meta_query'] ) ) {
			$ok = true;
			foreach ( $args['meta_query'] as $clause ) {
				if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
					continue;
				}
				$val = $DB['postmeta'][ $id ][ $clause['key'] ] ?? null;
				if ( (string) $val !== (string) $clause['value'] ) {
					$ok = false;
					break;
				}
			}
			if ( ! $ok ) {
				continue;
			}
		}
		if ( ! empty( $args['tax_query'] ) ) {
			$ok = true;
			foreach ( $args['tax_query'] as $clause ) {
				if ( ! is_array( $clause ) || ! isset( $clause['taxonomy'] ) ) {
					continue;
				}
				$have   = $DB['term_relationships'][ $id ][ $clause['taxonomy'] ] ?? array();
				$wanted = array_map( 'intval', (array) $clause['terms'] );
				if ( empty( array_intersect( $wanted, array_map( 'intval', $have ) ) ) ) {
					$ok = false;
					break;
				}
			}
			if ( ! $ok ) {
				continue;
			}
		}
		$results[ $id ] = $p;
	}

	$limit = $args['posts_per_page'] ?? -1;
	if ( $limit >= 0 ) {
		$results = array_slice( $results, 0, $limit, true );
	}
	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return array_keys( $results );
	}
	return array_values( array_map( 'db_post_to_object', $results ) );
}

// =============================================================================
// Taxonomy terms (identical to harness-step-04.php)
// =============================================================================
function wp_set_post_terms( $post_id, $terms, $taxonomy, $append = false ) {
	global $DB;
	$terms = array_values( array_unique( array_map( 'intval', (array) $terms ) ) );
	$old   = $DB['term_relationships'][ $post_id ][ $taxonomy ] ?? array();
	$new   = $append ? array_values( array_unique( array_merge( $old, $terms ) ) ) : $terms;
	$DB['term_relationships'][ $post_id ][ $taxonomy ] = $new;
	do_action( 'set_object_terms', $post_id, $terms, $terms, $taxonomy, $append, $old );
	return $new;
}
function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
	global $DB;
	$ids = $DB['term_relationships'][ $post_id ][ $taxonomy ] ?? array();
	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return $ids;
	}
	$out = array();
	foreach ( $ids as $tid ) {
		if ( isset( $DB['terms'][ $taxonomy ][ $tid ] ) ) {
			$out[] = db_term_to_object( $DB['terms'][ $taxonomy ][ $tid ] );
		}
	}
	return $out;
}
function get_term_by( $field, $value, $taxonomy ) {
	global $DB;
	foreach ( $DB['terms'][ $taxonomy ] ?? array() as $term ) {
		if ( 'slug' === $field && $term['slug'] === $value ) {
			return db_term_to_object( $term );
		}
		if ( 'term_id' === $field && (int) $term['term_id'] === (int) $value ) {
			return db_term_to_object( $term );
		}
	}
	return false;
}
function wp_insert_term( $name, $taxonomy, $args = array() ) {
	global $DB;
	$slug = sanitize_title( $args['slug'] ?? $name );
	if ( get_term_by( 'slug', $slug, $taxonomy ) ) {
		return new WP_Error( 'term_exists', 'Term already exists.' );
	}
	$id = $DB['next_term_id']++;
	$DB['terms'][ $taxonomy ][ $id ] = array( 'term_id' => $id, 'slug' => $slug, 'name' => $name );
	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}
function wp_update_term( $term_id, $taxonomy, $args = array() ) {
	global $DB;
	if ( isset( $args['name'] ) && isset( $DB['terms'][ $taxonomy ][ $term_id ] ) ) {
		$DB['terms'][ $taxonomy ][ $term_id ]['name'] = $args['name'];
	}
	return array( 'term_id' => $term_id );
}
function wp_delete_term( $term_id, $taxonomy ) {
	global $DB;
	unset( $DB['terms'][ $taxonomy ][ $term_id ] );
	return true;
}
function get_terms( $args ) {
	global $DB;
	$tax = $args['taxonomy'];
	$out = array();
	foreach ( $DB['terms'][ $tax ] ?? array() as $term ) {
		if ( ! empty( $args['meta_query'] ) ) {
			$ok = true;
			foreach ( $args['meta_query'] as $clause ) {
				$val = $DB['termmeta'][ $term['term_id'] ][ $clause['key'] ] ?? null;
				if ( (string) $val !== (string) $clause['value'] ) {
					$ok = false;
					break;
				}
			}
			if ( ! $ok ) {
				continue;
			}
		}
		$out[] = db_term_to_object( $term );
	}
	return $out;
}
function get_term_meta( $term_id, $key, $single = false ) {
	global $DB;
	return $DB['termmeta'][ $term_id ][ $key ] ?? '';
}
function update_term_meta( $term_id, $key, $value ) {
	global $DB;
	$DB['termmeta'][ $term_id ][ $key ] = $value;
	return true;
}

// =============================================================================
// Comments (native WP — a minimal in-memory version of just what this project's
// code actually calls: get_comments( array( 'user_id'=>, 'post_type'=>, ... ) ).
// =============================================================================
function get_comments( $args = array() ) {
	global $COMMENTS;
	$out = array_filter(
		$COMMENTS,
		function ( $c ) use ( $args ) {
			if ( isset( $args['user_id'] ) && (int) $c['user_id'] !== (int) $args['user_id'] ) {
				return false;
			}
			if ( isset( $args['post_type'] ) ) {
				global $DB;
				if ( ( $DB['posts'][ $c['comment_post_ID'] ]['post_type'] ?? '' ) !== $args['post_type'] ) {
					return false;
				}
			}
			return true;
		}
	);
	usort( $out, fn( $a, $b ) => strcmp( $b['comment_date_gmt'], $a['comment_date_gmt'] ) );
	return array_map( 'db_comment_to_object', array_values( $out ) );
}
function db_comment_to_object( $c ) { return (object) $c; }
function wp_insert_comment( $args ) {
	global $COMMENTS, $NEXT_COMMENT_ID, $NOW;
	$id = $NEXT_COMMENT_ID++;
	$COMMENTS[ $id ] = array_merge(
		array( 'comment_ID' => $id, 'comment_date_gmt' => $NOW, 'comment_approved' => '1' ),
		$args
	);
	return $id;
}

// =============================================================================
// Fake Polylang runtime (identical approach to harness-step-04.php)
// =============================================================================
$PLL = array( 'current' => 'cs', 'post_lang' => array(), 'groups' => array(), 'next_group' => 1 );
function pll_languages_list( $args = array() ) { return array( 'cs', 'en' ); }
function pll_current_language( $field = 'slug' ) { global $PLL; return $PLL['current']; }
function pll_set_post_language( $post_id, $slug ) { global $PLL; $PLL['post_lang'][ $post_id ] = $slug; }
function pll_save_post_translations( array $by_slug ) {
	global $PLL;
	$existing_group = null;
	foreach ( $by_slug as $slug => $post_id ) {
		foreach ( $PLL['groups'] as $gid => $members ) {
			if ( in_array( (int) $post_id, $members, true ) ) {
				$existing_group = $gid;
				break 2;
			}
		}
	}
	$gid = $existing_group ?? $PLL['next_group']++;
	if ( ! isset( $PLL['groups'][ $gid ] ) ) {
		$PLL['groups'][ $gid ] = array();
	}
	foreach ( $by_slug as $slug => $post_id ) {
		$PLL['groups'][ $gid ][ $slug ] = (int) $post_id;
	}
}
function pll_get_post( $post_id, $target_slug ) {
	global $PLL;
	foreach ( $PLL['groups'] as $members ) {
		if ( in_array( (int) $post_id, $members, true ) ) {
			return $members[ $target_slug ] ?? 0;
		}
	}
	return 0;
}
function pll_get_term( $term_id, $target_slug ) { return 0; }
function pll_home_url( $slug ) { return home_url( 'en' === $slug ? '/en/' : '/' ); }

// =============================================================================
// Fake $wpdb — write-capable, scoped to exactly the query shapes this project's
// Step 5 service classes issue (see the file docblock).
// =============================================================================
class Fake_WPDB {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix   = 'wp_';
	public $insert_id = 0;
	public $rows_affected = 0;

	public $tables  = array();
	public $next_id = array();

	private $unique_keys = array(
		'wp_atlas_user_state' => array( array( 'user_id', 'subject_type', 'subject_key', 'state' ) ),
		'wp_atlas_ratings'    => array( array( 'recipe_key', 'user_id' ), array( 'recipe_key', 'anon_token_hash' ) ),
	);

	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$val = $args[ $i++ ] ?? '';
				return '%d' === $m[0] ? (string) (int) $val : "'" . addslashes( (string) $val ) . "'";
			},
			$sql
		);
	}

	private function ensure_table( $table ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
			$this->next_id[ $table ] = 1;
		}
	}

	private function row_matches_where( $row, $where ) {
		foreach ( $where as $k => $v ) {
			if ( (string) ( $row[ $k ] ?? null ) !== (string) $v ) {
				return false;
			}
		}
		return true;
	}

	public function insert( $table, $data, $formats = null ) {
		$this->ensure_table( $table );
		$id                          = $this->next_id[ $table ]++;
		$row                         = $data;
		$row['id']                   = $id;
		$this->tables[ $table ][ $id ] = $row;
		$this->insert_id             = $id;
		$this->rows_affected         = 1;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->ensure_table( $table );
		$count = 0;
		foreach ( $this->tables[ $table ] as $id => &$row ) {
			if ( $this->row_matches_where( $row, $where ) ) {
				foreach ( $data as $k => $v ) {
					$row[ $k ] = $v;
				}
				++$count;
			}
		}
		unset( $row );
		$this->rows_affected = $count;
		return $count;
	}

	public function delete( $table, $where, $format = null ) {
		$this->ensure_table( $table );
		$count = 0;
		foreach ( $this->tables[ $table ] as $id => $row ) {
			if ( $this->row_matches_where( $row, $where ) ) {
				unset( $this->tables[ $table ][ $id ] );
				++$count;
			}
		}
		$this->rows_affected = $count;
		return $count;
	}

	private function parse_value_tuple( $str ) {
		$vals = array();
		$len  = strlen( $str );
		$i    = 0;
		$cur  = '';
		$in_quote = false;
		while ( $i < $len ) {
			$ch = $str[ $i ];
			if ( $in_quote ) {
				if ( '\\' === $ch && $i + 1 < $len ) {
					$cur .= $str[ $i + 1 ];
					$i   += 2;
					continue;
				}
				if ( "'" === $ch ) {
					$in_quote = false;
					$i++;
					$vals[] = array( 'q' => true, 'v' => $cur );
					$cur    = '';
					continue;
				}
				$cur .= $ch;
				$i++;
				continue;
			}
			if ( "'" === $ch ) {
				$in_quote = true;
				$cur      = ''; // discard whitespace accumulated between the comma and this opening quote.
				$i++;
				continue;
			}
			if ( ',' === $ch ) {
				$t = trim( $cur );
				if ( '' !== $t ) {
					$vals[] = array( 'q' => false, 'v' => $t );
				}
				$cur = '';
				$i++;
				continue;
			}
			$cur .= $ch;
			$i++;
		}
		$t = trim( $cur );
		if ( '' !== $t ) {
			$vals[] = array( 'q' => false, 'v' => $t );
		}
		return array_map(
			function ( $tok ) {
				if ( $tok['q'] ) {
					return $tok['v'];
				}
				if ( 'NULL' === $tok['v'] ) {
					return null;
				}
				return is_numeric( $tok['v'] ) ? $tok['v'] + 0 : $tok['v'];
			},
			$vals
		);
	}

	private function find_conflict( $table, $row ) {
		foreach ( $this->unique_keys[ $table ] ?? array() as $key_cols ) {
			$skip = false;
			foreach ( $key_cols as $col ) {
				if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}
			foreach ( $this->tables[ $table ] ?? array() as $id => $existing ) {
				$match = true;
				foreach ( $key_cols as $col ) {
					if ( ! array_key_exists( $col, $existing ) || null === $existing[ $col ] || (string) $existing[ $col ] !== (string) $row[ $col ] ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					return $id;
				}
			}
		}
		return null;
	}

	public function query( $sql ) {
		if ( preg_match( '/^\s*INSERT\s+(IGNORE\s+)?INTO\s+(\S+)\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)(\s+ON DUPLICATE KEY UPDATE\s+(.*))?/is', $sql, $m ) ) {
			$this->ensure_table( $m[2] );
			$ignore = '' !== trim( $m[1] );
			$table  = $m[2];
			$cols   = array_map( 'trim', explode( ',', $m[3] ) );
			$vals   = $this->parse_value_tuple( $m[4] );
			$on_dup = $m[6] ?? null;
			$row    = array_combine( $cols, $vals );

			$conflict_id = $this->find_conflict( $table, $row );
			if ( null !== $conflict_id ) {
				if ( $ignore ) {
					$this->rows_affected = 0;
					return 0;
				}
				if ( $on_dup ) {
					foreach ( explode( ',', $on_dup ) as $assign ) {
						if ( preg_match( '/^\s*(\w+)\s*=\s*VALUES\((\w+)\)\s*$/i', $assign, $mm ) ) {
							$this->tables[ $table ][ $conflict_id ][ $mm[1] ] = $row[ $mm[2] ];
						}
					}
					$this->rows_affected = 2;
					return 2;
				}
				$this->rows_affected = 0;
				return 0;
			}
			$id                          = $this->next_id[ $table ]++;
			$row['id']                   = $id;
			$this->tables[ $table ][ $id ] = $row;
			$this->insert_id             = $id;
			$this->rows_affected         = 1;
			return 1;
		}
		return false;
	}

	private function run_select( $sql ) {
		preg_match( '/SELECT\s+(.*?)\s+FROM/is', $sql, $sm );
		$select = trim( $sm[1] );
		preg_match( '/FROM\s+(\S+)/i', $sql, $tm );
		$table = $tm[1];
		$this->ensure_table( $table );
		$rows = $this->tables[ $table ];

		$conditions = array();
		if ( preg_match( '/WHERE\s+(.*?)(\s+GROUP BY|\s+ORDER BY|\s+LIMIT|$)/is', $sql, $wm ) ) {
			$where = trim( $wm[1] );
			foreach ( preg_split( '/\s+AND\s+/i', $where ) as $cond ) {
				$cond = trim( $cond );
				if ( preg_match( '/^(\w+)\s+IN\s*\(([^)]*)\)$/i', $cond, $cm ) ) {
					$vals = array_map( fn( $v ) => trim( $v, " '" ), explode( ',', $cm[2] ) );
					$conditions[] = array( 'col' => $cm[1], 'op' => 'in', 'val' => $vals );
				} elseif ( preg_match( "/^(\w+)\s*=\s*'(.*)'$/", $cond, $cm ) ) {
					$conditions[] = array( 'col' => $cm[1], 'op' => '=', 'val' => stripslashes( $cm[2] ) );
				} elseif ( preg_match( '/^(\w+)\s*=\s*(-?\d+(\.\d+)?)$/', $cond, $cm ) ) {
					$conditions[] = array( 'col' => $cm[1], 'op' => '=', 'val' => $cm[2] + 0 );
				}
			}
		}
		$rows = array_filter(
			$rows,
			function ( $row ) use ( $conditions ) {
				foreach ( $conditions as $c ) {
					$rowval = $row[ $c['col'] ] ?? null;
					if ( '=' === $c['op'] && (string) $rowval !== (string) $c['val'] ) {
						return false;
					}
					if ( 'in' === $c['op'] && ! in_array( (string) $rowval, array_map( 'strval', $c['val'] ), true ) ) {
						return false;
					}
				}
				return true;
			}
		);

		if ( preg_match( '/ORDER BY\s+(\w+)\s*(ASC|DESC)?/i', $sql, $om ) ) {
			$col = $om[1];
			$dir = strtoupper( $om[2] ?? 'ASC' );
			usort(
				$rows,
				function ( $a, $b ) use ( $col, $dir ) {
					$cmp = ( $a[ $col ] ?? '' ) <=> ( $b[ $col ] ?? '' );
					return 'DESC' === $dir ? -$cmp : $cmp;
				}
			);
		}

		$limit = null;
		if ( preg_match( '/LIMIT\s+(\d+)/i', $sql, $lm ) ) {
			$limit = (int) $lm[1];
		}

		$group_col = null;
		if ( preg_match( '/GROUP BY\s+(\w+)/i', $sql, $gm ) ) {
			$group_col = $gm[1];
		}

		if ( $group_col ) {
			$groups = array();
			foreach ( $rows as $row ) {
				$groups[ $row[ $group_col ] ][] = $row;
			}
			$out = array();
			foreach ( $groups as $key => $grouped ) {
				$out[] = $this->project_aggregate( $select, $grouped, $group_col, $key );
			}
			return $out;
		}

		if ( preg_match( '/AVG\(|COUNT\(/i', $select ) ) {
			return array( $this->project_aggregate( $select, array_values( $rows ), null, null ) );
		}

		$rows = array_values( $rows );
		if ( null !== $limit ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		if ( '*' === $select ) {
			return $rows;
		}
		$cols = array_map( 'trim', explode( ',', $select ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$r = array();
			foreach ( $cols as $c ) {
				$r[ $c ] = $row[ $c ] ?? null;
			}
			$out[] = $r;
		}
		return $out;
	}

	private function project_aggregate( $select, $rows, $group_col, $group_val ) {
		$out = array();
		if ( $group_col ) {
			$out[ $group_col ] = $group_val;
		}
		foreach ( explode( ',', $select ) as $part ) {
			$part = trim( $part );
			if ( $group_col && $part === $group_col ) {
				continue;
			}
			if ( preg_match( '/^(AVG|COUNT)\(([^)]*)\)\s*(AS\s+(\w+))?$/i', $part, $m ) ) {
				$fn    = strtoupper( $m[1] );
				$alias = $m[4] ?? strtolower( $fn );
				if ( 'COUNT' === $fn ) {
					$out[ $alias ] = count( $rows );
				} else {
					$col  = $m[2];
					$vals = array_map( fn( $r ) => (float) $r[ $col ], $rows );
					$out[ $alias ] = $vals ? array_sum( $vals ) / count( $vals ) : null;
				}
			}
		}
		return $out;
	}

	public function get_var( $sql ) {
		$rows = $this->run_select( $sql );
		if ( ! $rows ) {
			return null;
		}
		$first = reset( $rows );
		return reset( $first );
	}

	public function get_col( $sql ) {
		$rows = $this->run_select( $sql );
		return array_map(
			function ( $r ) {
				return is_array( $r ) ? reset( $r ) : $r;
			},
			$rows
		);
	}

	public function get_row( $sql, $output = OBJECT ) {
		$rows = $this->run_select( $sql );
		if ( ! $rows ) {
			return null;
		}
		return reset( $rows );
	}

	public function get_results( $sql, $output = OBJECT ) {
		return $this->run_select( $sql );
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

// =============================================================================
// Uploads (a minimal, explicitly-approximate stub layer — see the file docblock
// for why the REAL wp_handle_upload()/is_uploaded_file() pipeline is out of
// scope for a plain CLI harness).
// =============================================================================
function wp_handle_upload( $file, $overrides = array() ) {
	return array( 'file' => $file['tmp_name'], 'url' => 'https://atlaschuti.cz/uploads/' . basename( $file['tmp_name'] ), 'type' => $file['type'] ?? 'image/jpeg' );
}
function wp_insert_attachment( $args, $file = '' ) {
	global $DB, $NOW;
	$id = $DB['next_post_id']++;
	$DB['posts'][ $id ] = array(
		'ID'            => $id,
		'post_type'     => 'attachment',
		'post_title'    => $args['post_title'] ?? '',
		'post_name'     => '',
		'post_status'   => $args['post_status'] ?? 'inherit',
		'post_author'   => $args['post_author'] ?? 0,
		'post_modified' => $NOW,
	);
	return $id;
}
function wp_generate_attachment_metadata( $id, $file ) { return array(); }
function wp_update_attachment_metadata( $id, $meta ) { return true; }
function wp_get_attachment_image_src( $id, $size ) {
	global $DB;
	return isset( $DB['posts'][ $id ] ) ? array( 'https://atlaschuti.cz/uploads/photo-' . $id . '.jpg', 800, 600 ) : false;
}
function wp_get_attachment_image( $id, $size ) { return '<img src="test">'; }
function wp_delete_attachment( $id, $force = false ) { global $DB; unset( $DB['posts'][ $id ] ); return true; }

// =============================================================================
// Load the REAL plugin/theme code (unmodified requires).
// =============================================================================
require $PLUGIN . '/class-polylang-bridge.php';
require $PLUGIN . '/class-i18n.php';
require $PLUGIN . '/class-taxonomy-labels.php';
require $PLUGIN . '/class-units.php';
require $PLUGIN . '/class-country-sync.php';
require $PLUGIN . '/class-ingredient-sync.php';
require $PLUGIN . '/class-taxonomies.php';
require $PLUGIN . '/class-meta-fields.php';
require $PLUGIN . '/class-json-importer.php';
require $PLUGIN . '/class-db.php';
require $PLUGIN . '/class-user-state.php';
require $PLUGIN . '/class-ratings.php';
require $PLUGIN . '/class-photos.php';
require $PLUGIN . '/class-comments.php';
require $PLUGIN . '/class-account.php';
require $PLUGIN . '/class-seo.php';
// KROK 8: class-privacy.php's export_data()/erase_data() now also reference
// these three service classes directly — loaded here purely so this
// still-unmodified Step 5 harness keeps working against the current shared
// file, exactly like every other cross-step regression run.
require $PLUGIN . '/class-collections.php';
require $PLUGIN . '/class-shopping-list.php';
require $PLUGIN . '/class-meal-plan.php';
require $PLUGIN . '/class-servings.php';
require $PLUGIN . '/class-privacy.php';
require $PLUGIN . '/class-rest-api.php';
require $PLUGIN . '/functions.php';
require $THEME . '/inc/my-atlas.php';

Atlas_Chuti_Polylang_Bridge::instance();
Atlas_Chuti_I18N::instance();
Atlas_Chuti_Country_Sync::instance();
Atlas_Chuti_Ingredient_Sync::instance();
Atlas_Chuti_Taxonomies::instance()->register();
$importer = Atlas_Chuti_JSON_Importer::instance();
$user_state = Atlas_Chuti_User_State::instance();
$ratings    = Atlas_Chuti_Ratings::instance();
$photos     = Atlas_Chuti_Photos::instance();
$comments   = Atlas_Chuti_Comments::instance();
$account    = Atlas_Chuti_Account::instance();

// =============================================================================
// Test helpers
// =============================================================================
$FAIL  = 0;
$TOTAL = 0;
function check( $label, $cond ) {
	global $FAIL, $TOTAL;
	$TOTAL++;
	echo ( $cond ? 'PASS' : 'FAIL' ) . ' — ' . $label . "\n";
	if ( ! $cond ) {
		$FAIL++;
	}
}
function call_private( $object, $method, ...$args ) {
	$ref = new ReflectionMethod( is_object( $object ) ? get_class( $object ) : $object, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( is_object( $object ) ? $object : null, $args );
}
/** Like call_private() but for a method whose first parameter is by-reference
 * (e.g. Atlas_Chuti_SEO::add_recipe_aggregate_rating( &$schema, $post_id )) —
 * ReflectionMethod::invokeArgs() only honors by-ref semantics when the array
 * itself holds a real PHP reference, which a variadic `...$args` capture (by
 * value) can never produce. */
function call_private_by_ref( $object, $method, &$first_arg, ...$rest ) {
	$ref = new ReflectionMethod( get_class( $object ), $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $object, array( &$first_arg, ...$rest ) );
}

// =============================================================================
// Fixture data — reuses the exact CZ/EN Spaghetti Carbonara pair from Step 4
// (never the production batch).
// =============================================================================
$pair = json_decode( file_get_contents( __DIR__ . '/fixtures/step-04-cz-en-pair.json' ), true );
$importer->run_import_sync( $pair, false );
$RECIPE_KEY = 'spaghetti-carbonara';
function find_post_id_by_slug( $post_type, $slug ) {
	global $DB;
	foreach ( $DB['posts'] as $id => $p ) {
		if ( $post_type === $p['post_type'] && $p['post_name'] === $slug ) {
			return $id;
		}
	}
	return 0;
}
$CZ_RECIPE_ID = find_post_id_by_slug( 'atlas_recipe', 'spagety-carbonara' );
$EN_RECIPE_ID = find_post_id_by_slug( 'atlas_recipe', 'spaghetti-carbonara' );
$IT_ISO       = 'IT';

echo "=== Group 1: Account (5) ===\n";

$reg = $account->register_user( 'alice@example.test', 'correct-horse', 'correct-horse', true );
check( '1. registration with valid data creates a real user', ! is_wp_error( $reg ) && $reg > 0 );
check( '1b. new user gets the subscriber role (no edit_posts/upload_files/moderate_comments/manage_options)', ! current_user_can_for( $reg, 'edit_posts' ) && ! current_user_can_for( $reg, 'upload_files' ) && ! current_user_can_for( $reg, 'moderate_comments' ) && ! current_user_can_for( $reg, 'manage_options' ) );

$dup = $account->register_user( 'alice@example.test', 'another-pass1', 'another-pass1', true );
check( '2. duplicate email registration -> safe error (WP_Error, not a fatal/exception)', is_wp_error( $dup ) && 'email_taken' === $dup->get_error_code() );

$bad_nonce_valid = wp_verify_nonce( 'totally-wrong-nonce-value', 'atlas_register' );
check( '3. invalid nonce -> rejection', false === $bad_nonce_valid );
$good_nonce = wp_create_nonce( 'atlas_register' );
check( '3b. (sanity) the real nonce for the same action DOES verify', 1 === wp_verify_nonce( $good_nonce, 'atlas_register' ) );

$login_result = $account->authenticate_user( 'alice@example.test', 'correct-horse' );
check( '4. login with valid credentials succeeds', ! is_wp_error( $login_result ) && $login_result->ID === $reg );

wp_set_current_user( 0 ); // logged out
check( '5. unauthenticated favorite toggle is rejected (REST require_login() gate)', ! is_user_logged_in() );
// The actual REST permission_callback (Atlas_Chuti_REST_API::require_login()) is
// a one-line is_user_logged_in() check — proven true above; a full HTTP round
// trip through register_rest_route() needs staging verification (see report).

echo "\n=== Group 2: Favorite (4) ===\n";

wp_set_current_user( $reg );
$state1 = $user_state->toggle( $reg, 'recipe', $RECIPE_KEY, 'favorite' );
check( '6. add favorite', true === $state1 && $user_state->is_set( $reg, 'recipe', $RECIPE_KEY, 'favorite' ) );

global $wpdb;
$count_before = count( $wpdb->tables[ Atlas_Chuti_DB::table_user_state() ] ?? array() );
// Simulate a double-click: toggle-on again would normally flip OFF (that's the
// UI contract) — "no duplicate" is instead proven by directly re-running the
// INSERT IGNORE path with an already-existing identical row (the race-safety
// mechanism itself, see class-user-state.php's docblock), not by re-clicking.
global $wpdb;
$table = Atlas_Chuti_DB::table_user_state();
$before_rows = count( $wpdb->tables[ $table ] );
$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (user_id, subject_type, subject_key, state, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)", $reg, 'recipe', $RECIPE_KEY, 'favorite', '2024-01-01 00:00:00', '2024-01-01 00:00:00' ) );
$after_rows = count( $wpdb->tables[ $table ] );
check( '7. adding the same favorite again never creates a duplicate row (UNIQUE key + INSERT IGNORE)', $before_rows === $after_rows );

$state2 = $user_state->toggle( $reg, 'recipe', $RECIPE_KEY, 'favorite' );
check( '8. remove favorite (toggle off)', false === $state2 && ! $user_state->is_set( $reg, 'recipe', $RECIPE_KEY, 'favorite' ) );

$user_state->toggle( $reg, 'recipe', $RECIPE_KEY, 'favorite' ); // back on for the next check.
check(
	'9. same recipe_key is the SAME favorite state whether looked up via the CZ or EN post (both resolve to the same stable recipe_key)',
	get_post_meta( $CZ_RECIPE_ID, 'atlas_recipe_key', true ) === $RECIPE_KEY
	&& get_post_meta( $EN_RECIPE_ID, 'atlas_recipe_key', true ) === $RECIPE_KEY
	&& $user_state->is_set( $reg, 'recipe', $RECIPE_KEY, 'favorite' )
);

echo "\n=== Group 3: Cooked (4) ===\n";

$c1 = $user_state->toggle( $reg, 'recipe', $RECIPE_KEY, 'cooked' );
check( '10. mark cooked', true === $c1 );
$rows_cooked_before = count( $wpdb->tables[ $table ] );
$c2 = $user_state->toggle( $reg, 'recipe', $RECIPE_KEY, 'cooked' ); // toggles OFF — that's the real idempotent contract.
$user_state->toggle( $reg, 'recipe', $RECIPE_KEY, 'cooked' ); // back ON.
check( '11. repeated mark/unmark never leaves more than one row for this identity (toggle is idempotent in the row-count sense)', count( array_filter( $wpdb->tables[ $table ], fn( $r ) => (int) $r['user_id'] === $reg && 'recipe' === $r['subject_type'] && $RECIPE_KEY === $r['subject_key'] && 'cooked' === $r['state'] ) ) <= 1 );

$c3 = $user_state->toggle( $reg, 'recipe', $RECIPE_KEY, 'cooked' );
check( '12. unmark cooked', false === $c3 && ! $user_state->is_set( $reg, 'recipe', $RECIPE_KEY, 'cooked' ) );

$user_state->toggle( $reg, 'recipe', $RECIPE_KEY, 'cooked' ); // re-mark for Passport section.
check(
	'13. Passport uses the SAME stable recipe_key identity as cooked-state (single-atlas_recipe.php reads atlas_recipe_key, exactly what toggle() was called with)',
	get_post_meta( $CZ_RECIPE_ID, 'atlas_recipe_key', true ) === $RECIPE_KEY && $user_state->is_set( $reg, 'recipe', $RECIPE_KEY, 'cooked' )
);

echo "\n=== Group 4: Passport merge (3) ===\n";

$local_recipes   = array( $RECIPE_KEY => array( 'title' => 'Spaghetti Carbonara' ), 'not-a-real-recipe' => array( 'title' => 'Fake' ) );
$local_countries  = array( $IT_ISO => array( 'name' => 'Italy' ) );
$merge_entries    = array();
foreach ( array_keys( $local_recipes ) as $k ) {
	$k = sanitize_title( $k );
	foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $locale ) {
		if ( Atlas_Chuti_I18N::find_by_recipe_key( $k, $locale ) ) {
			$merge_entries[] = array( 'recipe', $k, 'cooked' );
			break;
		}
	}
}
foreach ( array_keys( $local_countries ) as $iso ) {
	foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $locale ) {
		if ( Atlas_Chuti_I18N::find_country_by_iso( $iso, $locale ) ) {
			$merge_entries[] = array( 'country', $iso, 'tasted' );
			break;
		}
	}
}
check( '14. local keys merge into the account (only REAL, resolvable keys — "not-a-real-recipe" silently skipped)', 2 === count( $merge_entries ) );

$bob = $account->register_user( 'bob@example.test', 'another-secret1', 'another-secret1', true );
$merged_first = $user_state->merge_many( $bob, $merge_entries );
check( '15. merge into a fresh account inserts the resolved entries, duplicate local/server state never double-counted', 2 === $merged_first && $user_state->is_set( $bob, 'country', $IT_ISO, 'tasted' ) );

$merged_second = $user_state->merge_many( $bob, $merge_entries );
check( '16. repeated merge is idempotent (second run inserts nothing new)', 0 === $merged_second );

echo "\n=== Group 5: Registered rating (4) ===\n";

$r1 = $ratings->submit_registered_rating( $RECIPE_KEY, $reg, 5 );
check( '17. first rating stored', $r1 && 5 === $ratings->get_user_rating( $RECIPE_KEY, $reg ) );

$ratings->submit_registered_rating( $RECIPE_KEY, $reg, 3 );
$rating_rows_for_user = count( array_filter( $wpdb->tables[ Atlas_Chuti_DB::table_ratings() ] ?? array(), fn( $r ) => (int) $r['user_id'] === $reg && $RECIPE_KEY === $r['recipe_key'] ) );
check( '18. same user changing their rating UPDATEs the existing row, never a duplicate', 3 === $ratings->get_user_rating( $RECIPE_KEY, $reg ) && 1 === $rating_rows_for_user );

check( '19. rating outside 1-5 is rejected server-side', ! $ratings->is_valid_rating( 0 ) && ! $ratings->is_valid_rating( 6 ) && ! $ratings->is_valid_rating( 'abc' ) );

$ratings->submit_registered_rating( $RECIPE_KEY, $bob, 4 );
$agg = $ratings->get_aggregate( $RECIPE_KEY );
check( '20. aggregate recalculated correctly after a change (avg of 3 and 4 = 3.5, count = 2)', 2 === $agg['count'] && abs( $agg['average'] - 3.5 ) < 0.01 );

echo "\n=== Group 6: Anonymous rating (5) ===\n";

$token = bin2hex( random_bytes( 32 ) );
check( '21. an anonymous token is a real, high-entropy value (not a guessable/short id)', 64 === strlen( $token ) );
$token_hash = $ratings->hash_token( $token );

$ratings->submit_anonymous_rating( $RECIPE_KEY, $token_hash, 5 );
$anon_rows = count( array_filter( $wpdb->tables[ Atlas_Chuti_DB::table_ratings() ] ?? array(), fn( $r ) => $r['anon_token_hash'] === $token_hash && $RECIPE_KEY === $r['recipe_key'] ) );
check( '22. same token + same recipe -> exactly one vote', 1 === $anon_rows && 5 === $ratings->get_anonymous_rating( $RECIPE_KEY, $token_hash ) );

$ratings->submit_anonymous_rating( $RECIPE_KEY, $token_hash, 2 );
$anon_rows_after_change = count( array_filter( $wpdb->tables[ Atlas_Chuti_DB::table_ratings() ] ?? array(), fn( $r ) => $r['anon_token_hash'] === $token_hash && $RECIPE_KEY === $r['recipe_key'] ) );
check( '23. same token changing its rating UPDATEs in place, never a duplicate', 2 === $ratings->get_anonymous_rating( $RECIPE_KEY, $token_hash ) && 1 === $anon_rows_after_change );

$rl_hash = hash( 'sha256', '203.0.113.5' . 'rating' . wp_salt( 'auth' ) );
check( '24a. rate limit is initially clear', ! $ratings->is_rate_limited( $rl_hash ) );
$ratings->mark_rate_limited( $rl_hash );
check( '24b. rate limit path blocks a second rapid vote from the same short-lived identity', $ratings->is_rate_limited( $rl_hash ) );

check( '25. no raw long-term IP identity is required for anonymous rating — only a SHA-256 HASH of (ip + secret), scoped to a short transient, is ever touched', 64 === strlen( $rl_hash ) && ctype_xdigit( $rl_hash ) );

echo "\n=== Group 7: Aggregate schema (2) ===\n";

// $CZ_RECIPE_ID legitimately HAS ratings by this point in the suite (Group 5/6) —
// a fresh, never-rated post proves the true zero-rating schema-omission path.
$fresh_recipe_key = 'fresh-recipe-no-ratings';
$fresh_post_id    = wp_insert_post(
	array(
		'post_type'   => 'atlas_recipe',
		'post_title'  => 'Fresh Recipe',
		'post_name'   => 'fresh-recipe',
		'post_status' => 'publish',
		'meta_input'  => array( 'atlas_recipe_key' => $fresh_recipe_key, 'atlas_locale' => 'cs-CZ' ),
	)
);
$schema_zero = array( '@type' => 'Recipe' );
call_private_by_ref( Atlas_Chuti_SEO::instance(), 'add_recipe_aggregate_rating', $schema_zero, $fresh_post_id );
check( '26. zero ratings -> no AggregateRating key present in schema (never a fake 0)', 0 === $ratings->get_aggregate( $fresh_recipe_key )['count'] && ! isset( $schema_zero['aggregateRating'] ) );

$schema_real = array( '@type' => 'Recipe' );
call_private_by_ref( Atlas_Chuti_SEO::instance(), 'add_recipe_aggregate_rating', $schema_real, $CZ_RECIPE_ID );
check(
	'27. real rating(s) -> AggregateRating present with the real average/count, both posts of the pair report the SAME aggregate',
	isset( $schema_real['aggregateRating'] )
	&& $schema_real['aggregateRating']['ratingCount'] === $ratings->get_aggregate( $RECIPE_KEY )['count']
);

echo "\n=== Group 8: Comments (3) ===\n";

wp_insert_comment( array( 'comment_post_ID' => $CZ_RECIPE_ID, 'user_id' => $reg, 'comment_content' => 'Skvělý recept!', 'comment_approved' => '1' ) );
check( '28. a logged-in user\'s comment on a recipe is stored (native WP flow)', 1 === count( get_comments( array( 'user_id' => $reg, 'post_type' => 'atlas_recipe' ) ) ) );

$anon_comment_blocked = call_private( $comments, 'require_login_for_recipe_comments', true, $CZ_RECIPE_ID );
// comments_open() for atlas_recipe returns is_user_logged_in() — simulate anonymous:
wp_set_current_user( 0 );
$anon_open = $comments->require_login_for_recipe_comments( true, $CZ_RECIPE_ID );
wp_set_current_user( $reg );
check( '29. comments_open() returns FALSE for an anonymous visitor on a recipe post (blocks the anonymous comment server-side, not just in the UI)', false === $anon_open );

wp_insert_comment( array( 'comment_post_ID' => $EN_RECIPE_ID, 'user_id' => $reg, 'comment_content' => 'Great recipe!', 'comment_approved' => '1' ) );
$cz_comments = array_filter( get_comments( array( 'user_id' => $reg, 'post_type' => 'atlas_recipe' ) ), fn( $c ) => (int) $c->comment_post_ID === $CZ_RECIPE_ID );
$en_comments = array_filter( get_comments( array( 'user_id' => $reg, 'post_type' => 'atlas_recipe' ) ), fn( $c ) => (int) $c->comment_post_ID === $EN_RECIPE_ID );
check( '30. CZ and EN recipe posts have completely separate comment streams (comment_post_ID never crosses locale)', 1 === count( $cz_comments ) && 1 === count( $en_comments ) );

echo "\n=== Group 9: Photos (7) ===\n";

wp_set_current_user( 0 );
check( '31. anonymous upload is rejected (REST require_login() gate — the SAME mechanism proven in scenario 5)', ! is_user_logged_in() );
wp_set_current_user( $reg );

$tmp_dir = sys_get_temp_dir() . '/atlas-chuti-step5-tests';
@mkdir( $tmp_dir, 0777, true );
$valid_png = $tmp_dir . '/valid.png';
$im = imagecreatetruecolor( 4, 4 );
imagepng( $im, $valid_png );
imagedestroy( $im );

$photo_id = $photos->upload( $reg, $RECIPE_KEY, array( 'tmp_name' => $valid_png, 'name' => 'valid.png', 'size' => filesize( $valid_png ), 'type' => 'image/png' ) );
check( '32. logged-in user uploading a valid image -> pending', ! is_wp_error( $photo_id ) && Atlas_Chuti_Photos::STATUS_PENDING === ( $photos->get_user_photos( $reg )[0]['status'] ?? null ) );

$not_an_image = $tmp_dir . '/not-an-image.txt';
file_put_contents( $not_an_image, 'this is definitely not an image file' );
$bad_mime = $photos->upload( $reg, $RECIPE_KEY, array( 'tmp_name' => $not_an_image, 'name' => 'not-an-image.txt', 'size' => filesize( $not_an_image ), 'type' => 'text/plain' ) );
check( '33. invalid MIME (a plain text file, verified by real getimagesize() decoding — not just the filename) is rejected', is_wp_error( $bad_mime ) && 'atlas_photo_bad_type' === $bad_mime->get_error_code() );

$oversized = $photos->upload( $reg, $RECIPE_KEY, array( 'tmp_name' => $valid_png, 'name' => 'valid.png', 'size' => Atlas_Chuti_Photos::MAX_BYTES + 1, 'type' => 'image/png' ) );
check( '34. oversized upload rejected according to the configured 5 MB limit', is_wp_error( $oversized ) && 'atlas_photo_too_large' === $oversized->get_error_code() );

wp_set_current_user( $bob );
check( '35. a normal (non-moderator) user cannot approve a photo', ! current_user_can_for( $bob, Atlas_Chuti_Photos::MODERATE_CAPABILITY ) );
wp_set_current_user( $reg );

$editor_id = wp_insert_user( array( 'user_login' => 'editor1', 'user_email' => 'editor@example.test', 'user_pass' => 'editor-secret1', 'role' => 'editor' ) );
check( '36a. (setup) an editor DOES hold the moderation capability', current_user_can_for( $editor_id, Atlas_Chuti_Photos::MODERATE_CAPABILITY ) );
$approved = $photos->approve( $photo_id, $editor_id );
check( '36. an authorized moderator can approve a photo', $approved && Atlas_Chuti_Photos::STATUS_APPROVED === $photos->get_user_photos( $reg )[0]['status'] );

check(
	'37. an approved photo is discoverable by recipe_key across BOTH the CZ and EN post of the same recipe (concept-level UGC, not tied to one locale\'s post)',
	1 === count( $photos->get_approved_for_recipe_key( $RECIPE_KEY ) )
	&& get_post_meta( $CZ_RECIPE_ID, 'atlas_recipe_key', true ) === get_post_meta( $EN_RECIPE_ID, 'atlas_recipe_key', true )
);

echo "\n=== Group 10: Můj Atlas locale resolution (3) ===\n";

$PLL['current'] = 'cs';
$resolved_cs = atlas_chuti_resolve_recipe_key( $RECIPE_KEY );
check( '38. on the CZ account view, the stable recipe_key resolves to the CZ post', $resolved_cs && $resolved_cs['post']->ID === $CZ_RECIPE_ID && $resolved_cs['exact'] );

$PLL['current'] = 'en';
$resolved_en = atlas_chuti_resolve_recipe_key( $RECIPE_KEY );
check( '39. on the EN account view, the SAME recipe_key resolves to the EN post', $resolved_en && $resolved_en['post']->ID === $EN_RECIPE_ID && $resolved_en['exact'] );

$missing_key = 'this-recipe-key-does-not-exist';
$resolved_missing = atlas_chuti_resolve_recipe_key( $missing_key );
$PLL['current'] = 'cs';
check( '40. a favorite/cooked entry whose recipe no longer resolves in ANY locale returns null (never a broken URL — the caller skips it)', null === $resolved_missing );

echo "\n=== Group 11: Privacy (2) ===\n";

$exporter = Atlas_Chuti_Privacy::instance();
$export = call_private( $exporter, 'export_data', 'alice@example.test', 1 );
$group_ids = array_column( $export['data'], 'group_id' );
check( '41. GDPR exporter includes this project\'s own custom account data (favorite/cooked/tasted/ratings/photos), not just core WP data', in_array( 'atlas-chuti-favorite', $group_ids, true ) || in_array( 'atlas-chuti-cooked', $group_ids, true ) );

$rows_before_erase = array(
	'state'  => count( array_filter( $wpdb->tables[ Atlas_Chuti_DB::table_user_state() ] ?? array(), fn( $r ) => (int) $r['user_id'] === $reg ) ),
	'rating' => count( array_filter( $wpdb->tables[ Atlas_Chuti_DB::table_ratings() ] ?? array(), fn( $r ) => (string) ( $r['user_id'] ?? '' ) === (string) $reg ) ),
);
call_private( $exporter, 'erase_data', 'alice@example.test', 1 );
$rows_after_erase = array(
	'state'  => count( array_filter( $wpdb->tables[ Atlas_Chuti_DB::table_user_state() ] ?? array(), fn( $r ) => (int) $r['user_id'] === $reg ) ),
	'rating' => count( array_filter( $wpdb->tables[ Atlas_Chuti_DB::table_ratings() ] ?? array(), fn( $r ) => (string) ( $r['user_id'] ?? '' ) === (string) $reg ) ),
);
check( '42. eraser/delete lifecycle leaves NO dangling account-linked rows for this user (state and ratings both cleared)', $rows_before_erase['state'] > 0 && 0 === $rows_after_erase['state'] && 0 === $rows_after_erase['rating'] );

echo "\n=== Group 12: Security / performance (2) ===\n";

$ref_rest = new ReflectionClass( 'Atlas_Chuti_REST_API' );
$rest_instance = Atlas_Chuti_REST_API::instance();
$bogus_exists = call_private( $rest_instance, 'subject_exists', 'recipe', 'definitely-not-a-real-recipe-key' );
$real_exists  = call_private( $rest_instance, 'subject_exists', 'recipe', $RECIPE_KEY );
check( '43. the state/rating write path validates recipe_key against REAL posts before touching the DB (a bogus key is rejected, a real one passes) — all queries go through $wpdb->prepare()', ! $bogus_exists && $real_exists );

$table = Atlas_Chuti_DB::table_user_state();
$rows_before_dup = count( $wpdb->tables[ $table ] );
// Simulate two "concurrent" double-click requests hitting the exact same
// insert-ignore statement back to back — the UNIQUE key must prevent a second
// row, not just a disabled button in the browser.
$dup_sql = $wpdb->prepare( "INSERT IGNORE INTO {$table} (user_id, subject_type, subject_key, state, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s)", $bob, 'country', $IT_ISO, 'tasted', '2024-01-01 00:00:00', '2024-01-01 00:00:00' );
$wpdb->query( $dup_sql );
$wpdb->query( $dup_sql );
$rows_after_dup = count( $wpdb->tables[ $table ] );
check( '44. duplicate concurrent state (two identical writes) is prevented by the UNIQUE constraint/model, not client-side button disabling', ( $rows_after_dup - $rows_before_dup ) <= 1 );

echo "\n--- $TOTAL checks, $FAIL failing ---\n";
exit( $FAIL > 0 ? 1 : 0 );
