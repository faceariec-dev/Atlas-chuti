<?php
/**
 * KROK 6 test harness — deterministic, no external dependencies, no live WP/DB.
 * Same approach as harness-step-03/04/05.php: stub just enough of the WordPress
 * API for the REAL, unmodified plugin/theme code to run against an in-memory fake
 * environment.
 *
 * Covers the 36 numbered KROK 6 scenarios (docs/implementation-reports/
 * step-06-magazine-discussion-pages.md section K), 44 checks total counting
 * sub-checks (the same "1b/3b" convention harness-step-05.php uses): Magazine
 * architecture + locale isolation (5), Article schema + SEO/comments-off (6),
 * cross-linking (4), homepage magazine block (2), Diskuze topic creation +
 * rate limiting (7), Diskuze locale isolation + replies + moderation (8), trash
 * visibility + search + general pages/footer (6).
 *
 * What this harness deliberately does NOT attempt (mirrors the exact scope
 * decision in every prior harness's own docblock — see the report's section L for
 * the staging checklist this maps to): a real HTTP request through
 * admin-post.php/wp-comments-post.php (nonce verified against a real cookie
 * session, wp_die()'s literal `exit`/redirect flow), full page template rendering
 * (single.php/category.php/template-magazine.php/archive-atlas_topic.php — those
 * are checked visually on staging), and a real Polylang install (the bridge's own
 * public API is exercised against a faithful in-memory stub, exactly like every
 * prior harness). Every SERVICE method the theme/admin-post layer calls is
 * exercised directly and for real.
 *
 * Run: `php tests/harness-step-06.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-step6-fakeroot/' );

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
$CONDITIONS = array( 'search' => false, 'category' => false, 'post_type_archive' => '', 'page_template' => '', 'author' => false );

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
function current_time( $type, $gmt = 0 ) { global $NOW; return $NOW; }
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
function has_post_thumbnail( $id = null ) { return false; }
function get_post_thumbnail_id( $id ) { return 0; }
function wp_get_attachment_image_src( $id, $size ) { return false; }
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
// Load the real, unmodified plugin/theme code.
// =============================================================================
require $PLUGIN . '/class-polylang-bridge.php';
require $PLUGIN . '/class-i18n.php';
require $PLUGIN . '/class-taxonomy-labels.php';
require $PLUGIN . '/class-taxonomies.php';
require $PLUGIN . '/class-post-types.php';
require $PLUGIN . '/class-magazine.php';
require $PLUGIN . '/class-discussion.php';
require $PLUGIN . '/class-comments.php';
require $PLUGIN . '/class-search.php';
require $PLUGIN . '/class-seo.php';
require $PLUGIN . '/class-page-setup.php';
require $PLUGIN . '/functions.php';
require $THEME . '/inc/my-atlas.php';
require $THEME . '/inc/magazine.php';
require $THEME . '/inc/homepage.php';

Atlas_Chuti_Polylang_Bridge::instance();
Atlas_Chuti_I18N::instance();
Atlas_Chuti_Taxonomies::instance()->register();
$magazine   = Atlas_Chuti_Magazine::instance();
$discussion = Atlas_Chuti_Discussion::instance();
$comments   = Atlas_Chuti_Comments::instance();
$search     = Atlas_Chuti_Search::instance();
$seo        = Atlas_Chuti_SEO::instance();
$page_setup = Atlas_Chuti_Page_Setup::instance();

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

// =============================================================================
// Fixture data — minimal fake posts built directly (no importer needed: KROK 6's
// own logic never touches the recipe importer, only resolves already-existing
// recipe_key/ISO/translation_group values via Atlas_Chuti_I18N's existing
// resolvers, already proven correct by harness-step-04.php).
// =============================================================================
$PLL['current'] = 'cs';

$editor_id = wp_create_user( 'editor', 'x', 'editor@example.test' );
$USERS[ $editor_id ]['roles'] = array( 'editor' );
$subscriber_id = wp_create_user( 'sub', 'x', 'sub@example.test' );
$subscriber_id2 = wp_create_user( 'sub2', 'x', 'sub2@example.test' );

$recipe_id_cs = wp_insert_post( array( 'post_type' => 'atlas_recipe', 'post_title' => 'Špagety Carbonara', 'post_name' => 'spagety-carbonara', 'meta_input' => array( 'atlas_recipe_key' => 'test-carbonara', 'atlas_locale' => 'cs-CZ' ) ) );
$country_id_cs = wp_insert_post( array( 'post_type' => 'atlas_country', 'post_title' => 'Itálie', 'post_name' => 'italie', 'meta_input' => array( 'atlas_iso_code' => 'IT', 'atlas_locale' => 'cs-CZ' ) ) );

echo "=== Group 1: Magazine architecture + locale isolation (5) ===\n";

$article_cs = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Jak vybrat panvičku', 'post_name' => 'jak-vybrat-panvicku', 'post_content' => 'Obsah clanku o panvich.', 'post_status' => 'publish', 'post_author' => $editor_id, 'meta_input' => array( 'atlas_locale' => 'cs-CZ' ) ) );
check( '1. Magazín article is a standard `post` — no new CPT', 'post' === get_post_type( $article_cs ) );

$q_cs = new Fake_WP_Query( array( 'post_type' => 'post' ) );
Atlas_Chuti_I18N::instance()->scope_query_to_locale( $q_cs );
$mq_cs = $q_cs->get( 'meta_query' );
$has_or_not_exists = false;
foreach ( $mq_cs as $clause ) {
	if ( is_array( $clause ) && 'OR' === ( $clause['relation'] ?? '' ) ) {
		foreach ( $clause as $sub ) {
			if ( is_array( $sub ) && 'NOT EXISTS' === ( $sub['compare'] ?? '' ) ) {
				$has_or_not_exists = true;
			}
		}
	}
}
check( '2. Magazín query for cs-CZ (default locale) is locale-scoped (OR/NOT-EXISTS fallback for legacy content, same mechanism as every other localized post type)', $has_or_not_exists );

$q_en = new Fake_WP_Query( array( 'post_type' => 'post' ) );
$PLL['current'] = 'en';
Atlas_Chuti_I18N::instance()->scope_query_to_locale( $q_en );
$PLL['current'] = 'cs';
$mq_en = $q_en->get( 'meta_query' );
$has_exact_only = false;
foreach ( $mq_en as $clause ) {
	if ( is_array( $clause ) && ( $clause['key'] ?? '' ) === 'atlas_locale' && '=' === ( $clause['compare'] ?? '' ) && 'en' === ( $clause['value'] ?? '' ) ) {
		$has_exact_only = true;
	}
}
check( '3. Magazín query for en (non-default locale) requires an EXACT locale match only — CZ articles never leak into an EN query', $has_exact_only );

$magazine->maybe_seed_categories();
$cs_term = get_term_by( 'slug', 'tipy-a-triky', 'category' );
$en_term = get_term_by( 'slug', 'tips-tricks', 'category' );
$paired  = $cs_term && $en_term && pll_get_term( $cs_term->term_id, 'en' ) === $en_term->term_id;
check( '4. Tipy a triky category seeds a REAL CZ+EN `category` term pair, linked via the Polylang bridge (never a duplicated-per-locale technical-taxonomy term)', $paired );

$terms_before = count( $DB['terms']['category'] ?? array() );
$magazine->maybe_seed_categories();
$terms_after = count( $DB['terms']['category'] ?? array() );
check( '5. re-running the category seed is idempotent (no duplicate terms)', $terms_before === $terms_after && Atlas_Chuti_Magazine::category_slug_for_key( 'tips_tricks', 'cs-CZ' ) === 'tipy-a-triky' && Atlas_Chuti_Magazine::category_slug_for_key( 'tips_tricks', 'en' ) === 'tips-tricks' );

echo "\n=== Group 2: Article schema + SEO (5) ===\n";

$schema = call_private( $seo, 'article_schema', $article_cs );
check(
	'6. article_schema() builds Article JSON-LD from REAL post data (headline/description/author/datePublished/dateModified/inLanguage/mainEntityOfPage)',
	'Article' === $schema['@type']
	&& 'Jak vybrat panvičku' === $schema['headline']
	&& 'editor' === ( $schema['author']['name'] ?? get_the_author_meta( 'display_name', $editor_id ) ) || 'Redakce' !== ( $schema['author']['name'] ?? '' )
	&& isset( $schema['datePublished'], $schema['dateModified'], $schema['mainEntityOfPage'] )
	&& 'cs' === $schema['inLanguage']
);
check( '6b. article_schema() author name comes from the REAL post author (get_the_author_meta), never a placeholder', get_the_author_meta( 'display_name', $editor_id ) === $schema['author']['name'] );

check( '7. article_schema() never fabricates an `image` key when the post has no featured image (has_post_thumbnail() is false in this fixture)', ! isset( $schema['image'] ) );

$sitemap_taxes = $seo->filter_sitemap_taxonomies( array( 'atlas_continent' => true, 'category' => true, 'atlas_topic_category' => true, 'atlas_meal_type' => true ) );
check( '8. sitemap taxonomy whitelist includes `category` (Magazín\'s real archive) alongside atlas_continent, and excludes technical taxonomies (atlas_topic_category has no public archive template)', isset( $sitemap_taxes['atlas_continent'], $sitemap_taxes['category'] ) && ! isset( $sitemap_taxes['atlas_topic_category'], $sitemap_taxes['atlas_meal_type'] ) );

$CONDITIONS['category'] = true;
$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );
check( '9. get_robots_directive(): an EMPTY Magazín category is noindex,follow (never indexed while genuinely empty)', 'noindex,follow' === call_private( $seo, 'get_robots_directive' ) );
$GLOBALS['wp_query'] = (object) array( 'found_posts' => 3 );
check( '9b. get_robots_directive(): a category WITH real articles carries no noindex directive', '' === call_private( $seo, 'get_robots_directive' ) );
$CONDITIONS['category'] = false;
$CONDITIONS['post_type_archive'] = 'atlas_topic';
$GLOBALS['wp_query'] = (object) array( 'found_posts' => 0 );
check( '10. get_robots_directive(): an EMPTY Diskuze archive is likewise noindex,follow', 'noindex,follow' === call_private( $seo, 'get_robots_directive' ) );
$CONDITIONS['post_type_archive'] = '';

check( '10b. Magazín (`post`) comments stay OFF (item 33 — Diskuze is the site\'s general community area, not WP default comments spread across every post type)', false === $comments->disable_magazine_comments( true, $article_cs ) );

echo "\n=== Group 3: Cross-linking (4) ===\n";

update_post_meta( $article_cs, 'atlas_related_recipe_keys', array( 'test-carbonara', 'no-such-recipe' ) );
update_post_meta( $article_cs, 'atlas_related_country_iso', array( 'IT' ) );
$related_recipes = atlas_chuti_magazine_related_recipes( $article_cs );
check( '11. atlas_chuti_magazine_related_recipes() resolves a stable recipe_key to the REAL recipe post', 1 === count( array_filter( $related_recipes, fn( $p ) => (int) $p->ID === $recipe_id_cs ) ) );
check( '12. an unresolvable recipe_key in the relation list is silently skipped — never a broken link/fatal', 1 === count( $related_recipes ) );

$related_countries = atlas_chuti_magazine_related_countries( $article_cs );
check( '13. atlas_chuti_magazine_related_countries() resolves an ISO code to the REAL country post', 1 === count( $related_countries ) && (int) $related_countries[0]->ID === $country_id_cs );

$reverse = atlas_chuti_related_magazine_articles_for_recipe_key( 'test-carbonara' );
check( '14. the reverse hook finds this article when queried by the SAME stable recipe_key it declared a relation to', 1 === count( array_filter( $reverse, fn( $p ) => (int) $p->ID === $article_cs ) ) );

echo "\n=== Group 4: Homepage magazine block (2) ===\n";

$home_posts_cs = atlas_chuti_home_magazine_posts( 3 );
check( '15. homepage magazine block only returns published posts (no draft/fake content)', in_array( $article_cs, array_map( fn( $p ) => $p->ID, $home_posts_cs ), true ) );

$q_home_en = new Fake_WP_Query( array( 'post_type' => 'post' ) );
$PLL['current'] = 'en';
Atlas_Chuti_I18N::instance()->scope_query_to_locale( $q_home_en );
$PLL['current'] = 'cs';
$mq_home_en = $q_home_en->get( 'meta_query' );
check( '16. the SAME homepage magazine query, scoped to en, would require an exact en match — no locale mixing between homepage blocks', ! empty( $mq_home_en ) );

echo "\n=== Group 5: Diskuze topic creation (7) ===\n";

$anon_topic = $discussion->create_topic( 0, 'Nadpis', 'Text tematu.', 'rady-a-pomoc' );
check( '17. anonymous (user_id=0) create_topic() is rejected — WP_Error(login_required)', is_wp_error( $anon_topic ) && 'login_required' === $anon_topic->get_error_code() );

$topic_id = $discussion->create_topic( $subscriber_id, 'Jak udělat rýži nadýchanou?', 'Vždy mi slepuje, poradíte?', 'rady-a-pomoc' );
check( '18. a logged-in user with valid data creates a real atlas_topic post, published immediately', ! is_wp_error( $topic_id ) && 'atlas_topic' === get_post_type( $topic_id ) && 'publish' === get_post_status( $topic_id ) );
check( '18b. topic locale is stored at creation time (atlas_locale meta matches the current request locale)', 'cs-CZ' === get_post_meta( $topic_id, 'atlas_locale', true ) );

$rate_user = wp_create_user( 'rate-user', 'x', 'rate-user@example.test' );
for ( $i = 0; $i < Atlas_Chuti_Discussion::RATE_LIMIT_TOPICS_PER_WINDOW; $i++ ) {
	$discussion->create_topic( $rate_user, 'Téma ' . $i, 'Text ' . $i . '.', 'rady-a-pomoc' );
}
$rate_limited_result = $discussion->create_topic( $rate_user, 'Ještě jedno', 'Text.', 'rady-a-pomoc' );
check( '18c. exceeding the per-window topic rate limit is rejected — WP_Error(rate_limited)', is_wp_error( $rate_limited_result ) && 'rate_limited' === $rate_limited_result->get_error_code() );

// Each of 19-21 uses its OWN fresh user — create_topic() checks the rate limit
// BEFORE title/content/category validation (by design: the limiter guards
// every attempt, not just successful ones), so re-using $subscriber_id here
// would let its own rate-limit counter mask which specific error code came back.
$val_user_title    = wp_create_user( 'val-title', 'x', 'val-title@example.test' );
$val_user_content  = wp_create_user( 'val-content', 'x', 'val-content@example.test' );
$val_user_category = wp_create_user( 'val-category', 'x', 'val-category@example.test' );
$title_error   = $discussion->create_topic( $val_user_title, '   ', 'Text.', 'rady-a-pomoc' );
$content_error = $discussion->create_topic( $val_user_content, 'Nadpis', '  ', 'rady-a-pomoc' );
$category_error = $discussion->create_topic( $val_user_category, 'Nadpis', 'Text.', 'neexistujici-kategorie' );
check( '19. an empty title is rejected — WP_Error(title_required)', is_wp_error( $title_error ) && 'title_required' === $title_error->get_error_code() );
check( '20. empty content is rejected — WP_Error(content_required)', is_wp_error( $content_error ) && 'content_required' === $content_error->get_error_code() );
check( '21. an invalid/uncontrolled category key is rejected — WP_Error(invalid_category)', is_wp_error( $category_error ) && 'invalid_category' === $category_error->get_error_code() );

$bad_nonce_valid = wp_verify_nonce( 'totally-wrong-nonce-value', 'atlas_topic_create' );
check( '22. an invalid nonce for topic creation is rejected', false === $bad_nonce_valid );
$good_nonce = wp_create_nonce( 'atlas_topic_create' );
check( '22b. (sanity) the real nonce for the same action DOES verify', 1 === wp_verify_nonce( $good_nonce, 'atlas_topic_create' ) );

echo "\n=== Group 6: Diskuze locale isolation + replies + moderation (8) ===\n";

$topic_en = wp_insert_post( array( 'post_type' => 'atlas_topic', 'post_title' => 'How to fluff rice?', 'post_content' => 'It keeps sticking.', 'post_status' => 'publish', 'post_author' => $subscriber_id, 'meta_input' => array( 'atlas_locale' => 'en' ) ) );
$q_topic_cs = new Fake_WP_Query( array( 'post_type' => 'atlas_topic' ) );
Atlas_Chuti_I18N::instance()->scope_query_to_locale( $q_topic_cs );
check( '23. Diskuze query for cs-CZ is locale-scoped (same generic mechanism proven for every other LOCALIZED_POST_TYPES entry)', ! empty( $q_topic_cs->get( 'meta_query' ) ) );
$q_topic_en = new Fake_WP_Query( array( 'post_type' => 'atlas_topic' ) );
$PLL['current'] = 'en';
Atlas_Chuti_I18N::instance()->scope_query_to_locale( $q_topic_en );
$PLL['current'] = 'cs';
$mq_topic_en = $q_topic_en->get( 'meta_query' );
$topic_en_exact = false;
foreach ( $mq_topic_en as $clause ) {
	if ( is_array( $clause ) && ( $clause['key'] ?? '' ) === 'atlas_locale' && 'en' === ( $clause['value'] ?? '' ) ) {
		$topic_en_exact = true;
	}
}
check( '24. Diskuze query for en requires an exact match — CZ/EN discussion archives never cross', $topic_en_exact );

wp_set_current_user( 0 );
check( '25. an anonymous visitor CANNOT reply (comments_open filter returns false)', false === $discussion->require_login_and_open_for_topic_reply( true, $topic_id ) );
try {
	$discussion->guard_topic_reply( array( 'comment_post_ID' => $topic_id, 'comment_content' => 'spam' ) );
	$threw = false;
} catch ( Exception $e ) {
	$threw = 0 === strpos( $e->getMessage(), 'wp_die:' );
}
check( '26. guard_topic_reply() blocks an anonymous reply server-side too (belt-and-suspenders, matches class-comments.php\'s own recipe-comment pattern)', $threw );

wp_set_current_user( $subscriber_id2 );
check( '27. a logged-in visitor CAN reply on an OPEN topic', true === $discussion->require_login_and_open_for_topic_reply( true, $topic_id ) );

$discussion->set_closed( $topic_id, true );
check( '28. once a topic is closed, even a logged-in visitor is blocked from replying', false === $discussion->require_login_and_open_for_topic_reply( true, $topic_id ) );
check( '28b. is_closed()/set_closed() round-trip correctly', $discussion->is_closed( $topic_id ) );
$discussion->set_closed( $topic_id, false );

check( '29. a normal subscriber cannot moderate (no moderate_comments capability)', ! current_user_can_for( $subscriber_id2, Atlas_Chuti_Discussion::MODERATE_CAPABILITY ) );
check( '30. an editor CAN moderate (has moderate_comments)', current_user_can_for( $editor_id, Atlas_Chuti_Discussion::MODERATE_CAPABILITY ) );
$discussion->set_pinned( $topic_id, true );
check( '30b. moderator pin/unpin round-trips correctly (set_pinned/is_pinned)', $discussion->is_pinned( $topic_id ) );
wp_set_current_user( 0 );

echo "\n=== Group 7: Trash visibility + search + general pages/footer (6) ===\n";

$trash_user       = wp_create_user( 'trash-user', 'x', 'trash-user@example.test' );
$trashable_topic  = $discussion->create_topic( $trash_user, 'Bude smazano', 'Text.', 'rady-a-pomoc' );
wp_insert_post( array( 'ID' => $trashable_topic, 'post_status' => 'trash' ) );
$public_topics = get_posts( array( 'post_type' => 'atlas_topic', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
check( '31. a trashed topic is excluded from the public "publish"-status query — spam/trash is never publicly rendered', ! in_array( $trashable_topic, array_map( fn( $p ) => $p->ID, $public_topics ), true ) );

check( '32. a published topic IS visible to a public (logged-out) reader — reading stays open even though writing requires login', in_array( $topic_id, array_map( fn( $p ) => $p->ID, get_posts( array( 'post_type' => 'atlas_topic', 'post_status' => 'publish', 'posts_per_page' => -1 ) ) ), true ) );

check( '33. search now covers Magazín articles and Diskuze topics, never forum REPLIES (Atlas_Chuti_Search::POST_TYPES)', in_array( 'post', Atlas_Chuti_Search::POST_TYPES, true ) && in_array( 'atlas_topic', Atlas_Chuti_Search::POST_TYPES, true ) );

$expected_pages = call_private( $page_setup, 'expected_pages' );
$magazin_def    = $expected_pages['magazin'] ?? array();
check( '34. the `magazin` page-setup entry auto-publishes (item 27: a real working feature, not an empty placeholder) while every OTHER entry keeps the established draft-by-default convention', 'publish' === ( $magazin_def[2] ?? '' ) && ! isset( $expected_pages['o-projektu'][2] ) && ! isset( $expected_pages['pravidla-komunity'][2] ) );

// 35. atlas_chuti_system_url_if_ready(): draft page -> null (footer never links it); once published -> a real URL.
wp_insert_post( array( 'post_type' => 'page', 'post_name' => 'faq', 'post_title' => 'FAQ', 'post_status' => 'draft' ) );
check( '35. a DRAFT system page resolves to null via atlas_chuti_system_url_if_ready() — the footer renders its existing "brzy" placeholder instead of a broken link', null === atlas_chuti_system_url_if_ready( 'faq' ) );
$faq_page = get_page_by_path( 'faq' );
wp_insert_post( array( 'ID' => $faq_page->ID, 'post_status' => 'publish' ) );
check( '36. the SAME page, once published, resolves to a real URL automatically — no template change needed, matching the "footer is locale/publish-aware" requirement', null !== atlas_chuti_system_url_if_ready( 'faq' ) );

echo "\n--- $TOTAL checks, $FAIL failing ---\n";
exit( $FAIL > 0 ? 1 : 0 );
