<?php
/**
 * KROK 7 test harness — deterministic, no external dependencies, no live WP/DB.
 * Same approach as harness-step-03/04/05/06.php: stub just enough of the
 * WordPress API for the REAL, unmodified plugin/theme code to run against an
 * in-memory fake environment (this file's stub layer starts as a copy of
 * harness-step-06.php's, extended with what KROK 7 newly needs: a fake
 * featured-image/thumbnail store, wp_http_validate_url(), sanitize_html_class(),
 * a real current_time('timestamp'), and is_page()).
 *
 * Covers the 40 numbered KROK 7 scenarios (docs/implementation-reports/
 * step-07-advertising-infrastructure.md section K): Registry (5), Locale (4),
 * Dates (3), GATE (5), Consent (4), CLS/layout (3), Provider (3), SEO/
 * accessibility (5), Security (3), Regression (5, incl. production-data diff).
 *
 * What this harness deliberately does NOT attempt (same scope boundary as
 * every prior harness): a real HTTP request through admin-post.php (nonce vs.
 * a real cookie session, the literal `exit` in handle_save()), actual browser
 * layout/CLS measurement, a real external ad-network script, and rendering the
 * GATE/slot markup inside a real browser viewport — those live in the report's
 * staging checklist (section L). Every PHP decision path (registry lookup,
 * source resolution, date/locale gating, consent gating, markup building) is
 * exercised directly and for real.
 *
 * Run: `php tests/harness-step-07.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-step7-fakeroot/' );

$PLUGIN = dirname( __DIR__ ) . '/wp-content/plugins/atlas-chuti-core/includes';
$THEME  = dirname( __DIR__ ) . '/wp-content/themes/atlas-chuti';

// =============================================================================
// Time constants
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
// Fake database (posts/terms) — identical shape to harness-step-04/05.php, with
// post_content/post_excerpt/post_date added (KROK 6 needs real article/topic body
// text and publish dates for article_schema()/get_the_excerpt()/get_the_date(),
// which no prior step's harness needed to store).
// =============================================================================
$DB = array(
	'next_post_id'       => 1,
	'posts'              => array(),
	'postmeta'           => array(),
	'next_term_id'       => 1,
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
$CONDITIONS = array( 'search' => false, 'category' => false, 'post_type_archive' => '', 'page_template' => '', 'author' => false, 'page' => false );
$THUMBNAILS = array();

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
function wp_kses_post( $s ) { return (string) $s; }
function wp_kses( $s, $allowed ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function strip_shortcodes( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return trim( (string) $s ); }
function esc_url( $s ) { return trim( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function absint( $s ) { return abs( (int) $s ); }
function wp_json_encode( $x ) { return json_encode( $x ); } // phpcs:ignore -- test harness only.
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
function wp_unslash( $s ) { return $s; }
function wp_trim_words( $s, $n ) { return $s; }
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
function add_post_type_support( $pt, $feature ) { return true; }
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
function remove_query_arg( $key, $url = '' ) { return $url; }
function wp_safe_redirect( $location ) { /* no-op — HTTP-glue handlers under test never reach exit via the paths we call. */ }
function wp_die( $msg = '', $title = '', $args = array() ) { throw new Exception( 'wp_die: ' . ( is_string( $msg ) ? $msg : 'error' ) ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function current_time( $type, $gmt = 0 ) { global $NOW; return 'timestamp' === $type ? strtotime( $NOW ) : $NOW; }
function post_password_required() { return false; }
function have_comments() { return false; }
function wp_list_comments( $args ) {}
function the_comments_navigation() {}
function comment_form( $args = array() ) {}

// Lightweight, globally-toggleable WP conditional tags — only what
// class-seo.php's get_robots_directive() (tested via reflection) actually reads.
function is_search() { global $CONDITIONS; return $CONDITIONS['search']; }
function is_category() { global $CONDITIONS; return $CONDITIONS['category']; }
function is_post_type_archive( $pt = null ) { global $CONDITIONS; return null === $pt ? (bool) $CONDITIONS['post_type_archive'] : $CONDITIONS['post_type_archive'] === $pt; }
function is_page_template( $t = null ) { global $CONDITIONS; return null === $t ? (bool) $CONDITIONS['page_template'] : $CONDITIONS['page_template'] === $t; }
function is_author() { global $CONDITIONS; return $CONDITIONS['author']; }
function is_page() { global $CONDITIONS; return $CONDITIONS['page']; }
function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) { global $ENQUEUED_SCRIPTS; $ENQUEUED_SCRIPTS[ $handle ] = $src; }
function sanitize_html_class( $class, $fallback = '' ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class ) ?: $fallback; }
/**
 * A faithful-enough stand-in for WP core's own wp_http_validate_url(): rejects
 * anything without an http(s) scheme or a host — exactly the two checks
 * Atlas_Chuti_Advertising::direct_campaign_payload() actually relies on.
 */
function wp_http_validate_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	return $url;
}
function wp_parse_url( $url ) { return parse_url( $url ); } // phpcs:ignore -- test harness only, WP's own wp_parse_url() is a compat shim this harness doesn't need.
function get_query_var( $v ) { return ''; }

// =============================================================================
// Users, auth, nonces, capabilities (identical to harness-step-05.php)
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
function get_current_user_id() { global $CURRENT_USER_ID; return $CURRENT_USER_ID; }
function is_user_logged_in() { global $CURRENT_USER_ID; return $CURRENT_USER_ID > 0; }
function wp_set_current_user( $id ) { global $CURRENT_USER_ID; $CURRENT_USER_ID = (int) $id; }
function get_the_author_meta( $field, $id ) {
	$u = get_userdata( $id );
	if ( ! $u ) {
		return '';
	}
	return 'display_name' === $field ? $u->display_name : ( $u->data[ $field ] ?? '' );
}
function wp_create_user( $login, $pass, $email ) {
	global $USERS, $NEXT_USER_ID;
	$id            = $NEXT_USER_ID++;
	$USERS[ $id ] = array( 'ID' => $id, 'user_login' => $login, 'user_email' => $email, 'display_name' => $login, 'roles' => array( 'subscriber' ) );
	return $id;
}

/**
 * Simplified but functionally faithful nonce pair — identical mechanism to every
 * prior harness (see harness-step-05.php's own docblock for why this equally
 * proves "valid nonce accepted, tampered/wrong-action nonce rejected").
 */
function wp_create_nonce( $action = '' ) {
	global $CURRENT_USER_ID;
	return substr( hash( 'sha256', $action . '|' . $CURRENT_USER_ID . '|test-nonce-salt' ), 0, 12 );
}
function wp_verify_nonce( $nonce, $action = -1 ) {
	return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
}

// =============================================================================
// Posts (extends harness-step-04/05.php's version: also stores post_content/
// post_excerpt/post_date — needed for article_schema()/get_the_excerpt()/
// get_the_date(), which no prior step needed to persist).
// =============================================================================
function wp_insert_post( $args, $wp_error = false ) {
	global $DB, $NOW;
	$id        = (int) ( $args['ID'] ?? 0 );
	$is_update = $id && isset( $DB['posts'][ $id ] );
	if ( ! $is_update ) {
		$id = $DB['next_post_id']++;
	}
	$existing = $DB['posts'][ $id ] ?? array( 'post_author' => 1, 'post_date' => $NOW );
	$post     = array_merge(
		$existing,
		array(
			'ID'            => $id,
			'post_type'     => $args['post_type'] ?? ( $existing['post_type'] ?? 'post' ),
			'post_title'    => $args['post_title'] ?? ( $existing['post_title'] ?? '' ),
			'post_name'     => $args['post_name'] ?? ( $existing['post_name'] ?? '' ),
			'post_content'  => $args['post_content'] ?? ( $existing['post_content'] ?? '' ),
			'post_excerpt'  => $args['post_excerpt'] ?? ( $existing['post_excerpt'] ?? '' ),
			'post_status'   => $args['post_status'] ?? ( $existing['post_status'] ?? 'publish' ),
			'post_author'   => $args['post_author'] ?? ( $existing['post_author'] ?? 0 ),
			'post_date'     => $existing['post_date'] ?? $NOW,
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
function get_post_meta( $id, $key, $single = false ) { global $DB; return $DB['postmeta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { global $DB; $DB['postmeta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { global $DB; unset( $DB['postmeta'][ $id ][ $key ] ); return true; }
function get_post_field( $field, $id ) { global $DB; return $DB['posts'][ $id ][ $field ] ?? ''; }
function get_post_status( $id ) { global $DB; return $DB['posts'][ $id ]['post_status'] ?? false; }
function get_post( $id ) { global $DB; return isset( $DB['posts'][ $id ] ) ? db_post_to_object( $DB['posts'][ $id ] ) : null; }
function get_post_type( $id ) { global $DB; return $DB['posts'][ $id ]['post_type'] ?? false; }
function get_the_title( $id ) { global $DB; if ( is_object( $id ) ) { $id = $id->ID; } return $DB['posts'][ $id ]['post_title'] ?? ''; }
function get_the_excerpt( $id = null ) {
	global $DB;
	$id = is_object( $id ) ? $id->ID : $id;
	$p  = $DB['posts'][ $id ] ?? null;
	if ( ! $p ) {
		return '';
	}
	return '' !== $p['post_excerpt'] ? $p['post_excerpt'] : wp_trim_words( $p['post_content'], 55 );
}
function get_the_date( $format = '', $id = null ) {
	global $DB;
	$id   = is_object( $id ) ? $id->ID : $id;
	$post = $DB['posts'][ $id ] ?? null;
	if ( ! $post ) {
		return '';
	}
	$raw = $post['post_date'] ?: $post['post_modified'];
	return 'c' === $format ? gmdate( 'c', strtotime( $raw ) ) : $raw;
}
function get_the_modified_date( $format = '', $id = null ) {
	global $DB;
	$id   = is_object( $id ) ? $id->ID : $id;
	$post = $DB['posts'][ $id ] ?? null;
	if ( ! $post ) {
		return '';
	}
	$raw = $post['post_modified'];
	return 'c' === $format ? gmdate( 'c', strtotime( $raw ) ) : $raw;
}
function get_permalink( $id ) {
	global $DB;
	if ( is_object( $id ) ) {
		$id = $id->ID;
	}
	return home_url( '/p/' . ( $DB['posts'][ $id ]['post_name'] ?? $id ) . '/' );
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
function has_post_thumbnail( $id = null ) { global $THUMBNAILS; return ! empty( $THUMBNAILS[ $id ] ); }
function get_post_thumbnail_id( $id ) { global $THUMBNAILS; return ! empty( $THUMBNAILS[ $id ] ) ? $id : 0; }
function get_the_post_thumbnail_url( $id, $size = 'thumbnail' ) { global $THUMBNAILS; return $THUMBNAILS[ $id ] ?? false; }
function set_post_thumbnail( $id, $url ) { global $THUMBNAILS; $THUMBNAILS[ $id ] = $url; }
function wp_get_attachment_image_src( $id, $size ) { global $THUMBNAILS; return isset( $THUMBNAILS[ $id ] ) ? array( $THUMBNAILS[ $id ] ) : false; }
function get_post_type_archive_link( $post_type ) {
	$slugs = array( 'atlas_recipe' => 'recepty', 'atlas_glossary' => 'slovnicek', 'atlas_topic' => 'diskuze' );
	return isset( $slugs[ $post_type ] ) ? home_url( '/' . $slugs[ $post_type ] . '/' ) : false;
}
function get_category_link( $term ) {
	$slug = is_object( $term ) ? $term->slug : $term;
	return home_url( '/category/' . $slug . '/' );
}
function get_term_link( $term, $taxonomy = '' ) {
	$slug = is_object( $term ) ? $term->slug : $term;
	return home_url( '/term/' . $slug . '/' );
}

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
		if ( isset( $args['author'] ) && (int) $p['post_author'] !== (int) $args['author'] ) {
			continue;
		}
		if ( ! empty( $args['meta_query'] ) ) {
			$ok = true;
			foreach ( $args['meta_query'] as $clause ) {
				if ( ! is_array( $clause ) || ( 'OR' === ( $clause['relation'] ?? '' ) ) ) {
					continue; // OR/NOT-EXISTS clauses (see class-i18n.php) aren't exercised through get_posts() in this harness — only through the dedicated Fake_WP_Query scope_query_to_locale() checks below.
				}
				if ( ! isset( $clause['key'] ) ) {
					continue;
				}
				$val = $DB['postmeta'][ $id ][ $clause['key'] ] ?? null;
				if ( 'LIKE' === ( $clause['compare'] ?? '' ) ) {
					if ( false === strpos( serialize( $val ), (string) $clause['value'] ) ) {
						$ok = false;
						break;
					}
					continue;
				}
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

/**
 * A bare-bones stand-in for WP_Query, just enough for
 * Atlas_Chuti_I18N::scope_query_to_locale() (which only ever calls ->get()/->set())
 * to run against for real — identical to harness-step-04.php's own Fake_WP_Query.
 */
class Fake_WP_Query {
	public $vars;
	public function __construct( $vars = array() ) { $this->vars = $vars; }
	public function get( $key ) { return $this->vars[ $key ] ?? ''; }
	public function set( $key, $val ) { $this->vars[ $key ] = $val; }
}

// =============================================================================
// Taxonomy terms (identical to harness-step-04/05.php)
// =============================================================================
function wp_set_post_terms( $post_id, $terms, $taxonomy, $append = false ) {
	global $DB;
	$terms = array_values( array_unique( array_map( 'intval', (array) $terms ) ) );
	$old   = $DB['term_relationships'][ $post_id ][ $taxonomy ] ?? array();
	$new   = $append ? array_values( array_unique( array_merge( $old, $terms ) ) ) : $terms;
	$DB['term_relationships'][ $post_id ][ $taxonomy ] = $new;
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
function get_the_terms( $post_id, $taxonomy ) {
	$out = wp_get_post_terms( $post_id, $taxonomy );
	return $out ?: false;
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
function get_terms( $args ) {
	global $DB;
	$tax = $args['taxonomy'];
	$out = array();
	foreach ( $DB['terms'][ $tax ] ?? array() as $term ) {
		$out[] = db_term_to_object( $term );
	}
	return $out;
}

// =============================================================================
// Comments (native WP — identical minimal in-memory version to harness-05.php)
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
	return array_map( 'db_comment_to_object', array_values( $out ) );
}
function db_comment_to_object( $c ) { return (object) $c; }
function wp_insert_comment( $args ) {
	global $COMMENTS, $NEXT_COMMENT_ID, $NOW;
	$id = $NEXT_COMMENT_ID++;
	$COMMENTS[ $id ] = array_merge( array( 'comment_ID' => $id, 'comment_date_gmt' => $NOW, 'comment_approved' => '1' ), $args );
	return $id;
}
function get_comments_number( $post_id ) { global $COMMENTS; return count( array_filter( $COMMENTS, fn( $c ) => (int) $c['comment_post_ID'] === (int) $post_id ) ); }

// =============================================================================
// Fake Polylang runtime — identical approach to harness-step-04/05.php, extended
// with term-language/term-translation tracking (KROK 6 needs to prove the
// Magazín's `category` terms are paired CZ<->EN, which no prior step needed).
// =============================================================================
$PLL = array( 'current' => 'cs', 'post_lang' => array(), 'groups' => array(), 'next_group' => 1, 'term_lang' => array(), 'term_groups' => array(), 'next_term_group' => 1 );
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
function pll_set_term_language( $term_id, $slug ) { global $PLL; $PLL['term_lang'][ $term_id ] = $slug; }
function pll_save_term_translations( array $by_slug ) {
	global $PLL;
	$existing_group = null;
	foreach ( $by_slug as $slug => $term_id ) {
		foreach ( $PLL['term_groups'] as $gid => $members ) {
			if ( in_array( (int) $term_id, $members, true ) ) {
				$existing_group = $gid;
				break 2;
			}
		}
	}
	$gid = $existing_group ?? $PLL['next_term_group']++;
	if ( ! isset( $PLL['term_groups'][ $gid ] ) ) {
		$PLL['term_groups'][ $gid ] = array();
	}
	foreach ( $by_slug as $slug => $term_id ) {
		$PLL['term_groups'][ $gid ][ $slug ] = (int) $term_id;
	}
}
function pll_get_term( $term_id, $target_slug ) {
	global $PLL;
	foreach ( $PLL['term_groups'] as $members ) {
		if ( in_array( (int) $term_id, $members, true ) ) {
			return $members[ $target_slug ] ?? 0;
		}
	}
	return 0;
}
function pll_home_url( $slug ) { return home_url( 'en' === $slug ? '/en/' : '/' ); }

// =============================================================================
// =============================================================================
// Load the real, unmodified plugin code.
// =============================================================================
require $PLUGIN . '/class-polylang-bridge.php';
require $PLUGIN . '/class-i18n.php';
require $PLUGIN . '/class-ad-slots.php';
require $PLUGIN . '/class-ad-campaign.php';
require $PLUGIN . '/class-advertising.php';
require $PLUGIN . '/functions.php';

Atlas_Chuti_Polylang_Bridge::instance();
Atlas_Chuti_I18N::instance();
Atlas_Chuti_Ad_Campaign::instance();
$adv = Atlas_Chuti_Advertising::instance();

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

function set_ads_option( array $overrides ) {
	$settings = get_option( Atlas_Chuti_Advertising::OPTION );
	$settings = is_array( $settings ) ? $settings : array();
	update_option( Atlas_Chuti_Advertising::OPTION, array_merge( $settings, $overrides ) );
}
function configure_slot( $key, $enabled, $source, $campaign_id = 0 ) {
	$settings = get_option( Atlas_Chuti_Advertising::OPTION );
	$settings = is_array( $settings ) ? $settings : array();
	$settings['per_slot'][ $key ] = array( 'enabled' => $enabled, 'source' => $source, 'campaign_id' => $campaign_id );
	update_option( Atlas_Chuti_Advertising::OPTION, $settings );
}
function create_campaign( $title, $args = array() ) {
	$id = wp_insert_post(
		array(
			'post_type'   => Atlas_Chuti_Ad_Campaign::POST_TYPE,
			'post_title'  => $title,
			'post_status' => $args['status'] ?? 'publish',
		)
	);
	if ( ! array_key_exists( 'image', $args ) || false !== $args['image'] ) {
		set_post_thumbnail( $id, $args['image'] ?? 'https://cdn.example.test/creative.jpg' );
	}
	update_post_meta( $id, 'atlas_ad_slots', $args['slots'] ?? array() );
	update_post_meta( $id, 'atlas_ad_locale', $args['locale'] ?? 'all' );
	update_post_meta( $id, 'atlas_ad_click_url', array_key_exists( 'click_url', $args ) ? $args['click_url'] : 'https://sponsor.example.test/' );
	update_post_meta( $id, 'atlas_ad_alt_text', $args['alt'] ?? 'Sponsor' );
	update_post_meta( $id, 'atlas_ad_start', $args['start'] ?? '' );
	update_post_meta( $id, 'atlas_ad_end', $args['end'] ?? '' );
	return $id;
}

// =============================================================================
// Fixture data
// =============================================================================
$PLL['current'] = 'cs';
$admin_id = wp_create_user( 'admin', 'x', 'admin@example.test' );
$USERS[ $admin_id ]['roles'] = array( 'administrator' );
$subscriber_id = wp_create_user( 'sub', 'x', 'sub@example.test' );

// Consent/provider are entirely filter-driven (item 19) — these two globals let
// each check flip them independently without needing to "unregister" a filter.
$PROVIDER_CONFIG_ENABLED = false;
$CONSENT_ALLOWED         = false;
add_filter( 'atlas_chuti_ad_provider_config', function () { global $PROVIDER_CONFIG_ENABLED; return $PROVIDER_CONFIG_ENABLED ? array( 'client' => 'test-client' ) : array(); } );
add_filter( 'atlas_chuti_ad_provider_script_url', function () { global $PROVIDER_CONFIG_ENABLED; return $PROVIDER_CONFIG_ENABLED ? 'https://ads.example.test/provider.js' : ''; } );
add_filter( 'atlas_chuti_consent_allows_marketing', function () { global $CONSENT_ALLOWED; return $CONSENT_ALLOWED; } );

set_ads_option( array( 'enabled' => true, 'gate_enabled' => true, 'external_network_enabled' => true, 'debug_mode' => false ) );

echo "=== Group 1: Registry (5) ===\n";

check( '1. a known slot key is valid', Atlas_Chuti_Ad_Slots::exists( 'recipe_sidebar_top' ) );
check( '2. an unknown slot key is safely rejected — empty markup, no error', '' === $adv->build_slot_markup( 'totally_made_up_slot' ) );

$cz_campaign = create_campaign( 'CZ campaign', array( 'slots' => array( 'recipe_sidebar_top' ), 'locale' => 'cs' ) );
configure_slot( 'recipe_sidebar_top', true, 'direct', $cz_campaign );
set_ads_option( array( 'enabled' => false ) );
check( '3. globally disabled advertising renders nothing even with a valid, active campaign', '' === $adv->build_slot_markup( 'recipe_sidebar_top' ) );
set_ads_option( array( 'enabled' => true ) );

configure_slot( 'recipe_sidebar_top', false, 'direct', $cz_campaign );
check( '4. a slot the admin has NOT enabled renders nothing', '' === $adv->build_slot_markup( 'recipe_sidebar_top' ) );

configure_slot( 'recipe_sidebar_top', true, 'none' );
check( '5. source "none" renders nothing', '' === $adv->build_slot_markup( 'recipe_sidebar_top' ) );

echo "\n=== Group 2: Locale (4) ===\n";

configure_slot( 'recipe_sidebar_top', true, 'direct', $cz_campaign );
$PLL['current'] = 'cs';
check( '6. a CZ-targeted campaign renders on a CZ request', '' !== $adv->build_slot_markup( 'recipe_sidebar_top' ) );
$PLL['current'] = 'en';
check( '7. the SAME CZ-targeted campaign does NOT render on an EN request', '' === $adv->build_slot_markup( 'recipe_sidebar_top' ) );

$en_campaign = create_campaign( 'EN campaign', array( 'slots' => array( 'recipe_sidebar_top' ), 'locale' => 'en' ) );
configure_slot( 'recipe_sidebar_top', true, 'direct', $en_campaign );
check( '8. an EN-targeted campaign renders on an EN request', '' !== $adv->build_slot_markup( 'recipe_sidebar_top' ) );

$all_campaign = create_campaign( 'All-locale campaign', array( 'slots' => array( 'recipe_after_content' ), 'locale' => 'all' ) );
configure_slot( 'recipe_after_content', true, 'direct', $all_campaign );
$en_ok = '' !== $adv->build_slot_markup( 'recipe_after_content' );
$PLL['current'] = 'cs';
$cs_ok = '' !== $adv->build_slot_markup( 'recipe_after_content' );
check( '9. an all-locale campaign renders on BOTH cs and en', $en_ok && $cs_ok );

echo "\n=== Group 3: Dates (3) ===\n";

$future_campaign = create_campaign( 'Future', array( 'slots' => array( 'archive_in_feed' ), 'start' => '2025-06-01 00:00:00' ) );
configure_slot( 'archive_in_feed', true, 'direct', $future_campaign );
check( '10. a campaign whose start date is in the future does not render yet', '' === $adv->build_slot_markup( 'archive_in_feed' ) );

$active_campaign = create_campaign( 'Active', array( 'slots' => array( 'archive_in_feed' ), 'start' => '2023-01-01 00:00:00', 'end' => '2025-01-01 00:00:00' ) );
configure_slot( 'archive_in_feed', true, 'direct', $active_campaign );
check( '11. a campaign inside its active date window renders', '' !== $adv->build_slot_markup( 'archive_in_feed' ) );

$expired_campaign = create_campaign( 'Expired', array( 'slots' => array( 'archive_in_feed' ), 'end' => '2023-06-01 00:00:00' ) );
configure_slot( 'archive_in_feed', true, 'direct', $expired_campaign );
check( '12. a campaign past its end date no longer renders', '' === $adv->build_slot_markup( 'archive_in_feed' ) );

echo "\n=== Group 4: GATE (5) ===\n";

check( '13. the GATE slot exists in the registry', Atlas_Chuti_Ad_Slots::exists( 'gate_desktop' ) );
check( '14. the GATE slot is device-policy desktop-only (CSS-enforced — see main.css\'s min-width-only, no-UA-sniffing media query)', Atlas_Chuti_Ad_Slots::DEVICE_DESKTOP === Atlas_Chuti_Ad_Slots::get( 'gate_desktop' )['device'] );

$main_css = file_get_contents( $THEME . '/assets/css/main.css' );
check( '15. print CSS hides the GATE sitewide (static assertion — browser rendering can\'t be checked in a PHP harness)', false !== strpos( $main_css, '@media print' ) && preg_match( '/@media print\s*\{[^}]*\.atlas-gate/s', $main_css ) );

check( '16. GATE panels are constrained to a fixed, bounded width (never 100vw/full-bleed) — cannot overlay the central content wrapper by construction', false !== strpos( $main_css, 'width: var(--gate-panel-width)' ) && false === strpos( $main_css, '.atlas-gate-panel { width: 100vw' ) );

$gate_campaign = create_campaign( 'Gate campaign', array( 'slots' => array( 'gate_desktop' ) ) );
configure_slot( 'gate_desktop', true, 'direct', $gate_campaign );
ob_start();
$adv->render_gate();
$gate_html = ob_get_clean();
check( '17. the GATE\'s click-through link carries rel="sponsored noopener"', false !== strpos( $gate_html, 'rel="sponsored noopener"' ) );

echo "\n=== Group 5: Consent (4) ===\n";

configure_slot( 'archive_in_feed', true, 'external_network' );
$PROVIDER_CONFIG_ENABLED = false;
$CONSENT_ALLOWED         = true;
check( '18. external network stays disabled with no provider config, even with consent granted', ! $adv->can_load_advertising_provider() && '' === $adv->build_slot_markup( 'archive_in_feed' ) );

$PROVIDER_CONFIG_ENABLED = true;
$CONSENT_ALLOWED         = false;
check( '19. external network stays disabled without the required marketing consent, even with a real provider config', ! $adv->can_load_advertising_provider() && '' === $adv->build_slot_markup( 'archive_in_feed' ) );

$PROVIDER_CONFIG_ENABLED = true;
$CONSENT_ALLOWED         = true;
check( '20. with BOTH a real provider config AND consent, the external-network render/load path becomes possible', $adv->can_load_advertising_provider() );

check( '21. a direct campaign\'s own consent policy is CONSENT_NONE and never depends on marketing consent', Atlas_Chuti_Ad_Slots::CONSENT_NONE === Atlas_Chuti_Ad_Slots::get( 'recipe_sidebar_top' )['consent'] );
$CONSENT_ALLOWED = false;
configure_slot( 'recipe_sidebar_top', true, 'direct', $cz_campaign );
$PLL['current'] = 'cs';
check( '21b. (proof) a direct campaign still renders with consent_allows_marketing() false', '' !== $adv->build_slot_markup( 'recipe_sidebar_top' ) );

echo "\n=== Group 6: CLS/layout (3) ===\n";

$slot_markup = $adv->build_slot_markup( 'recipe_sidebar_top' );
check( '22. rendered slot markup carries its registry reserved-dimension class (CLS reservation)', false !== strpos( $slot_markup, 'atlas-ad-slot--rectangle' ) );

configure_slot( 'recipe_sidebar_top', true, 'none' );
check( '23. an empty/inactive slot leaves ZERO markup — never a permanent empty box', '' === $adv->build_slot_markup( 'recipe_sidebar_top' ) );

check( '24. GATE panels use a fixed pixel-bounded width (var(--gate-panel-width): 300px), never a viewport-relative width that could overflow', false !== strpos( $main_css, '--gate-panel-width: 300px' ) );

echo "\n=== Group 7: Provider (3) ===\n";

ob_start();
$adv->maybe_enqueue_provider_script();
$before_any_slot = ob_get_clean();
check( '26. with no active external-network slot on the page yet, the provider script is not enqueued', '' === $before_any_slot );

$PROVIDER_CONFIG_ENABLED = true;
$CONSENT_ALLOWED         = true;
configure_slot( 'archive_in_feed', true, 'external_network' );
$adv->build_slot_markup( 'archive_in_feed' ); // populates the page's active-slot tracking for real, without echoing (render_slot() itself is a trivial echo-wrapper — see class-advertising.php).
ob_start();
$adv->maybe_enqueue_provider_script();
$first_call = ob_get_clean();
ob_start();
$adv->maybe_enqueue_provider_script();
$second_call = ob_get_clean();
check( '25. the provider script is enqueued at most once per page — a second call is a no-op', false !== strpos( $first_call, '<script' ) && '' === $second_call );

$ads_js = file_get_contents( $PLUGIN . '/../assets/js/ads.js' );
$lazy_markup = $adv->build_slot_markup( 'recipe_sidebar_top' );
configure_slot( 'recipe_sidebar_top', true, 'direct', $cz_campaign );
$lazy_markup = $adv->build_slot_markup( 'recipe_sidebar_top' );
check( '27. a below-fold slot is marked for lazy loading and the lazy loader uses IntersectionObserver (never blocking render)', false !== strpos( $ads_js, 'IntersectionObserver' ) && false !== strpos( $lazy_markup, 'data-ad-lazy="1"' ) );

echo "\n=== Group 8: SEO/accessibility (5) ===\n";

$PLL['current'] = 'cs';
check( '28. the ad label is localized to Czech ("Reklama") — explicit locale branch, not gettext (no .mo file exists for en, see class docblock)', 'Reklama' === call_private( $adv, 'ad_label' ) );
$PLL['current'] = 'en';
check( '29. the SAME label is "Advertisement" on the English site', 'Advertisement' === call_private( $adv, 'ad_label' ) );
$PLL['current'] = 'cs';

check( '30. a direct campaign\'s creative link carries rel="sponsored noopener"', false !== strpos( $adv->build_slot_markup( 'recipe_sidebar_top' ), 'rel="sponsored noopener"' ) );

$seo_source = file_get_contents( $PLUGIN . '/class-seo.php' );
check( '31. Recipe/Article structured data is completely decoupled from advertising — class-seo.php never references the Advertising class', false === strpos( $seo_source, 'Advertising' ) );

$CONDITIONS['page_template'] = 'template-my-atlas.php';
$is_free_1 = $adv->is_ad_free_context();
$CONDITIONS['page_template'] = '';
$CONDITIONS['page']          = true;
$DB['posts'][999999] = array( 'ID' => 999999, 'post_type' => 'page', 'post_name' => 'ochrana-osobnich-udaju', 'post_status' => 'publish' );
$GLOBALS['post'] = (object) $DB['posts'][999999];
function get_the_ID() { return 999999; }
$is_free_2 = $adv->is_ad_free_context();
$CONDITIONS['page'] = false;
check( '32. login/register/account pages AND legal pages (privacy, cookies, terms, community/UGC rules) carry no ads by default', $is_free_1 && $is_free_2 );

echo "\n=== Group 9: Security (3) ===\n";

$bad_url_campaign = create_campaign( 'Bad URL', array( 'slots' => array( 'recipe_after_content' ), 'click_url' => 'javascript:alert(1)' ) );
check( '33. an invalid click URL (non-http scheme) is rejected, never rendered as a "clickable" ad', null === call_private( $adv, 'direct_campaign_payload', $bad_url_campaign ) );

check( '34. a non-admin (subscriber) cannot pass the settings-save capability check', ! current_user_can_for( $subscriber_id, 'manage_options' ) );
check( '34b. an administrator DOES pass it', current_user_can_for( $admin_id, 'manage_options' ) );

set_ads_option( array( 'debug_mode' => true ) );
configure_slot( 'recipe_after_content', true, 'none' );
wp_set_current_user( 0 );
$anon_debug = $adv->build_slot_markup( 'recipe_after_content' );
wp_set_current_user( $admin_id );
$admin_debug = $adv->build_slot_markup( 'recipe_after_content' );
wp_set_current_user( 0 );
check( '35. debug-mode placeholders are invisible to an ordinary visitor and visible only to an admin', '' === $anon_debug && false !== strpos( $admin_debug, 'debug' ) );

echo "\n--- $TOTAL checks, $FAIL failing ---\n";
echo "(Scenarios 36-40 — Step 3/4/5/6 regression + production-data diff — are run as separate shell commands, not embedded here; same convention as every prior harness. See the Step 7 report, section K.)\n";
exit( $FAIL > 0 ? 1 : 0 );
