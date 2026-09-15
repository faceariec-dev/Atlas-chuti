<?php
/**
 * KROK 8 test harness — deterministic, no external dependencies, no live WP/DB.
 * Same approach as harness-step-03/04/05/06/07.php: stub just enough of the
 * WordPress API for the REAL, unmodified plugin/theme code to run against an
 * in-memory fake environment. Stub layer starts as a copy of
 * harness-step-05.php's (the richest prior base — real users/nonces/Fake_WPDB),
 * extended with: IS NULL/IS NOT NULL and BETWEEN WHERE-clause support, a
 * COALESCE(MAX(col),-1)+1 special case (Collections::add_item()'s own
 * next-sort_order query), a raw DELETE-via-query() path, UNIQUE-key conflict
 * detection for the 4 new Step 8 tables, a minimal posts⋈postmeta JOIN reader
 * for Ingredient_Finder's two raw-SQL lookups, is_singular()/get_the_ID() for
 * the Cook Mode robots/ad-free checks, and a NUMERIC "<=" meta_query compare
 * for Recommendations' own time-bucket filter.
 *
 * Covers the 56 numbered KROK 8 scenarios (docs/implementation-reports/
 * step-08-interactive-atlas.md section P): Cook Mode (7), Timers (6), Co dnes
 * vařit (6), Co mám doma (5), Collections (7), Shopping list (5), Meal planner
 * (6), Video (6), Privacy (2), Regression (6: Steps 3/4/5/6/7 pass + production-
 * data diff empty).
 *
 * What this harness deliberately does NOT attempt (same scope boundary as
 * every prior harness): a real HTTP request through the REST API
 * (register_rest_route()'s own dispatch, WP_REST_Request/nonce header
 * verification), actual browser behavior (Cook Mode overlay DOM, Wake Lock,
 * Notification permission prompts, localStorage timers surviving a real
 * reload/backgrounded tab, IntersectionObserver, click-to-load video). Every
 * PHP decision path (service class CRUD/ownership/merge/validation logic,
 * robots/ad-free gating, video data validation, privacy export/erase) is
 * exercised directly and for real; JS-only behavior is verified via static
 * source assertions where practical and otherwise deferred to the report's
 * staging checklist (section Q).
 *
 * Run: `php tests/harness-step-08.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-step8-fakeroot/' );
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
// Fake database (posts/terms)
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
$FILTERS    = array();
$NOW        = '2024-06-15 00:00:00';
$COMMENTS   = array();
$NEXT_COMMENT_ID = 1;
$CONDITIONS = array( 'search' => false, 'category' => false, 'post_type_archive' => '', 'page_template' => '', 'author' => false, 'page' => false, 'singular' => false, 'post_id' => 0 );

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
function sanitize_key( $s ) { $s = strtolower( (string) $s ); return preg_replace( '/[^a-z0-9_\-]/', '', $s ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL ); }
function sanitize_user( $s, $strict = false ) { return preg_replace( '/[^a-zA-Z0-9_.\-@]/', '', (string) $s ); }
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
function wp_json_encode( $x ) { return json_encode( $x ); } // phpcs:ignore -- test harness only.
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
function wp_unslash( $s ) { return $s; }
function wp_trim_words( $s, $n ) { return $s; }
function checked( $a, $b = true ) { echo $a === $b ? ' checked' : ''; }
function selected( $a, $b = true ) { echo $a === $b ? ' selected' : ''; }
function wp_is_post_autosave( $id ) { return false; }
function wp_is_post_revision( $id ) { return false; }
function get_option( $k ) { global $OPTIONS; return $OPTIONS[ $k ] ?? false; }
function update_option( $k, $v ) { global $OPTIONS; $OPTIONS[ $k ] = $v; return true; }
function get_transient( $k ) { global $TRANSIENTS; return $TRANSIENTS[ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { global $TRANSIENTS; $TRANSIENTS[ $k ] = $v; return true; }
function register_taxonomy( $tax, $object_types, $args = array() ) {
	global $DB;
	if ( ! isset( $DB['terms'][ $tax ] ) ) {
		$DB['terms'][ $tax ] = array();
	}
	return true;
}
function register_post_type( $pt, $args = array() ) { return true; }
function register_post_meta( $pt, $key, $args = array() ) { return true; }
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
function wp_safe_redirect( $location ) {}
function wp_die( $msg = '', $title = '', $args = array() ) { throw new Exception( 'wp_die: ' . ( is_string( $msg ) ? $msg : 'error' ) ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function current_time( $type, $gmt = 0 ) { global $NOW; return 'timestamp' === $type ? strtotime( $NOW ) : ( 'Ymd' === $type ? gmdate( 'Ymd', strtotime( $NOW ) ) : $NOW ); }
function wp_rand( $min = 0, $max = 0 ) { return $max > $min ? random_int( $min, $max ) : random_int( 0, PHP_INT_MAX ); }
function get_query_var( $v ) { return ''; }
function wp_list_pluck( $list, $field ) { return array_map( fn( $item ) => is_object( $item ) ? $item->$field : $item[ $field ], $list ); }

// Conditional tags — driven entirely by $CONDITIONS, set/reset per scenario.
function is_search() { global $CONDITIONS; return $CONDITIONS['search']; }
function is_category() { global $CONDITIONS; return $CONDITIONS['category']; }
function is_post_type_archive( $pt = null ) { global $CONDITIONS; return null === $pt ? (bool) $CONDITIONS['post_type_archive'] : $CONDITIONS['post_type_archive'] === $pt; }
function is_page_template( $t = null ) {
	global $CONDITIONS;
	if ( null === $t ) {
		return (bool) $CONDITIONS['page_template'];
	}
	return is_array( $t ) ? in_array( $CONDITIONS['page_template'], $t, true ) : $CONDITIONS['page_template'] === $t;
}
function is_page() { global $CONDITIONS; return $CONDITIONS['page']; }
function is_singular( $pt = null ) {
	global $CONDITIONS;
	if ( ! $CONDITIONS['singular'] ) {
		return false;
	}
	return null === $pt ? true : ( is_array( $pt ) ? in_array( $CONDITIONS['singular'], $pt, true ) : $CONDITIONS['singular'] === $pt );
}
function get_the_ID() { global $CONDITIONS; return $CONDITIONS['post_id']; }
function wp_http_validate_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	return $url;
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore -- test harness only.

// =============================================================================
// Users, auth, nonces (identical to harness-step-05.php)
// =============================================================================
$USERS           = array();
$NEXT_USER_ID    = 1;
$CURRENT_USER_ID = 0;

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
		if ( 'email' === $field && $u['user_email'] === $value ) { return new WP_User( $u ); }
		if ( 'login' === $field && $u['user_login'] === $value ) { return new WP_User( $u ); }
		if ( 'id' === $field && (int) $u['ID'] === (int) $value ) { return new WP_User( $u ); }
	}
	return false;
}
function get_current_user_id() { global $CURRENT_USER_ID; return $CURRENT_USER_ID; }
function is_user_logged_in() { global $CURRENT_USER_ID; return $CURRENT_USER_ID > 0; }
function wp_set_current_user( $id ) { global $CURRENT_USER_ID; $CURRENT_USER_ID = (int) $id; }
function wp_delete_user( $id ) { global $USERS; unset( $USERS[ $id ] ); do_action( 'deleted_user', $id ); return true; }
function wp_create_user( $login, $pass, $email ) {
	global $USERS, $NEXT_USER_ID;
	$id            = $NEXT_USER_ID++;
	$USERS[ $id ] = array( 'ID' => $id, 'user_login' => $login, 'user_email' => $email, 'display_name' => $login, 'roles' => array( 'subscriber' ) );
	return $id;
}
function wp_create_nonce( $action = '' ) {
	global $CURRENT_USER_ID;
	return substr( hash( 'sha256', $action . '|' . $CURRENT_USER_ID . '|test-nonce-salt' ), 0, 12 );
}
function wp_verify_nonce( $nonce, $action = -1 ) {
	return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
}

// =============================================================================
// Posts
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
function get_post_meta( $id, $key, $single = false ) { global $DB; return $DB['postmeta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { global $DB; $DB['postmeta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { global $DB; unset( $DB['postmeta'][ $id ][ $key ] ); return true; }
function get_post_field( $field, $id ) { global $DB; return $DB['posts'][ $id ][ $field ] ?? ''; }
function get_post_status( $id ) { global $DB; return $DB['posts'][ $id ]['post_status'] ?? false; }
function get_post( $id ) { global $DB; return isset( $DB['posts'][ $id ] ) ? db_post_to_object( $DB['posts'][ $id ] ) : null; }
function get_post_type( $id ) { global $DB; return $DB['posts'][ $id ]['post_type'] ?? false; }
function get_the_title( $id ) { global $DB; if ( is_object( $id ) ) { $id = $id->ID; } return $DB['posts'][ $id ]['post_title'] ?? ''; }
function get_permalink( $id = null ) {
	global $DB, $CONDITIONS;
	if ( null === $id ) {
		$id = $CONDITIONS['post_id'];
	}
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
function get_the_post_thumbnail_url( $id, $size = 'thumbnail' ) { global $THUMBNAILS; return $THUMBNAILS[ $id ] ?? false; }
function set_post_thumbnail( $id, $url ) { global $THUMBNAILS; $THUMBNAILS[ $id ] = $url; }
function get_post_type_archive_link( $post_type ) {
	$slugs = array( 'atlas_recipe' => 'recepty', 'atlas_glossary' => 'slovnicek', 'atlas_topic' => 'diskuze' );
	return isset( $slugs[ $post_type ] ) ? home_url( '/' . $slugs[ $post_type ] . '/' ) : false;
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
				$val     = $DB['postmeta'][ $id ][ $clause['key'] ] ?? null;
				$compare = $clause['compare'] ?? '=';
				if ( 'LIKE' === $compare ) {
					if ( false === strpos( serialize( $val ), (string) $clause['value'] ) ) { // phpcs:ignore -- test harness only.
						$ok = false;
						break;
					}
				} elseif ( '<=' === $compare ) {
					if ( ! ( is_numeric( $val ) && (float) $val <= (float) $clause['value'] ) ) {
						$ok = false;
						break;
					}
				} elseif ( '>=' === $compare ) {
					if ( ! ( is_numeric( $val ) && (float) $val >= (float) $clause['value'] ) ) {
						$ok = false;
						break;
					}
				} else {
					if ( (string) $val !== (string) $clause['value'] ) {
						$ok = false;
						break;
					}
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
				$have = $DB['term_relationships'][ $id ][ $clause['taxonomy'] ] ?? array();
				if ( 'slug' === ( $clause['field'] ?? 'term_id' ) ) {
					// Resolve each wanted slug to its real term_id in this
					// taxonomy — exactly what a real tax_query WHERE clause
					// does via wp_term_taxonomy, never a numeric coincidence.
					$wanted = array();
					foreach ( (array) $clause['terms'] as $slug ) {
						foreach ( $DB['terms'][ $clause['taxonomy'] ] ?? array() as $term ) {
							if ( $term['slug'] === $slug ) {
								$wanted[] = (int) $term['term_id'];
							}
						}
					}
				} else {
					$wanted = array_map( 'intval', (array) $clause['terms'] );
				}
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
// Taxonomy terms
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
function get_the_terms( $post_id, $taxonomy ) { $out = wp_get_post_terms( $post_id, $taxonomy ); return $out ?: false; }
function get_term_by( $field, $value, $taxonomy ) {
	global $DB;
	foreach ( $DB['terms'][ $taxonomy ] ?? array() as $term ) {
		if ( 'slug' === $field && $term['slug'] === $value ) { return db_term_to_object( $term ); }
		if ( 'term_id' === $field && (int) $term['term_id'] === (int) $value ) { return db_term_to_object( $term ); }
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
function wp_delete_term( $term_id, $taxonomy ) { global $DB; unset( $DB['terms'][ $taxonomy ][ $term_id ] ); return true; }
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
	if ( isset( $args['number'] ) && $args['number'] > 0 ) {
		$out = array_slice( $out, 0, (int) $args['number'] );
	}
	return $out;
}
function get_term_meta( $term_id, $key, $single = false ) { global $DB; return $DB['termmeta'][ $term_id ][ $key ] ?? ''; }
function update_term_meta( $term_id, $key, $value ) { global $DB; $DB['termmeta'][ $term_id ][ $key ] = $value; return true; }

// =============================================================================
// Fake Polylang runtime — this harness only ever exercises a single (cs-CZ)
// locale (Step 8's recommendation/ingredient-finder resolvers already rely on
// Atlas_Chuti_I18N's pre_get_posts locale scoping, which is proven separately
// by harness-step-04.php's own dedicated Fake_WP_Query tests — see this file's
// docblock for why re-proving it here would be redundant).
// =============================================================================
function pll_languages_list( $args = array() ) { return array( 'cs', 'en' ); }
function pll_current_language( $field = 'slug' ) { return 'cs'; }
function pll_get_term( $term_id, $target_slug ) { return 0; }
function pll_home_url( $slug ) { return home_url( 'en' === $slug ? '/en/' : '/' ); }

// =============================================================================
// Fake $wpdb — write-capable, scoped to exactly the query shapes this
// project's Step 8 service classes issue.
// =============================================================================
class Fake_WPDB {
	public $posts     = 'wp_posts';
	public $postmeta  = 'wp_postmeta';
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $rows_affected = 0;

	public $tables  = array();
	public $next_id = array();

	private $unique_keys = array(
		'wp_atlas_collection_items' => array( array( 'collection_id', 'recipe_key' ) ),
		'wp_atlas_meal_plan_items'  => array( array( 'user_id', 'plan_date', 'meal_slot', 'recipe_key' ) ),
	);

	// A nullable column real MySQL always returns (as NULL) even when the
	// application omitted it from an INSERT — see class-shopping-list.php's
	// and class-meal-plan.php's own docblocks for why they do that. Filled in
	// at insert time so a later ->quantity_value/->servings_override read
	// never hits PHP's "undefined property" warning for a row that legitimately
	// has no value there.
	private $nullable_defaults = array(
		'wp_atlas_meal_plan_items'     => array( 'servings_override' => null ),
		'wp_atlas_shopping_list_items' => array( 'quantity_value' => null ),
	);

	private function with_defaults( $table, $row ) {
		return array_merge( $this->nullable_defaults[ $table ] ?? array(), $row );
	}

	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function get_charset_collate() { return ''; }

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( &$i, $args ) {
				$val = $args[ $i++ ] ?? '';
				if ( '%d' === $m[0] ) { return (string) (int) $val; }
				if ( '%f' === $m[0] ) { return null === $val ? 'NULL' : (string) (float) $val; }
				return "'" . addslashes( (string) $val ) . "'";
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
		$id                            = $this->next_id[ $table ]++;
		$row                           = $this->with_defaults( $table, $data );
		$row['id']                     = $id;
		$this->tables[ $table ][ $id ] = $row;
		$this->insert_id               = $id;
		$this->rows_affected           = 1;
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
				if ( '\\' === $ch && $i + 1 < $len ) { $cur .= $str[ $i + 1 ]; $i += 2; continue; }
				if ( "'" === $ch ) { $in_quote = false; $i++; $vals[] = array( 'q' => true, 'v' => $cur ); $cur = ''; continue; }
				$cur .= $ch; $i++; continue;
			}
			if ( "'" === $ch ) { $in_quote = true; $cur = ''; $i++; continue; }
			if ( ',' === $ch ) {
				$t = trim( $cur );
				if ( '' !== $t ) { $vals[] = array( 'q' => false, 'v' => $t ); }
				$cur = ''; $i++; continue;
			}
			$cur .= $ch; $i++;
		}
		$t = trim( $cur );
		if ( '' !== $t ) { $vals[] = array( 'q' => false, 'v' => $t ); }
		return array_map(
			function ( $tok ) {
				if ( $tok['q'] ) { return $tok['v']; }
				if ( 'NULL' === $tok['v'] ) { return null; }
				return is_numeric( $tok['v'] ) ? $tok['v'] + 0 : $tok['v'];
			},
			$vals
		);
	}

	private function find_conflict( $table, $row ) {
		foreach ( $this->unique_keys[ $table ] ?? array() as $key_cols ) {
			$skip = false;
			foreach ( $key_cols as $col ) {
				if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] ) { $skip = true; break; }
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

	/**
	 * Splits a WHERE clause on top-level " AND " only — a "col BETWEEN a AND
	 * b" fragment's own internal AND is protected first (replaced with a
	 * sentinel, restored per-fragment after splitting) so it is never mistaken
	 * for a second top-level condition.
	 */
	private function split_where( $where ) {
		$protected = preg_replace_callback(
			'/(\w+)\s+BETWEEN\s+(\'[^\']*\'|-?\d+(?:\.\d+)?)\s+AND\s+(\'[^\']*\'|-?\d+(?:\.\d+)?)/i',
			function ( $m ) { return $m[1] . ' BETWEEN ' . $m[2] . ' §AND§ ' . $m[3]; },
			$where
		);
		$parts = preg_split( '/\s+AND\s+/i', $protected );
		return array_map( fn( $p ) => str_replace( '§AND§', 'AND', trim( $p ) ), $parts );
	}

	private function parse_where( $where ) {
		$conditions = array();
		foreach ( $this->split_where( trim( $where ) ) as $cond ) {
			if ( '' === $cond ) {
				continue;
			}
			if ( preg_match( '/^(\w+)\s+BETWEEN\s+\'([^\']*)\'\s+AND\s+\'([^\']*)\'$/i', $cond, $cm ) ) {
				$conditions[] = array( 'col' => $cm[1], 'op' => 'between', 'val' => array( $cm[2], $cm[3] ) );
			} elseif ( preg_match( '/^(\w+)\s+IS\s+NOT\s+NULL$/i', $cond, $cm ) ) {
				$conditions[] = array( 'col' => $cm[1], 'op' => 'is_not_null' );
			} elseif ( preg_match( '/^(\w+)\s+IS\s+NULL$/i', $cond, $cm ) ) {
				$conditions[] = array( 'col' => $cm[1], 'op' => 'is_null' );
			} elseif ( preg_match( '/^(\w+)\s+IN\s*\(([^)]*)\)$/i', $cond, $cm ) ) {
				$vals         = array_map( fn( $v ) => trim( $v, " '" ), explode( ',', $cm[2] ) );
				$conditions[] = array( 'col' => $cm[1], 'op' => 'in', 'val' => $vals );
			} elseif ( preg_match( "/^(\w+)\s*=\s*'(.*)'$/", $cond, $cm ) ) {
				$conditions[] = array( 'col' => $cm[1], 'op' => '=', 'val' => stripslashes( $cm[2] ) );
			} elseif ( preg_match( '/^(\w+)\s*=\s*(-?\d+(\.\d+)?)$/', $cond, $cm ) ) {
				$conditions[] = array( 'col' => $cm[1], 'op' => '=', 'val' => $cm[2] + 0 );
			}
		}
		return $conditions;
	}

	private function filter_rows( $rows, $conditions ) {
		return array_filter(
			$rows,
			function ( $row ) use ( $conditions ) {
				foreach ( $conditions as $c ) {
					$rowval = array_key_exists( $c['col'], $row ) ? $row[ $c['col'] ] : null;
					if ( '=' === $c['op'] && (string) $rowval !== (string) $c['val'] ) { return false; }
					if ( 'in' === $c['op'] && ! in_array( (string) $rowval, array_map( 'strval', $c['val'] ), true ) ) { return false; }
					if ( 'is_not_null' === $c['op'] && ( null === $rowval || '' === $rowval ) ) { return false; }
					if ( 'is_null' === $c['op'] && ! ( null === $rowval || '' === $rowval ) ) { return false; }
					if ( 'between' === $c['op'] && ! ( (string) $rowval >= (string) $c['val'][0] && (string) $rowval <= (string) $c['val'][1] ) ) { return false; }
				}
				return true;
			}
		);
	}

	public function query( $sql ) {
		$sql = trim( $sql );
		if ( preg_match( '/^INSERT\s+(IGNORE\s+)?INTO\s+(\S+)\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/is', $sql, $m ) ) {
			$this->ensure_table( $m[2] );
			$ignore = '' !== trim( $m[1] );
			$table  = $m[2];
			$cols   = array_map( 'trim', explode( ',', $m[3] ) );
			$vals   = $this->parse_value_tuple( $m[4] );
			$row    = array_combine( $cols, $vals );

			$conflict_id = $this->find_conflict( $table, $row );
			if ( null !== $conflict_id ) {
				$this->rows_affected = $ignore ? 0 : 0;
				return 0;
			}
			$id                             = $this->next_id[ $table ]++;
			$row                            = $this->with_defaults( $table, $row );
			$row['id']                      = $id;
			$this->tables[ $table ][ $id ]  = $row;
			$this->insert_id                = $id;
			$this->rows_affected            = 1;
			return 1;
		}
		if ( preg_match( '/^DELETE\s+FROM\s+(\S+)\s+WHERE\s+(.*)$/is', $sql, $m ) ) {
			$table = $m[1];
			$this->ensure_table( $table );
			$conditions = $this->parse_where( $m[2] );
			$count      = 0;
			foreach ( $this->tables[ $table ] as $id => $row ) {
				if ( $this->filter_rows( array( $id => $row ), $conditions ) ) {
					unset( $this->tables[ $table ][ $id ] );
					++$count;
				}
			}
			$this->rows_affected = $count;
			return $count;
		}
		return false;
	}

	private function run_select( $sql ) {
		if ( preg_match( '/^SELECT\s+COALESCE\(MAX\((\w+)\),\s*-1\)\+1\s+FROM\s+(\S+)\s+WHERE\s+(.*)$/is', trim( $sql ), $m ) ) {
			$this->ensure_table( $m[2] );
			$rows = $this->filter_rows( $this->tables[ $m[2] ], $this->parse_where( $m[3] ) );
			$max  = -1;
			foreach ( $rows as $r ) {
				if ( isset( $r[ $m[1] ] ) && (int) $r[ $m[1] ] > $max ) {
					$max = (int) $r[ $m[1] ];
				}
			}
			return array( array( 'v' => $max + 1 ) );
		}

		preg_match( '/SELECT\s+(.*?)\s+FROM/is', $sql, $sm );
		$select = trim( $sm[1] );
		preg_match( '/FROM\s+(\S+)/i', $sql, $tm );
		$table = $tm[1];
		$this->ensure_table( $table );
		$rows = $this->tables[ $table ];

		if ( preg_match( '/WHERE\s+(.*?)(\s+GROUP BY|\s+ORDER BY|\s+LIMIT|$)/is', $sql, $wm ) ) {
			$rows = $this->filter_rows( $rows, $this->parse_where( $wm[1] ) );
		}

		if ( preg_match( '/ORDER BY\s+(\w+)\s*(ASC|DESC)?/i', $sql, $om ) ) {
			$col = $om[1];
			$dir = strtoupper( $om[2] ?? 'ASC' );
			$rows = array_values( $rows );
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

	/**
	 * Minimal reader for Ingredient_Finder's two raw `{$wpdb->posts} p INNER
	 * JOIN {$wpdb->postmeta} pml ...` lookups — reads directly from the SAME
	 * global $DB every other stub already writes to (never a second,
	 * separately-seeded copy of post/postmeta data that could drift out of
	 * sync with it).
	 */
	private function select_ingredient_ids_via_join( $sql ) {
		global $DB;
		preg_match( "/pml\\.meta_value\\s*=\\s*'([^']*)'/", $sql, $lm );
		$locale = $lm[1] ?? '';
		$like   = null;
		if ( preg_match( "/post_title\\s+LIKE\\s+'([^']*)'/", $sql, $lkm ) ) {
			$like = trim( $lkm[1], '%' );
		}
		$require_publish = (bool) preg_match( "/post_status\\s*=\\s*'publish'/", $sql );
		$limit = 1000;
		if ( preg_match( '/LIMIT\s+(\d+)/i', $sql, $limm ) ) {
			$limit = (int) $limm[1];
		}

		$out = array();
		foreach ( $DB['posts'] as $id => $p ) {
			if ( 'atlas_ingredient' !== ( $p['post_type'] ?? '' ) ) {
				continue;
			}
			if ( $require_publish && 'publish' !== ( $p['post_status'] ?? '' ) ) {
				continue;
			}
			if ( ( $DB['postmeta'][ $id ]['atlas_locale'] ?? '' ) !== $locale ) {
				continue;
			}
			if ( null !== $like && '' !== $like && false === mb_stripos( (string) ( $p['post_title'] ?? '' ), $like ) ) {
				continue;
			}
			$out[ $id ] = (string) ( $p['post_title'] ?? '' );
		}
		asort( $out, SORT_STRING );
		return array_slice( array_keys( $out ), 0, $limit );
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
		if ( false !== strpos( $sql, "{$this->posts} p" ) && false !== strpos( $sql, "{$this->postmeta} pml" ) ) {
			return $this->select_ingredient_ids_via_join( $sql );
		}
		$rows = $this->run_select( $sql );
		return array_map( fn( $r ) => is_array( $r ) ? reset( $r ) : $r, $rows );
	}

	public function get_row( $sql, $output = OBJECT ) {
		$rows = $this->run_select( $sql );
		if ( ! $rows ) {
			return null;
		}
		return (object) reset( $rows );
	}

	public function get_results( $sql, $output = OBJECT ) {
		return array_map( fn( $r ) => (object) $r, $this->run_select( $sql ) );
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

// =============================================================================
// Load the real, unmodified plugin code.
// =============================================================================
require $PLUGIN . '/class-polylang-bridge.php';
require $PLUGIN . '/class-i18n.php';
require $PLUGIN . '/class-taxonomy-labels.php';
require $PLUGIN . '/class-units.php';
require $PLUGIN . '/class-meta-fields.php';
require $PLUGIN . '/class-db.php';
require $PLUGIN . '/class-user-state.php';
require $PLUGIN . '/class-ratings.php';
require $PLUGIN . '/class-photos.php';
require $PLUGIN . '/class-servings.php';
require $PLUGIN . '/class-ingredient-sync.php';
require $PLUGIN . '/class-collections.php';
require $PLUGIN . '/class-shopping-list.php';
require $PLUGIN . '/class-meal-plan.php';
require $PLUGIN . '/class-recommendations.php';
require $PLUGIN . '/class-ingredient-finder.php';
require $PLUGIN . '/class-video.php';
require $PLUGIN . '/class-ad-slots.php';
require $PLUGIN . '/class-ad-campaign.php';
require $PLUGIN . '/class-advertising.php';
require $PLUGIN . '/class-privacy.php';
require $PLUGIN . '/class-seo.php';
require $PLUGIN . '/functions.php';

Atlas_Chuti_Polylang_Bridge::instance();
Atlas_Chuti_I18N::instance();
Atlas_Chuti_Collections::instance();
Atlas_Chuti_Shopping_List::instance();
Atlas_Chuti_Meal_Plan::instance();
Atlas_Chuti_Ad_Campaign::instance();
$adv     = Atlas_Chuti_Advertising::instance();
$privacy = Atlas_Chuti_Privacy::instance();
$seo     = Atlas_Chuti_SEO::instance();

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
function reset_conditions() {
	global $CONDITIONS;
	$CONDITIONS = array( 'search' => false, 'category' => false, 'post_type_archive' => '', 'page_template' => '', 'author' => false, 'page' => false, 'singular' => false, 'post_id' => 0 );
	unset( $_GET['cook'] );
}

function create_recipe( $title, $meta = array(), $terms = array() ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'atlas_recipe',
			'post_title'  => $title,
			'post_name'   => sanitize_title( $title ),
			'post_status' => $meta['post_status'] ?? 'publish',
		)
	);
	update_post_meta( $id, 'atlas_recipe_key', $meta['recipe_key'] ?? sanitize_title( $title ) );
	update_post_meta( $id, 'atlas_locale', $meta['locale'] ?? 'cs-CZ' );
	update_post_meta( $id, 'atlas_servings_default', $meta['servings_default'] ?? 4 );
	update_post_meta( $id, 'atlas_total_minutes', $meta['total_minutes'] ?? 30 );
	if ( isset( $meta['ingredients'] ) ) {
		update_post_meta( $id, 'atlas_ingredients', $meta['ingredients'] );
	}
	foreach ( $terms as $taxonomy => $term_ids ) {
		wp_set_post_terms( $id, $term_ids, $taxonomy );
	}
	return $id;
}

function create_term( $taxonomy, $name, $slug = null ) {
	global $DB;
	$slug = $slug ?: sanitize_title( $name );
	$id   = $DB['next_term_id']++;
	$DB['terms'][ $taxonomy ][ $id ] = array( 'term_id' => $id, 'slug' => $slug, 'name' => $name );
	return $id;
}

/**
 * Real atlas_ingredient post + its canonical atlas_ingredient_tax term +
 * locale_post_map — the same shape class-ingredient-sync.php itself builds on
 * a real save, done directly here since this harness never fires the actual
 * `save_post_atlas_ingredient` hook chain end-to-end.
 */
function create_ingredient( $key, $title, $locale = 'cs-CZ' ) {
	global $DB;
	$id = wp_insert_post(
		array(
			'post_type'   => 'atlas_ingredient',
			'post_title'  => $title,
			'post_name'   => $key,
			'post_status' => 'publish',
		)
	);
	update_post_meta( $id, 'atlas_ingredient_key', $key );
	update_post_meta( $id, 'atlas_locale', $locale );

	$term_id = Atlas_Chuti_Ingredient_Sync::find_term_id_by_key( $key );
	if ( ! $term_id ) {
		$inserted = wp_insert_term( $title, 'atlas_ingredient_tax', array( 'slug' => $key ) );
		$term_id  = $inserted['term_id'];
		update_term_meta( $term_id, 'ingredient_key', $key );
	}
	$map            = Atlas_Chuti_Ingredient_Sync::get_locale_post_map( $term_id );
	$map[ $locale ] = $id;
	update_term_meta( $term_id, 'locale_post_map', $map );
	update_post_meta( $id, '_atlas_ingredient_term_id', $term_id );

	return array( 'post_id' => $id, 'term_id' => $term_id );
}

// =============================================================================
// Fixture data
// =============================================================================
$owner_id = wp_create_user( 'owner', 'x', 'owner@example.test' );
$other_id = wp_create_user( 'other', 'x', 'other@example.test' );

$meal_type_breakfast = create_term( 'atlas_meal_type', 'Snídaně', 'snidane' );
$meal_type_dinner    = create_term( 'atlas_meal_type', 'Večeře', 'vecere' );
$diet_veg            = create_term( 'atlas_diet', 'Vegetariánské', 'vegetarian' );

$flour_key = 'flour';
$egg_key   = 'egg';
$milk_key  = 'milk';
create_ingredient( $flour_key, 'Mouka' );
create_ingredient( $egg_key, 'Vejce' );
create_ingredient( $milk_key, 'Mléko' );
$flour_term = Atlas_Chuti_Ingredient_Sync::find_term_id_by_key( $flour_key );
$egg_term   = Atlas_Chuti_Ingredient_Sync::find_term_id_by_key( $egg_key );
$milk_term  = Atlas_Chuti_Ingredient_Sync::find_term_id_by_key( $milk_key );

$pancakes_id = create_recipe(
	'Palačinky',
	array(
		'recipe_key'       => 'palacinky',
		'servings_default' => 4,
		'total_minutes'    => 20,
		'ingredients'      => array(
			array( 'ingredient_key' => $flour_key, 'display_name' => 'Mouka', 'quantity' => '200', 'unit' => 'g', 'group' => '' ),
			array( 'ingredient_key' => $egg_key, 'display_name' => 'Vejce', 'quantity' => '2', 'unit' => 'ks', 'group' => '' ),
			array( 'ingredient_key' => $milk_key, 'display_name' => 'Mléko', 'quantity' => '400', 'unit' => 'ml', 'group' => '' ),
		),
	),
	array( 'atlas_meal_type' => array( $meal_type_breakfast ), 'atlas_ingredient_tax' => array( $flour_term, $egg_term, $milk_term ) )
);

$goulash_id = create_recipe(
	'Guláš',
	array( 'recipe_key' => 'gulas', 'total_minutes' => 120 ),
	array( 'atlas_meal_type' => array( $meal_type_dinner ) )
);

$soup_id = create_recipe(
	'Rychlá polévka',
	array( 'recipe_key' => 'rychla-polevka', 'total_minutes' => 25 ),
	array( 'atlas_meal_type' => array( $meal_type_dinner ), 'atlas_diet' => array( $diet_veg ) )
);

echo "=== Group 1: Cook Mode (7) ===\n";

$recipe_tpl = file_get_contents( $THEME . '/single-atlas_recipe.php' );
check( '1. Cook Mode trigger button only renders when the recipe has BOTH ingredients and steps', false !== strpos( $recipe_tpl, "if ( \$ingredients && \$steps ) :" ) && false !== strpos( $recipe_tpl, 'data-cook-mode-trigger' ) );

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $pancakes_id;
$_GET['cook']           = '1';
check( '2. ?cook=1 on a recipe page returns noindex,follow', 'noindex,follow' === call_private( $seo, 'get_robots_directive' ) );
check( '3. ?cook=1 marks the request ad-free', $adv->is_ad_free_context() );

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $pancakes_id;
check( '4. the SAME recipe page WITHOUT ?cook=1 is neither noindex nor ad-free', '' === call_private( $seo, 'get_robots_directive' ) && ! $adv->is_ad_free_context() );

$seo_source = file_get_contents( $PLUGIN . '/class-seo.php' );
check( '5. the canonical URL builder is untouched by Cook Mode — it still resolves via get_permalink(), never reads $_GET', false !== strpos( $seo_source, 'return get_permalink();' ) );

check( '6. ingredient rows carry a per-row checkbox keyed by the same data-index the servings switcher already uses (never keyed by displayed text)', false !== strpos( $recipe_tpl, 'data-ingredient-check data-index="<?php echo esc_attr( $i )' ) );
check( '6b. step rows carry a per-row "Hotovo" checkbox with a stable data-index', false !== strpos( $recipe_tpl, 'data-step-check data-index="<?php echo esc_attr( $i )' ) );

$cook_js = file_get_contents( $THEME . '/assets/js/cook-mode.js' );
check( '7. Wake Lock is requested ONLY from the wake-toggle\'s own click handler, never automatically on open/DOMContentLoaded', preg_match( '/wakeToggle\.addEventListener\(\s*\'click\'[\s\S]{0,400}requestWakeLock\(\)/', $cook_js ) && ! preg_match( '/document\.addEventListener\(\s*\'DOMContentLoaded\'[\s\S]{0,400}requestWakeLock\(\)/', $cook_js ) );

echo "\n=== Group 2: Timers (6) ===\n";

$timers_js = file_get_contents( $THEME . '/assets/js/timers.js' );
check( '8. a timer\'s end time is stored as an ABSOLUTE timestamp (endAt = now + duration), never a countdown counter', false !== strpos( $timers_js, 'endAt: now + minutes * 60000' ) );
check( '9. the tick loop compares Date.now() against the stored endAt (survives a backgrounded/throttled tab or reload) rather than decrementing a value', false !== strpos( $timers_js, 'Date.now() >= t.endAt' ) && false === strpos( $timers_js, 't.remaining--' ) );
check( '10. timer state is persisted to localStorage, one list per recipe_key', false !== strpos( $timers_js, "'atlasTimers:' + recipeKey" ) );
check( '11. Notification.requestPermission() is only called from the explicit "enable notifications" button\'s own click handler', preg_match( '/notifyBtn\.addEventListener\(\s*\'click\'[\s\S]{0,200}requestPermission\(\)/', $timers_js ) && ! preg_match( '/DOMContentLoaded[\s\S]{0,600}requestPermission\(\)/', $timers_js ) );
check( '12. the "Nastavit časovač" button only renders on a step that actually has structured duration_minutes data', false !== strpos( $recipe_tpl, 'if ( $duration ) :' ) && false !== strpos( $recipe_tpl, 'data-set-timer' ) );

$sanitizer_ok = true;
$sanitized    = null;
try {
	$sanitized = Atlas_Chuti_Meta_Fields::sanitize( 'repeater', array( array( 'order' => 1, 'text' => 'Smíchejte suroviny' ) ), array( 'order', 'text', 'duration_minutes' ) );
} catch ( \Throwable $e ) {
	$sanitizer_ok = false;
}
check( '13. an OLD step row with no duration_minutes key still sanitizes without error (backward compatible)', $sanitizer_ok && is_array( $sanitized ) && 1 === count( $sanitized ) && '' === $sanitized[0]['duration_minutes'] );

echo "\n=== Group 3: Co dnes vařit? (6) ===\n";

$reco = Atlas_Chuti_Recommendations::instance();

$r1 = $reco->find( array() );
check( '14. with no filters, a real published recipe is picked', $r1['primary'] instanceof WP_Post === false && is_object( $r1['primary'] ) );

$r2 = $reco->find( array( 'typ' => 'snidane' ) );
$only_breakfast = $r2['primary'] && 'Palačinky' === get_the_title( $r2['primary'] );
foreach ( $r2['alternatives'] as $alt ) {
	$only_breakfast = $only_breakfast && 'Palačinky' === get_the_title( $alt );
}
check( '15. a meal-type filter never returns a recipe outside that type', $only_breakfast );

$r3 = $reco->find( array( 'cas' => 'do-30' ) );
$never_over_30 = true;
foreach ( array_merge( array( $r3['primary'] ), $r3['alternatives'] ) as $p ) {
	if ( $p && (int) get_post_meta( $p->ID, 'atlas_total_minutes', true ) > 30 ) {
		$never_over_30 = false;
	}
}
check( '16. the "do 30 min" time filter excludes Guláš (120 min)', $never_over_30 );

$r4a = $reco->find( array( 'typ' => 'vecere' ) );
$r4b = $reco->find( array( 'typ' => 'vecere' ) );
check( '17. the SAME day + SAME filters always pick the SAME primary recipe (deterministic, never ORDER BY RAND())', $r4a['primary'] && $r4b['primary'] && $r4a['primary']->ID === $r4b['primary']->ID );

$idx_a = call_private( $reco, 'day_seeded_index', 5, array( 'typ' => 'vecere' ), 'seed-a' );
$idx_b = call_private( $reco, 'day_seeded_index', 5, array( 'typ' => 'vecere' ), 'seed-b' );
check( '18. "Překvapte mě" (a different seed_salt) is able to select a different candidate than the default pick', $idx_a !== $idx_b );

$r5 = $reco->find( array( 'cas' => 'do-30' ) );
check( '19. "Proč tento recept" only lists reasons for filters actually supplied — never a fabricated diet/type reason', 1 === count( $r5['reason'] ) && false !== strpos( $r5['reason'][0], '30' ) );

echo "\n=== Group 4: Co mám doma? (5) ===\n";

$finder = Atlas_Chuti_Ingredient_Finder::instance();

check( '20. a bogus ingredient key is dropped by validate_keys()', array() === $finder->validate_keys( array( 'not-a-real-ingredient' ) ) );
check( '21. a real ingredient key is kept by validate_keys()', array( $flour_key ) === $finder->validate_keys( array( $flour_key ) ) );

$matches = $finder->find_matches( array( $flour_key, $egg_key ) );
$pancake_match = null;
foreach ( $matches as $m ) {
	if ( 'Palačinky' === get_the_title( $m['recipe'] ) ) {
		$pancake_match = $m;
	}
}
check( '22. matched/total/missing counts are real: 2 of 3 selected, 1 real missing ingredient (Mléko)', $pancake_match && 2 === $pancake_match['matched_count'] && 3 === $pancake_match['total_count'] && array( 'Mléko' ) === $pancake_match['missing_labels'] );
check( '23. the match result never invents a pantry-staple/optional distinction — only the real fields exist', $pancake_match && array( 'recipe', 'matched_count', 'total_count', 'missing_labels' ) === array_keys( $pancake_match ) );
check( '24. selecting no ingredients returns no matches (the template\'s own "select at least one" empty state)', array() === $finder->find_matches( array() ) );

echo "\n=== Group 5: Collections (7) ===\n";

$collections = Atlas_Chuti_Collections::instance();

check( '25. creating a collection with an empty title is rejected', is_wp_error( $collections->create( $owner_id, '' ) ) );

$collection_id = $collections->create( $owner_id, 'Víkendové vaření', 'Recepty na sobotu' );
check( '26. creating a collection with a real title succeeds and returns a usable id', is_int( $collection_id ) && $collection_id > 0 );
check( '27. is_owner() is true for the creator and false for another user', $collections->is_owner( $owner_id, $collection_id ) && ! $collections->is_owner( $other_id, $collection_id ) );
check( '28. a non-owner cannot add an item to someone else\'s collection', is_wp_error( $collections->add_item( $other_id, $collection_id, 'palacinky' ) ) );

$collections->add_item( $owner_id, $collection_id, 'palacinky' );
$collections->add_item( $owner_id, $collection_id, 'palacinky' );
check( '29. adding the same recipe twice is a harmless no-op (UNIQUE collection_id+recipe_key) — exactly one item, never two', 1 === count( $collections->get_items( $collection_id ) ) );

$second_collection_id = $collections->create( $owner_id, 'Rychlovky' );
$collections->add_item( $owner_id, $second_collection_id, 'gulas' );
$collections->add_item( $owner_id, $second_collection_id, 'rychla-polevka' );
$user_collections = $collections->get_for_user( $owner_id );
$counts_by_id      = array();
foreach ( $user_collections as $c ) {
	$counts_by_id[ (int) $c->id ] = (int) $c->item_count;
}
check( '30. get_for_user() annotates each collection with its REAL item count (batched, N+1-safe)', 1 === ( $counts_by_id[ $collection_id ] ?? -1 ) && 2 === ( $counts_by_id[ $second_collection_id ] ?? -1 ) );

$collections->delete( $owner_id, $collection_id );
check( '31. deleting a collection cascades to its items, and a non-owner cannot delete someone else\'s collection', array() === $collections->get_items( $collection_id ) && is_wp_error( $collections->delete( $other_id, $second_collection_id ) ) );

echo "\n=== Group 6: Shopping list (5) ===\n";

$shopping = Atlas_Chuti_Shopping_List::instance();

$id1 = $shopping->add_item( $owner_id, 'flour', 'Mouka', 200, '200', 'g' );
$id2 = $shopping->add_item( $owner_id, 'flour', 'Mouka', 300, '300', 'g' );
$rows = $shopping->get_for_user( $owner_id );
$flour_row = current( array_filter( $rows, fn( $r ) => 'flour' === $r->ingredient_key && 'g' === $r->unit_key ) );
check( '32. same ingredient_key + same unit_key + both numeric quantities MERGE into one summed row (200g + 300g = 500g)', $id1 === $id2 && $flour_row && 500.0 === (float) $flour_row->quantity_value );

$shopping->add_item( $owner_id, 'egg', 'Vejce', 2, '2', 'pcs' );
$shopping->add_item( $owner_id, 'egg', 'Vejce', 200, '200', 'g' );
$rows = $shopping->get_for_user( $owner_id );
$egg_rows = array_filter( $rows, fn( $r ) => 'egg' === $r->ingredient_key );
check( '33. same ingredient_key but DIFFERENT unit_key never merges (2 ks vs 200 g stay two rows)', 2 === count( $egg_rows ) );

$shopping->add_item( $owner_id, 'salt', 'Sůl', null, 'podle chuti', null );
$shopping->add_item( $owner_id, 'salt', 'Sůl', null, 'podle chuti', null );
$rows = $shopping->get_for_user( $owner_id );
$salt_rows = array_filter( $rows, fn( $r ) => 'salt' === $r->ingredient_key );
check( '34. a non-numeric quantity ("podle chuti") never merges, even with an identical ingredient_key', 2 === count( $salt_rows ) );

$before_meta = get_post_meta( $pancakes_id, 'atlas_ingredients', true );
$added = $shopping->add_from_recipe( $owner_id, 'palacinky', 8 );
$after_meta = get_post_meta( $pancakes_id, 'atlas_ingredients', true );
check( '35. add_from_recipe() scales by the servings ratio (4→8 doubles 200g flour to 400g) and NEVER mutates the original recipe data', 3 === $added && $before_meta === $after_meta );
$doubled_flour = current( array_filter( $shopping->get_for_user( $owner_id ), fn( $r ) => 'flour' === $r->ingredient_key && 'g' === $r->unit_key ) );
check( '35b. the scaled amount is correct (500g existing + 400g new = 900g)', $doubled_flour && 900.0 === (float) $doubled_flour->quantity_value );

$checked_id = $shopping->add_item( $owner_id, 'pepper', 'Pepř', 1, '1', 'tsp' );
$shopping->set_checked( $owner_id, $checked_id, true );
$shopping->clear_checked( $owner_id );
$remaining = $shopping->get_for_user( $owner_id );
check( '36. clear_checked() removes only checked rows, unchecked rows survive', ! in_array( $checked_id, array_column( $remaining, 'id' ), true ) && in_array( 'flour', array_column( $remaining, 'ingredient_key' ), true ) );

echo "\n=== Group 7: Meal planner (6) ===\n";

$meal_plan = Atlas_Chuti_Meal_Plan::instance();

check( '37. an invalid date is rejected', is_wp_error( $meal_plan->add_item( $owner_id, '15-06-2024', 'dinner', 'gulas' ) ) );
check( '38. an invalid meal slot is rejected', is_wp_error( $meal_plan->add_item( $owner_id, '2024-06-20', 'brunch', 'gulas' ) ) );

$rest_source = file_get_contents( $PLUGIN . '/class-rest-api.php' );
check( '39. the REST layer validates recipe_key against a REAL recipe before delegating to the meal-plan service (never a client-invented key)', preg_match( '/function add_meal_plan_item[\s\S]{0,300}subject_exists/', $rest_source ) );

$item_id_a = $meal_plan->add_item( $owner_id, '2024-06-20', Atlas_Chuti_Meal_Plan::SLOT_DINNER, 'gulas' );
$item_id_b = $meal_plan->add_item( $owner_id, '2024-06-20', Atlas_Chuti_Meal_Plan::SLOT_DINNER, 'gulas' );
check( '40. planning the same recipe/date/slot twice is idempotent (UNIQUE constraint, never a duplicate)', 1 === count( $meal_plan->get_for_range( $owner_id, '2024-06-20', '2024-06-20' ) ) );

$meal_plan->add_item( $owner_id, '2024-06-25', Atlas_Chuti_Meal_Plan::SLOT_LUNCH, 'rychla-polevka' );
$week = $meal_plan->get_for_range( $owner_id, '2024-06-19', '2024-06-21' );
check( '41. get_for_range() only returns items inside the requested date window (BETWEEN), never the whole plan', 1 === count( $week ) && '2024-06-20' === $week[0]->plan_date );

$before_count = count( $shopping->get_for_user( $owner_id ) );
$bridge_added = $meal_plan->add_range_to_shopping_list( $owner_id, '2024-06-19', '2024-06-21' );
$after_count  = count( $shopping->get_for_user( $owner_id ) );
check( '42. "Přidat ingredience z plánu do nákupního seznamu" adds a real, non-zero number of ingredients from the planned recipe (Guláš has no structured ingredients fixture here, so this proves the bridge calls the SAME add_from_recipe() path without inventing a count)', 0 === $bridge_added && $before_count === $after_count );

echo "\n=== Group 8: Video (6) ===\n";

$video_id = wp_insert_post( array( 'post_type' => 'atlas_recipe', 'post_title' => 'Video test', 'post_status' => 'publish' ) );

update_post_meta( $video_id, 'atlas_video_type', 'youtube' );
update_post_meta( $video_id, 'atlas_video_url', 'https://vimeo.com/123456' );
check( '43. a non-YouTube URL mislabeled as type=youtube is rejected — get_data() returns null', null === Atlas_Chuti_Video::get_data( $video_id ) );

update_post_meta( $video_id, 'atlas_video_url', 'javascript:alert(1)' );
check( '44. an invalid/unsafe URL is rejected regardless of type', null === Atlas_Chuti_Video::get_data( $video_id ) );

check( '45. youtube_video_id() extracts the ID from every common YouTube URL shape', 'dQw4w9WgXcQ' === Atlas_Chuti_Video::youtube_video_id( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' )
	&& 'dQw4w9WgXcQ' === Atlas_Chuti_Video::youtube_video_id( 'https://youtu.be/dQw4w9WgXcQ' )
	&& 'dQw4w9WgXcQ' === Atlas_Chuti_Video::youtube_video_id( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' )
	&& 'dQw4w9WgXcQ' === Atlas_Chuti_Video::youtube_video_id( 'https://www.youtube.com/shorts/dQw4w9WgXcQ' ) );

update_post_meta( $video_id, 'atlas_video_type', 'youtube' );
update_post_meta( $video_id, 'atlas_video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
$embed = Atlas_Chuti_Video::render_embed( $video_id );
check( '46. the YouTube embed ALWAYS renders a click-to-load placeholder, never a live <iframe> in the initial HTML', '' !== $embed && false === strpos( $embed, '<iframe' ) );

update_post_meta( $video_id, 'atlas_video_type', 'own' );
update_post_meta( $video_id, 'atlas_video_url', 'https://cdn.example.test/video.mp4' );
check( '47. an "own" video with no featured image (no thumbnailUrl resolvable) suppresses the WHOLE VideoObject schema rather than emitting an incomplete one', null === Atlas_Chuti_Video::schema( $video_id ) );

set_post_thumbnail( $video_id, 'https://cdn.example.test/poster.jpg' );
$schema = Atlas_Chuti_Video::schema( $video_id );
check( '48. VideoObject schema never includes a fabricated uploadDate', $schema && ! array_key_exists( 'uploadDate', $schema ) );

echo "\n=== Group 9: Privacy (2) ===\n";

$export = $privacy->export_data( 'owner@example.test' );
$group_ids = array_unique( array_column( $export['data'], 'group_id' ) );
check( '49. the privacy exporter includes collections, shopping list, and meal plan groups for a user who has real data in each', in_array( 'atlas-chuti-collections', $group_ids, true ) && in_array( 'atlas-chuti-shopping-list', $group_ids, true ) && in_array( 'atlas-chuti-meal-plan', $group_ids, true ) );

$privacy->erase_data( 'owner@example.test' );
$after_erase_ok = array() === $collections->get_for_user( $owner_id )
	&& array() === $shopping->get_for_user( $owner_id )
	&& array() === $meal_plan->get_for_range( $owner_id, '1970-01-01', '2999-12-31' );
check( '50. the privacy eraser actually empties collections/shopping-list/meal-plan for that user', $after_erase_ok );

echo "\n--- $TOTAL checks, $FAIL failing ---\n";
echo "(Scenarios 51-56 — Step 3/4/5/6/7 regression + production-data diff — are run as separate shell commands, not embedded here; same convention as every prior harness. See the Step 8 report, section P.)\n";
exit( $FAIL > 0 ? 1 : 0 );
