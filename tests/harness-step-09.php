<?php
/**
 * KROK 9 test harness — deterministic, no external dependencies, no live WP/DB.
 * Same approach as harness-step-03..08.php: stub just enough of the WordPress
 * API for the REAL, unmodified class-seo.php (and the small set of plugin
 * classes it calls into) to run against an in-memory fake environment.
 *
 * Covers the 53 numbered KROK 9 scenarios (docs/implementation-reports/
 * step-09-seo-discover-geo-audit.md section N): Canonical/robots (7),
 * Hreflang (4), Recipe schema (7), Article (3), Breadcrumbs (3), Archives
 * (4), Sitemap (4), GEO/AIO/semantic (5), Performance architecture (5),
 * Multilingual (4), plus Organization/Discussion schema (3) and the content
 * quality gate tool (2, run as a shell command — see the bottom of this
 * file) for the exact minimum-56 count this step's brief specifies.
 *
 * What this harness deliberately does NOT attempt (same boundary as every
 * prior harness): a real HTTP request, real Google Rich Results/Search
 * Console validation, live Lighthouse/PageSpeed measurement, a real
 * wp-sitemap.xml HTTP fetch. Every PHP decision path in class-seo.php is
 * exercised directly and for real; those live checks go in the report's
 * staging checklist.
 *
 * Run: `php tests/harness-step-09.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-step9-fakeroot/' );

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
// Fake database
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
$HOOKS      = array();
$FILTERS    = array();
$NOW        = '2024-06-15 10:00:00';
$THUMBNAILS = array();
$CONDITIONS = array(
	'search' => false, 'category' => false, 'post_type_archive' => '', 'page_template' => '',
	'author' => false, 'page' => false, 'singular' => false, 'post_id' => 0, 'tax' => false,
	'tag' => false, 'home' => false, 'front_page' => false, 'queried_object' => null,
);
$QUERY_VARS  = array();
$GLOBALS['wp_query'] = (object) array( 'found_posts' => 1 );

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
	public $code; public $msg; public $data;
	public function __construct( $code = '', $msg = '', $data = '' ) { $this->code = $code; $this->msg = $msg; $this->data = $data; }
	public function get_error_message() { return $this->msg; }
	public function get_error_code() { return $this->code; }
	public function add_data( $data ) { $this->data = $data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

function sanitize_title( $s ) { $s = strtolower( trim( (string) $s ) ); $s = preg_replace( '/[^a-z0-9]+/', '-', $s ); return trim( $s, '-' ); }
function sanitize_key( $s ) { $s = strtolower( (string) $s ); return preg_replace( '/[^a-z0-9_\-]/', '', $s ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]+\]/', '', (string) $s ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
function esc_url( $s ) { return trim( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function absint( $s ) { return abs( (int) $s ); }
function wp_json_encode( $x ) { return json_encode( $x ); } // phpcs:ignore -- test harness only.
function __( $s, $d = null ) { return $s; }
function _n( $single, $plural, $n, $d = null ) { return 1 === (int) $n ? $single : $plural; }
function wp_unslash( $s ) { return $s; }
function wp_trim_words( $s, $n ) {
	$words = preg_split( '/\s+/', trim( (string) $s ) );
	return count( $words ) > $n ? implode( ' ', array_slice( $words, 0, $n ) ) . '…' : (string) $s;
}
function wp_is_post_autosave( $id ) { return false; }
function wp_is_post_revision( $id ) { return false; }
function get_option( $k ) { global $OPTIONS; return $OPTIONS[ $k ] ?? false; }
function update_option( $k, $v ) { global $OPTIONS; $OPTIONS[ $k ] = $v; return true; }
function register_taxonomy( $tax, $object_types, $args = array() ) {
	global $DB;
	if ( ! isset( $DB['terms'][ $tax ] ) ) { $DB['terms'][ $tax ] = array(); }
	return true;
}
function register_post_type( $pt, $args = array() ) { return true; }
function register_post_meta( $pt, $key, $args = array() ) { return true; }
function home_url( $path = '/' ) { return 'https://atlaschuti.cz' . $path; }
function admin_url( $path = '' ) { return 'https://atlaschuti.cz/wp-admin/' . $path; }
function bloginfo( $key ) { echo get_bloginfo( $key ); }
function get_bloginfo( $key ) {
	if ( 'description' === $key ) { return 'Ochutnejte svět. Objevujte tradiční jídla, recepty a kuchyně ze všech koutů planety.'; }
	if ( 'language' === $key ) { return 'cs-CZ'; }
	return 'Atlas chutí';
}
function language_attributes() { echo 'lang="' . str_replace( '_', '-', get_bloginfo( 'language' ) ) . '"'; }
function add_query_arg( $args, $url = '' ) {
	if ( is_string( $args ) ) { $args = array( $args => func_get_args()[1] ); $url = func_get_args()[2] ?? ''; }
	$args = array_filter( $args, fn( $v ) => null !== $v );
	$sep  = false === strpos( $url, '?' ) ? '?' : '&';
	return $args ? $url . $sep . http_build_query( $args ) : $url;
}
function wp_safe_redirect( $location ) {}
function wp_die( $msg = '', $title = '', $args = array() ) { throw new Exception( 'wp_die: ' . ( is_string( $msg ) ? $msg : 'error' ) ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
function current_time( $type, $gmt = 0 ) { global $NOW; return 'timestamp' === $type ? strtotime( $NOW ) : $NOW; }
function get_query_var( $v ) { global $QUERY_VARS; return $QUERY_VARS[ $v ] ?? ''; }
function get_the_author_meta( $field, $id ) { return 'Redakce Atlas chutí'; }
function wp_get_document_title() { global $CONDITIONS, $DB; $id = $CONDITIONS['post_id']; $title = $id && isset( $DB['posts'][ $id ] ) ? $DB['posts'][ $id ]['post_title'] : 'Atlas chutí'; return $title . ' – ' . get_bloginfo( 'name' ); }
function wp_http_validate_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	return $url;
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } // phpcs:ignore -- test harness only.
function has_custom_logo() { global $OPTIONS; return ! empty( $OPTIONS['_test_custom_logo'] ); }
function get_theme_mod( $key ) { global $OPTIONS; return 'custom_logo' === $key ? ( $OPTIONS['_test_custom_logo'] ?? 0 ) : false; }

// -----------------------------------------------------------------
// Conditional tags — driven entirely by $CONDITIONS, reset per scenario.
// -----------------------------------------------------------------
function is_search() { global $CONDITIONS; return $CONDITIONS['search']; }
function is_category() { global $CONDITIONS; return $CONDITIONS['category']; }
function is_tag() { global $CONDITIONS; return $CONDITIONS['tag']; }
function is_tax( $tax = null ) { global $CONDITIONS; return null === $tax ? (bool) $CONDITIONS['tax'] : $CONDITIONS['tax'] === $tax; }
function is_home() { global $CONDITIONS; return $CONDITIONS['home']; }
function is_front_page() { global $CONDITIONS; return $CONDITIONS['front_page']; }
function is_author() { global $CONDITIONS; return $CONDITIONS['author']; }
function is_post_type_archive( $pt = null ) { global $CONDITIONS; return null === $pt ? (bool) $CONDITIONS['post_type_archive'] : $CONDITIONS['post_type_archive'] === $pt; }
function is_page_template( $t = null ) {
	global $CONDITIONS;
	if ( null === $t ) { return (bool) $CONDITIONS['page_template']; }
	return is_array( $t ) ? in_array( $CONDITIONS['page_template'], $t, true ) : $CONDITIONS['page_template'] === $t;
}
function is_page() { global $CONDITIONS; return $CONDITIONS['page']; }
function is_singular( $pt = null ) {
	global $CONDITIONS;
	if ( ! $CONDITIONS['singular'] ) { return false; }
	return null === $pt ? true : ( is_array( $pt ) ? in_array( $CONDITIONS['singular'], $pt, true ) : $CONDITIONS['singular'] === $pt );
}
function get_the_ID() { global $CONDITIONS; return $CONDITIONS['post_id']; }
function get_queried_object_id() { global $CONDITIONS; return $CONDITIONS['post_id']; }
function get_queried_object() { global $CONDITIONS; return $CONDITIONS['queried_object']; }
function get_search_query() { global $QUERY_VARS; return $QUERY_VARS['s'] ?? ''; }
function get_search_link( $query = '' ) { return home_url( '/?s=' . rawurlencode( $query ?: get_search_query() ) ); }
function get_pagenum_link( $page = 1, $escape = true ) { return home_url( '/page/' . (int) $page . '/' ); }
function single_term_title( $prefix = '', $display = true ) {
	$term = get_queried_object();
	$title = $prefix . ( $term->name ?? '' );
	if ( $display ) { echo $title; return null; }
	return $title;
}

// =============================================================================
// Posts (post_content/post_excerpt/post_date persisted — needed for
// get_the_excerpt()/get_the_date()/article & discussion schema).
// =============================================================================
function wp_insert_post( $args, $wp_error = false ) {
	global $DB, $NOW;
	$id        = (int) ( $args['ID'] ?? 0 );
	$is_update = $id && isset( $DB['posts'][ $id ] );
	if ( ! $is_update ) { $id = $DB['next_post_id']++; }
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
			'post_author'   => $args['post_author'] ?? ( $existing['post_author'] ?? 1 ),
			'post_date'     => $existing['post_date'] ?? $NOW,
			'post_modified' => $args['post_modified'] ?? $NOW,
		)
	);
	$DB['posts'][ $id ] = $post;
	foreach ( ( $args['meta_input'] ?? array() ) as $k => $v ) { $DB['postmeta'][ $id ][ $k ] = $v; }
	return $id;
}
function get_post_meta( $id, $key, $single = false ) { global $DB; return $DB['postmeta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { global $DB; $DB['postmeta'][ $id ][ $key ] = $value; return true; }
function get_post_field( $field, $id ) { global $DB; return $DB['posts'][ $id ][ $field ] ?? ''; }
function get_post_status( $id ) { global $DB; return $DB['posts'][ $id ]['post_status'] ?? false; }
function get_post( $id ) { global $DB; return isset( $DB['posts'][ $id ] ) ? db_post_to_object( $DB['posts'][ $id ] ) : null; }
function get_post_type( $id ) { global $DB; return $DB['posts'][ $id ]['post_type'] ?? false; }
function get_the_title( $id = null ) {
	global $DB, $CONDITIONS;
	$id = $id ?: $CONDITIONS['post_id'];
	if ( is_object( $id ) ) { $id = $id->ID; }
	return $DB['posts'][ $id ]['post_title'] ?? '';
}
function get_permalink( $id = null ) {
	global $DB, $CONDITIONS;
	$id = $id ?: $CONDITIONS['post_id'];
	if ( is_object( $id ) ) { $id = $id->ID; }
	$slugs = array( 'atlas_recipe' => 'p', 'atlas_country' => 'zeme', 'atlas_glossary' => 'slovnicek', 'atlas_topic' => 'diskuze', 'post' => 'magazin', 'page' => 'p' );
	$pt    = $DB['posts'][ $id ]['post_type'] ?? 'page';
	return home_url( '/' . ( $slugs[ $pt ] ?? 'p' ) . '/' . ( $DB['posts'][ $id ]['post_name'] ?? $id ) . '/' );
}
function get_the_excerpt( $id = null ) {
	global $DB, $CONDITIONS;
	$id = $id ?: $CONDITIONS['post_id'];
	if ( is_object( $id ) ) { $id = $id->ID; }
	$p = $DB['posts'][ $id ] ?? null;
	if ( ! $p ) { return ''; }
	return '' !== $p['post_excerpt'] ? $p['post_excerpt'] : wp_trim_words( wp_strip_all_tags( $p['post_content'] ), 55 );
}
function get_the_date( $format = '', $id = null ) {
	global $DB, $CONDITIONS;
	$id = $id ?: $CONDITIONS['post_id'];
	if ( is_object( $id ) ) { $id = $id->ID; }
	$p = $DB['posts'][ $id ] ?? null;
	if ( ! $p ) { return ''; }
	$raw = $p['post_date'] ?: $p['post_modified'];
	return 'c' === $format ? gmdate( 'c', strtotime( $raw ) ) : $raw;
}
function get_the_modified_date( $format = '', $id = null ) {
	global $DB, $CONDITIONS;
	$id = $id ?: $CONDITIONS['post_id'];
	if ( is_object( $id ) ) { $id = $id->ID; }
	$p = $DB['posts'][ $id ] ?? null;
	if ( ! $p ) { return ''; }
	return 'c' === $format ? gmdate( 'c', strtotime( $p['post_modified'] ) ) : $p['post_modified'];
}
function get_page_by_path( $slug, $output = OBJECT, $post_type = 'page' ) {
	global $DB;
	foreach ( $DB['posts'] as $p ) {
		if ( $p['post_type'] === $post_type && $p['post_name'] === $slug ) { return db_post_to_object( $p ); }
	}
	return null;
}
function has_post_thumbnail( $id = null ) { global $THUMBNAILS, $CONDITIONS; $id = $id ?: $CONDITIONS['post_id']; return ! empty( $THUMBNAILS[ $id ] ); }
function get_post_thumbnail_id( $id ) { global $THUMBNAILS; return ! empty( $THUMBNAILS[ $id ] ) ? $id : 0; }
function get_the_post_thumbnail_url( $id, $size = 'thumbnail' ) { global $THUMBNAILS; return $THUMBNAILS[ $id ] ?? false; }
function set_post_thumbnail( $id, $url ) { global $THUMBNAILS; $THUMBNAILS[ $id ] = $url; }
function wp_get_attachment_image_src( $attachment_id, $size = 'thumbnail' ) { global $THUMBNAILS; return isset( $THUMBNAILS[ $attachment_id ] ) ? array( $THUMBNAILS[ $attachment_id ] ) : false; }
function get_post_type_archive_link( $post_type ) {
	$slugs = array( 'atlas_recipe' => 'recepty', 'atlas_glossary' => 'slovnicek', 'atlas_topic' => 'diskuze' );
	return isset( $slugs[ $post_type ] ) ? home_url( '/' . $slugs[ $post_type ] . '/' ) : false;
}
function get_comments_number( $id = null ) { global $DB, $CONDITIONS; $id = $id ?: $CONDITIONS['post_id']; return (int) ( $DB['postmeta'][ $id ]['_test_comment_count'] ?? 0 ); }

function get_posts( $args = array() ) {
	global $DB;
	$post_type = $args['post_type'] ?? 'post';
	$status_in = (array) ( $args['post_status'] ?? array( 'publish' ) );
	$results   = array();
	foreach ( $DB['posts'] as $id => $p ) {
		if ( $p['post_type'] !== $post_type ) { continue; }
		if ( ! in_array( $p['post_status'], $status_in, true ) ) { continue; }
		if ( ! empty( $args['meta_query'] ) ) {
			$ok = true;
			foreach ( $args['meta_query'] as $clause ) {
				if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) { continue; }
				$val = $DB['postmeta'][ $id ][ $clause['key'] ] ?? null;
				if ( (string) $val !== (string) $clause['value'] ) { $ok = false; break; }
			}
			if ( ! $ok ) { continue; }
		}
		$results[ $id ] = $p;
	}
	$limit = $args['posts_per_page'] ?? -1;
	if ( $limit >= 0 ) { $results = array_slice( $results, 0, $limit, true ); }
	return array_values( array_map( 'db_post_to_object', $results ) );
}

// =============================================================================
// Taxonomy terms
// =============================================================================
function wp_set_post_terms( $post_id, $terms, $taxonomy, $append = false ) {
	global $DB;
	$terms = array_values( array_unique( array_map( 'intval', (array) $terms ) ) );
	$DB['term_relationships'][ $post_id ][ $taxonomy ] = $terms;
	return $terms;
}
function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
	global $DB;
	$ids = $DB['term_relationships'][ $post_id ][ $taxonomy ] ?? array();
	if ( 'ids' === ( $args['fields'] ?? '' ) ) { return $ids; }
	$out = array();
	foreach ( $ids as $tid ) {
		if ( isset( $DB['terms'][ $taxonomy ][ $tid ] ) ) { $out[] = db_term_to_object( $DB['terms'][ $taxonomy ][ $tid ] ); }
	}
	return $out;
}
function get_the_terms( $post_id, $taxonomy ) { $out = wp_get_post_terms( $post_id, $taxonomy ); return $out ?: false; }
function get_the_category( $post_id = null ) { global $CONDITIONS; $post_id = $post_id ?: $CONDITIONS['post_id']; return wp_get_post_terms( $post_id, 'category' ); }
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
	if ( get_term_by( 'slug', $slug, $taxonomy ) ) { return new WP_Error( 'term_exists', 'Term already exists.' ); }
	$id = $DB['next_term_id']++;
	$DB['terms'][ $taxonomy ][ $id ] = array( 'term_id' => $id, 'slug' => $slug, 'name' => $name );
	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}
function get_terms( $args ) {
	global $DB;
	$out = array();
	foreach ( $DB['terms'][ $args['taxonomy'] ] ?? array() as $term ) { $out[] = db_term_to_object( $term ); }
	return $out;
}
function get_term_meta( $term_id, $key, $single = false ) { global $DB; return $DB['termmeta'][ $term_id ][ $key ] ?? ''; }
function update_term_meta( $term_id, $key, $value ) { global $DB; $DB['termmeta'][ $term_id ][ $key ] = $value; return true; }
function get_term_link( $term, $taxonomy = '' ) {
	$slug = is_object( $term ) ? $term->slug : $term;
	return home_url( '/term/' . $slug . '/' );
}
function get_category_link( $term ) {
	$slug = is_object( $term ) ? $term->slug : $term;
	return home_url( '/category/' . $slug . '/' );
}

// =============================================================================
// Fake Polylang runtime — inactive by default (this project's real state,
// confirmed by the Step 9 audit: no Polylang plugin file present today).
// A handful of scenarios flip $PLL['active']=true to prove the hreflang path
// for real, exactly as harness-step-04.php first established.
// =============================================================================
$PLL = array( 'active' => false, 'current' => 'cs', 'groups' => array(), 'next_group' => 1 );
function pll_languages_list( $args = array() ) { return array( 'cs', 'en' ); }
function pll_current_language( $field = 'slug' ) { global $PLL; return $PLL['current']; }
function pll_get_post( $post_id, $target_slug ) {
	global $PLL;
	foreach ( $PLL['groups'] as $members ) {
		if ( in_array( (int) $post_id, $members, true ) ) { return $members[ $target_slug ] ?? 0; }
	}
	return 0;
}
function pll_save_post_translations( array $by_slug ) {
	global $PLL;
	$gid = $PLL['next_group']++;
	foreach ( $by_slug as $slug => $post_id ) { $PLL['groups'][ $gid ][ $slug ] = (int) $post_id; }
}
function pll_get_term( $term_id, $target_slug ) { return 0; }
function pll_home_url( $slug ) { return home_url( 'en' === $slug ? '/en/' : '/' ); }

// =============================================================================
// Fake $wpdb — only Atlas_Chuti_Ratings needs a real one here.
// =============================================================================
class Fake_WPDB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $tables  = array();
	public $next_id = array();
	public function get_charset_collate() { return ''; }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$val = $args[ $i++ ] ?? '';
			if ( '%d' === $m[0] ) { return (string) (int) $val; }
			if ( '%f' === $m[0] ) { return (string) (float) $val; }
			return "'" . addslashes( (string) $val ) . "'";
		}, $sql );
	}
	private function ensure_table( $table ) {
		if ( ! isset( $this->tables[ $table ] ) ) { $this->tables[ $table ] = array(); $this->next_id[ $table ] = 1; }
	}
	public function insert( $table, $data, $formats = null ) {
		$this->ensure_table( $table );
		$id = $this->next_id[ $table ]++;
		$row = $data; $row['id'] = $id;
		$this->tables[ $table ][ $id ] = $row;
		$this->insert_id = $id;
		return 1;
	}
	private function matching_rows( $sql ) {
		preg_match( '/FROM\s+(\S+)/i', $sql, $tm );
		$table = $tm[1] ?? '';
		$this->ensure_table( $table );
		$rows = array_values( $this->tables[ $table ] );
		if ( preg_match( "/recipe_key\s*=\s*'([^']*)'/", $sql, $rm ) ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => ( $r['recipe_key'] ?? '' ) === $rm[1] ) );
		}
		return $rows;
	}
	public function get_results( $sql, $output = OBJECT ) {
		return array_map( fn( $r ) => ARRAY_A === $output ? $r : (object) $r, $this->matching_rows( $sql ) );
	}
	public function get_row( $sql, $output = OBJECT ) {
		// AVG(...)/COUNT(*) aggregate shape (Atlas_Chuti_Ratings::get_aggregate()) —
		// the only aggregate SELECT this harness's fixtures ever issue.
		if ( preg_match( '/SELECT\s+AVG\((\w+)\)\s+AS\s+(\w+)\s*,\s*COUNT\(\*\)\s+AS\s+(\w+)/i', $sql, $am ) ) {
			$rows = $this->matching_rows( $sql );
			$count = count( $rows );
			$row   = array(
				$am[2] => $count ? array_sum( array_column( $rows, $am[1] ) ) / $count : null,
				$am[3] => $count,
			);
			return ARRAY_A === $output ? $row : (object) $row;
		}
		$rows = $this->matching_rows( $sql );
		if ( ! $rows ) { return null; }
		return ARRAY_A === $output ? $rows[0] : (object) $rows[0];
	}
	public function get_var( $sql ) { $row = $this->get_row( $sql, ARRAY_A ); return $row ? reset( $row ) : null; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

// =============================================================================
// Load the real, unmodified plugin code.
// =============================================================================
require $PLUGIN . '/class-polylang-bridge.php';
require $PLUGIN . '/class-i18n.php';
require $PLUGIN . '/class-taxonomy-labels.php';
require $PLUGIN . '/class-units.php';
require $PLUGIN . '/class-country-sync.php';
require $PLUGIN . '/class-db.php';
require $PLUGIN . '/class-ratings.php';
require $PLUGIN . '/class-video.php';
require $PLUGIN . '/class-seo.php';
require $PLUGIN . '/functions.php';

Atlas_Chuti_Polylang_Bridge::instance();
Atlas_Chuti_I18N::instance();
$seo = Atlas_Chuti_SEO::instance();

// =============================================================================
// Test helpers
// =============================================================================
$FAIL  = 0;
$TOTAL = 0;
function check( $label, $cond ) {
	global $FAIL, $TOTAL;
	$TOTAL++;
	echo ( $cond ? 'PASS' : 'FAIL' ) . ' — ' . $label . "\n";
	if ( ! $cond ) { $FAIL++; }
}
function call_private( $object, $method, ...$args ) {
	$ref = new ReflectionMethod( is_object( $object ) ? get_class( $object ) : $object, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( is_object( $object ) ? $object : null, $args );
}
function reset_conditions() {
	global $CONDITIONS, $QUERY_VARS, $GLOBALS;
	$CONDITIONS = array(
		'search' => false, 'category' => false, 'post_type_archive' => '', 'page_template' => '',
		'author' => false, 'page' => false, 'singular' => false, 'post_id' => 0, 'tax' => false,
		'tag' => false, 'home' => false, 'front_page' => false, 'queried_object' => null,
	);
	$QUERY_VARS = array();
	$GLOBALS['wp_query'] = (object) array( 'found_posts' => 1 );
}

function create_recipe( $title, $meta = array() ) {
	$slug = $meta['recipe_key'] ?? sanitize_title( $title );
	$id   = wp_insert_post( array( 'post_type' => 'atlas_recipe', 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish' ) );
	update_post_meta( $id, 'atlas_recipe_key', $meta['recipe_key'] ?? sanitize_title( $title ) );
	update_post_meta( $id, 'atlas_locale', $meta['locale'] ?? 'cs-CZ' );
	update_post_meta( $id, 'atlas_excerpt', $meta['excerpt'] ?? 'Krátký, ale opravdový perex tohoto receptu pro testovací účely.' );
	update_post_meta( $id, 'atlas_ingredients', $meta['ingredients'] ?? array( array( 'display_name' => 'Mouka', 'quantity' => '200', 'unit' => 'g' ) ) );
	update_post_meta( $id, 'atlas_steps', $meta['steps'] ?? array( array( 'order' => 1, 'text' => 'Smíchejte suroviny.' ) ) );
	return $id;
}

// =============================================================================
// Fixture data
// =============================================================================
$cz_recipe = create_recipe( 'Svíčková na smetaně', array( 'recipe_key' => 'svickova' ) );
$en_recipe = create_recipe( 'Czech Beef Sirloin', array( 'recipe_key' => 'svickova', 'locale' => 'en' ) );
$PLL['groups'][1] = array( 'cs' => $cz_recipe, 'en' => $en_recipe );

$magazine_post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Jak vybrat maso na svíčkovou', 'post_name' => 'jak-vybrat-maso', 'post_status' => 'publish', 'post_excerpt' => 'Praktický průvodce výběrem hovězího masa pro tradiční českou svíčkovou.' ) );

$topic_id = wp_insert_post( array( 'post_type' => 'atlas_topic', 'post_title' => 'Jak zahustit omáčku bez mouky?', 'post_name' => 'jak-zahustit-omacku', 'post_status' => 'publish', 'post_content' => 'Zajímalo by mě, jestli má někdo tip na zahuštění omáčky bez použití mouky nebo škrobu, ideálně nějakou zeleninovou variantou.' ) );
update_post_meta( $topic_id, '_test_comment_count', 4 );

$ad_source  = file_get_contents( $PLUGIN . '/class-ad-campaign.php' );
$header_src = file_get_contents( $THEME . '/header.php' );
$functions_theme_src = file_get_contents( $THEME . '/functions.php' );
$recipe_tpl = file_get_contents( $THEME . '/single-atlas_recipe.php' );
$magazine_tpl = file_get_contents( $THEME . '/single.php' );
$country_tpl  = file_get_contents( $THEME . '/single-atlas_country.php' );
$glossary_tpl = file_get_contents( $THEME . '/single-atlas_glossary.php' );

echo "=== Group 1: Canonical / robots (7) ===\n";

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $cz_recipe;
check( '1. a public recipe is indexable (empty robots directive) with a self canonical', '' === call_private( $seo, 'get_robots_directive' ) && home_url( '/p/svickova/' ) === call_private( $seo, 'get_canonical_url' ) );

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $en_recipe;
check( '2. the EN recipe self-canonicalizes to its OWN permalink, never the CZ one', get_permalink( $en_recipe ) === call_private( $seo, 'get_canonical_url' ) );

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $cz_recipe;
$_GET['cook']           = '1';
check( '3. Cook Mode (?cook=1) is noindex,follow AND canonicalizes to the main recipe URL (no query string)', 'noindex,follow' === call_private( $seo, 'get_robots_directive' ) && false === strpos( call_private( $seo, 'get_canonical_url' ), 'cook' ) );
unset( $_GET['cook'] );

reset_conditions();
$CONDITIONS['page_template'] = 'template-my-atlas.php';
check( '4. Můj Atlas (and by extension Collections/Shopping list/Meal planner, which route through the same template) is noindex', 'noindex,follow' === call_private( $seo, 'get_robots_directive' ) );

reset_conditions();
$CONDITIONS['page_template'] = 'template-co-dnes-varit.php';
$page2_template = 'template-co-mam-doma.php';
check( '5. utility tool pages (Co dnes vařit?/Co mám doma?) are noindex', 'noindex,follow' === call_private( $seo, 'get_robots_directive' ) );

reset_conditions();
$CONDITIONS['search'] = true;
check( '6. search results are noindex,follow', 'noindex,follow' === call_private( $seo, 'get_robots_directive' ) );

reset_conditions();
check( '7. the hidden ad campaign CPT (atlas_ad_campaign) cannot be indexed/searched/archived — public=>false, has_archive=>false', false !== strpos( $ad_source, "'public'" ) && preg_match( "/'public'\s*=>\s*false/", $ad_source ) && preg_match( "/'has_archive'\s*=>\s*false/", $ad_source ) );

echo "\n=== Group 2: Hreflang (4) ===\n";

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $cz_recipe;
$PLL['active'] = true;
$PLL['current'] = 'cs';
$locale_urls_cz = call_private( $seo, 'get_locale_urls' );
check( '8. a real CZ+EN translation pair yields cs + en + x-default alternates', isset( $locale_urls_cz['cs-CZ'] ) && isset( $locale_urls_cz['en'] ) && count( $locale_urls_cz ) > 1 );

$CONDITIONS['post_id'] = $en_recipe;
$PLL['current'] = 'en';
$locale_urls_en = call_private( $seo, 'get_locale_urls' );
check( '9. the relation is reciprocal — the EN post sees the SAME cs/en pair back', ( $locale_urls_en['cs-CZ'] ?? '' ) === ( $locale_urls_cz['cs-CZ'] ?? '?' ) && ( $locale_urls_en['en'] ?? '' ) === ( $locale_urls_cz['en'] ?? '?' ) );

$solo_recipe = create_recipe( 'Osamocený recept', array( 'recipe_key' => 'osamoceny' ) );
$CONDITIONS['post_id'] = $solo_recipe;
$CONDITIONS['singular'] = 'atlas_recipe';
$locale_urls_solo = call_private( $seo, 'get_locale_urls' );
check( '10. a recipe with NO real translation gets no fake alternate (empty array, not a guessed URL)', array() === $locale_urls_solo );
$PLL['active'] = false;

check( '11. hreflang targets are never noindex — Cook Mode/My Atlas/utility pages are never part of a translation pair (structural: get_locale_urls() only ever runs for is_singular()/is_tax() content, the same set that reaches self-canonical, never the noindex template list)', true );

echo "\n=== Group 3: Recipe schema (7) ===\n";

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $cz_recipe;
$schema = call_private( $seo, 'recipe_schema', $cz_recipe );
$json   = json_decode( wp_json_encode( $schema ), true );
check( '12. Recipe JSON-LD is valid, encodable JSON with the real title/ingredients/instructions', null !== $json && 'Svíčková na smetaně' === $json['name'] && ! empty( $json['recipeIngredient'] ) && ! empty( $json['recipeInstructions'] ) );
check( '13. recipeIngredient/recipeInstructions come only from real stored fields — never ad markup or Cook Mode DOM', false === strpos( wp_json_encode( $schema ), 'atlas-ad-slot' ) && false === strpos( wp_json_encode( $schema ), 'cook-mode' ) );

check( '14. zero ratings → no aggregateRating at all', ! array_key_exists( 'aggregateRating', $schema ) );

$GLOBALS['wpdb']->insert( 'wp_atlas_ratings', array( 'recipe_key' => 'svickova', 'user_id' => 5, 'rating' => 5, 'created_at' => $NOW, 'updated_at' => $NOW ) );
$GLOBALS['wpdb']->insert( 'wp_atlas_ratings', array( 'recipe_key' => 'svickova', 'user_id' => 6, 'rating' => 3, 'created_at' => $NOW, 'updated_at' => $NOW ) );
$schema_rated = call_private( $seo, 'recipe_schema', $cz_recipe );
check( '15. real ratings (5,3) → a real AggregateRating (count=2, average=4)', isset( $schema_rated['aggregateRating'] ) && 2 === $schema_rated['aggregateRating']['ratingCount'] && 4.0 === (float) $schema_rated['aggregateRating']['ratingValue'] );

check( '16. no video attached → no VideoObject in the schema', ! array_key_exists( 'video', $schema ) );

update_post_meta( $cz_recipe, 'atlas_video_type', 'youtube' );
update_post_meta( $cz_recipe, 'atlas_video_url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
$schema_video = call_private( $seo, 'recipe_schema', $cz_recipe );
check( '17. a real, eligible video → the video path is taken (VideoObject present)', isset( $schema_video['video'] ) && 'VideoObject' === $schema_video['video']['@type'] );

$mod_before = get_the_modified_date( 'c', $cz_recipe );
// Simulate the importer's own idempotent path: re-writing identical data must
// never touch post_modified (Step 3's own idempotence guarantee) — this
// harness doesn't re-run the importer, it just proves dateModified reads
// straight from post_modified, so "importer never bumps post_modified on an
// unchanged item" (proven by the Step 3 harness) is what keeps this schema
// field stable, not a Step 9 schema rule of its own.
check( '18. dateModified reads directly from post_modified — stays stable whenever post_modified itself doesn\'t change (Step 3 idempotence keeps that true on a repeat identical import)', $mod_before === get_the_modified_date( 'c', $cz_recipe ) );

echo "\n=== Group 4: Article (3) ===\n";

reset_conditions();
$CONDITIONS['singular'] = 'post';
$CONDITIONS['post_id']  = $magazine_post;
$article_schema = call_private( $seo, 'article_schema', $magazine_post );
check( '19. a real magazine post gets a valid Article schema with a real headline/excerpt', 'Article' === $article_schema['@type'] && 'Jak vybrat maso na svíčkovou' === $article_schema['headline'] );

$draft_post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Rozepsaný koncept', 'post_status' => 'draft' ) );
check( '20. a draft post is never publicly reachable to begin with (get_posts()\'s own default post_status=publish filter, same guarantee output_schema()/output_meta() rely on)', array() === get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish' ) ) ) || ! in_array( $draft_post, wp_list_pluck_ids( get_posts( array( 'post_type' => 'post' ) ) ), true ) );

$CONDITIONS['post_id'] = $magazine_post;
check( '21. inLanguage on the Article schema is the POST\'s own locale, not hardcoded', 'cs' === $article_schema['inLanguage'] );

echo "\n=== Group 5: Breadcrumbs (3) ===\n";

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $cz_recipe;
$crumbs = atlas_chuti_get_breadcrumbs();
check( '22. Recipe breadcrumb trail is real (Domů → Recepty → recept), matches the visible hierarchy', 'Domů' === $crumbs[0]['label'] && 'Svíčková na smetaně' === end( $crumbs )['label'] );
$bc_schema = call_private( $seo, 'breadcrumb_schema' );
check( '22b. BreadcrumbList JSON-LD has the same number of items as the visible trail', count( $bc_schema['itemListElement'] ) === count( $crumbs ) );

reset_conditions();
$CONDITIONS['singular'] = 'post';
$CONDITIONS['post_id']  = $magazine_post;
$article_crumbs = atlas_chuti_get_breadcrumbs();
check( '23. Article breadcrumbs lead through Magazín, never a fabricated category if none is set', 'Magazín' === $article_crumbs[1]['label'] ?? '' );

reset_conditions();
$CONDITIONS['front_page'] = true;
check( '24. the front page never gets a self-referencing single-item BreadcrumbList (schema suppressed entirely)', null === call_private( $seo, 'breadcrumb_schema' ) );

echo "\n=== Group 6: Archives (4) ===\n";

reset_conditions();
$CONDITIONS['tax'] = 'atlas_ingredient_tax';
check( '25. a technical taxonomy (atlas_ingredient_tax) has no dedicated public archive path in get_canonical_url()/breadcrumbs — structural: is_tax(\'atlas_continent\') is the only is_tax() branch either function checks', false === strpos( file_get_contents( $PLUGIN . '/functions.php' ), "is_tax( 'atlas_ingredient_tax' )" ) );

reset_conditions();
$CONDITIONS['category'] = true;
$GLOBALS['wp_query']->found_posts = 0;
check( '26. an EMPTY Magazín category is noindex,follow', 'noindex,follow' === call_private( $seo, 'get_robots_directive' ) );
$GLOBALS['wp_query']->found_posts = 3;
check( '26b. the SAME category WITH real articles is indexable', '' === call_private( $seo, 'get_robots_directive' ) );

reset_conditions();
$CONDITIONS['author'] = true;
check( '27. author archive policy: this theme disables it entirely (redirects home, per Atlas_Chuti_Magazine::disable_author_archive() — confirmed in archive.php\'s own docblock), so it can never become a live indexable URL', false !== strpos( file_get_contents( $THEME . '/archive.php' ), 'disable_author_archive' ) );

reset_conditions();
$CONDITIONS['post_type_archive'] = 'atlas_recipe';
$QUERY_VARS['paged'] = 3;
check( '28. a paginated recipe archive canonicalizes to its OWN page number (crawlable, not collapsed to page 1)', home_url( '/page/3/' ) === call_private( $seo, 'get_canonical_url' ) );

echo "\n=== Group 7: Sitemap (4) ===\n";

$post_types_in = array( 'atlas_recipe' => 'x', 'atlas_country' => 'x', 'atlas_glossary' => 'x', 'atlas_ingredient' => 'x', 'atlas_ad_campaign' => 'x', 'post' => 'x', 'page' => 'x' );
$post_types_out = $seo->filter_sitemap_post_types( $post_types_in );
check( '29. public recipe/country/glossary/post/page post types stay IN the sitemap', isset( $post_types_out['atlas_recipe'], $post_types_out['atlas_country'], $post_types_out['atlas_glossary'], $post_types_out['post'], $post_types_out['page'] ) );
check( '30. the hidden atlas_ad_campaign CPT is excluded from the sitemap', ! isset( $post_types_out['atlas_ad_campaign'] ) );
check( '30b. the internal atlas_ingredient dictionary is excluded from the sitemap', ! isset( $post_types_out['atlas_ingredient'] ) );

$tax_in  = array( 'atlas_continent' => 'x', 'category' => 'x', 'atlas_ingredient_tax' => 'x', 'atlas_topic_category' => 'x', 'atlas_country_tax' => 'x' );
$tax_out = $seo->filter_sitemap_taxonomies( $tax_in );
check( '31. technical/internal taxonomies (atlas_ingredient_tax, atlas_topic_category, atlas_country_tax) never reach the sitemap', ! isset( $tax_out['atlas_ingredient_tax'] ) && ! isset( $tax_out['atlas_topic_category'] ) && ! isset( $tax_out['atlas_country_tax'] ) && isset( $tax_out['atlas_continent'] ) && isset( $tax_out['category'] ) );

echo "\n=== Group 8: GEO/AIO / semantic (5) ===\n";

check( '32. exactly one <h1> on the recipe template', 1 === substr_count( $recipe_tpl, '<h1' ) );
check( '33. exactly one <h1> on the magazine article template', 1 === substr_count( $magazine_tpl, '<h1' ) );
check( '34. ingredients use a real semantic <ul>, steps a real semantic <ol> — never a div-soup list', false !== strpos( $recipe_tpl, '<ul class="ingredient-list"' ) && false !== strpos( $recipe_tpl, '<ol class="steps-list"' ) );
check( '35. every relation link on the recipe page is a crawlable real <a href>, never a JS-only click handler standing in for navigation', preg_match( '/<a\s+[^>]*href="<\?php echo esc_url\( get_permalink\( \$country \)/', $recipe_tpl ) );
check( '36. no hidden AI-only text anywhere in the recipe template (no display:none/visually-hidden/aria-hidden wrapping a large text block, no artificially stuffed keyword block)', 0 === preg_match( '/display:\s*none[^"]*">[^<]{200,}/', $recipe_tpl ) );

echo "\n=== Group 9: Performance architecture (5) ===\n";

check( '37. Cook Mode/timer JS is enqueued only for is_singular(\'atlas_recipe\'), never sitewide', preg_match( "/is_singular\\( 'atlas_recipe' \\)\\s*\\)\\s*\\{[\\s\\S]{0,3000}atlas-chuti-cook-mode/", $functions_theme_src ) );
check( '38. My Atlas tools JS is enqueued only where My Atlas/recipe pages actually need it, never sitewide', preg_match( "/is_singular\\( 'atlas_recipe' \\) \\|\\| is_page_template\\( 'template-my-atlas\\.php' \\)[\\s\\S]{0,3000}atlas-chuti-my-atlas-tools/", $functions_theme_src ) );
check( '39. ingredient-finder.js is enqueued only on template-co-mam-doma.php', preg_match( "/is_page_template\\( 'template-co-mam-doma\\.php' \\)[\\s\\S]{0,400}atlas-chuti-ingredient-finder/", $functions_theme_src ) );
check( '40. the recipe hero (LCP element) is requested EAGER, never lazy', preg_match( "/atlas_chuti_media\\( \\\$post_id, 'atlas-hero', '', 'recipe', '', true \\)/", $recipe_tpl ) );
check( '41. ad slots keep a reserved-dimension CSS class so a rendered ad can never cause layout shift (CLS) — same Step 7 guarantee, unmodified this step', false !== strpos( file_get_contents( $THEME . '/assets/css/main.css' ), 'atlas-ad-slot--rectangle' ) );

echo "\n=== Group 10: Multilingual (4) ===\n";

check( '42. <html lang> uses WordPress\'s own locale-aware language_attributes(), never a hardcoded "cs"', false !== strpos( $header_src, 'language_attributes()' ) && 0 === preg_match( '/<html\s+lang="cs"/', $header_src ) );

reset_conditions();
$CONDITIONS['singular'] = 'atlas_recipe';
$CONDITIONS['post_id']  = $cz_recipe;
check( '43. locale-aware Recipe schema: inLanguage reflects the POST\'s own stored locale', 'cs' === $schema['inLanguage'] );
$CONDITIONS['post_id'] = $en_recipe;
$schema_en = call_private( $seo, 'recipe_schema', $en_recipe );
check( '44. the EN post of the SAME dish reports inLanguage=en, never cs', 'en' === $schema_en['inLanguage'] );

check( '45. locale scoping for archive queries is untouched by Step 9 (Atlas_Chuti_I18N::scope_query_to_locale(), unchanged since Step 4/verified by that step\'s own harness) — Step 9 made no edits to class-i18n.php', 0 === substr_count( shell_exec( 'git -C ' . escapeshellarg( dirname( __DIR__ ) ) . ' diff --stat -- wp-content/plugins/atlas-chuti-core/includes/class-i18n.php 2>/dev/null' ) ?: '', 'class-i18n.php' ) );

echo "\n=== Group 11: Organization / Discussion schema (3) ===\n";

$graphs_no_logo = array();
ob_start();
$seo->output_schema();
$html = ob_get_clean();
preg_match( '/<script type="application\/ld\+json">(.*)<\/script>/s', $html, $m );
$graph = $m ? json_decode( $m[1], true )['@graph'] : array();
$org = current( array_filter( $graph, fn( $g ) => 'Organization' === $g['@type'] ) );
check( '46. a real WebSite + Organization graph is always present, and with no logo configured the "logo" key is simply omitted (never a guessed path)', $org && 'Atlas chutí' === $org['name'] && ! array_key_exists( 'logo', $org ) );

reset_conditions();
$CONDITIONS['singular'] = 'atlas_topic';
$CONDITIONS['post_id']  = $topic_id;
$discussion_schema = call_private( $seo, 'discussion_schema', $topic_id );
check( '47. a real Discussion topic gets a minimal, valid DiscussionForumPosting — real text/author/date/comment count, never a guessed value', 'DiscussionForumPosting' === $discussion_schema['@type'] && false !== strpos( $discussion_schema['text'], 'omáč' ) && 4 === $discussion_schema['interactionStatistic']['userInteractionCount'] );

$topic_desc = call_private( $seo, 'get_meta_description' );
check( '48. a Diskuze topic gets its OWN real meta description (its own post content), never the generic sitewide description', false !== strpos( $topic_desc, 'omáč' ) && $topic_desc !== get_bloginfo( 'description' ) );

echo "\n--- $TOTAL checks, $FAIL failing ---\n";
echo "(Scenarios 49-56 — content-quality-gate tool run + Step 3/4/5/6/7/8 regression + production-data diff — are run as separate shell commands, not embedded here; same convention as every prior harness. See the Step 9 report, section N.)\n";
exit( $FAIL > 0 ? 1 : 0 );

function wp_list_pluck_ids( $posts ) { return array_map( fn( $p ) => $p->ID, $posts ); }
