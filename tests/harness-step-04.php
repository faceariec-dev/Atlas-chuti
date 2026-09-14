<?php
/**
 * KROK 4 test harness — deterministic, no external dependencies, no live WP/DB, no
 * real Polylang install. Extends the same approach as harness-step-03.php (stubs just
 * enough of the WordPress API to run the REAL, unmodified plugin classes against an
 * in-memory fake database) with a minimal fake Polylang runtime — just enough of
 * pll_get_post()/pll_save_post_translations()/pll_set_post_language()/
 * pll_current_language()/pll_languages_list()/pll_home_url() to exercise
 * class-polylang-bridge.php's real, unmodified code for real, rather than mocking the
 * bridge itself away.
 *
 * Covers the 30 numbered KROK 4 scenarios (docs/implementation-reports/
 * step-04-multilingual-cz-en.md section I): locale validation (4), CZ/EN recipe pair
 * creation + same-batch resolution (6), duplicate/identity handling (4), idempotence
 * (4), query isolation (2), SEO-adjacent data correctness (4), controlled tags /
 * ingredient CZ+EN labels (3), Kulinářský pas / fallback language-independence (3).
 *
 * What this harness deliberately does NOT attempt (see the report's section M): full
 * hreflang/canonical HTML output, the language switcher template tag, and the real
 * sitemap — all of those need `is_singular()`/`get_queried_object()`/the WP loop/real
 * permalinks, i.e. an actual WordPress front-end request context this headless
 * harness cannot safely fake without reimplementing WP_Query. Those need staging
 * verification with Polylang actually installed — see the report.
 *
 * Run: `php tests/harness-step-04.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', '/tmp/' );

$PLUGIN = dirname( __DIR__ ) . '/wp-content/plugins/atlas-chuti-core/includes';

// =============================================================================
// Fake database (identical shape to harness-step-03.php)
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
$OPTIONS = array();
$HOOKS   = array();
$NOW     = 'T0';

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
function add_filter( $tag, $cb, $priority = 10, $accepted_args = 1 ) { /* not exercised by import path */ }
function apply_filters( $tag, $value, ...$args ) { return $value; }
function is_admin() { global $IS_ADMIN; return $IS_ADMIN ?? false; }

class WP_Error {
	public $code;
	public $msg;
	public function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
	public function get_error_message() { return $this->msg; }
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
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $s ) { return trim( (string) $s ); }
function absint( $s ) { return abs( (int) $s ); }
function remove_accents( $s ) { return (string) $s; }
function wp_json_encode( $x ) { return json_encode( $x ); } // phpcs:ignore -- test harness only.
function __( $s, $d = null ) { return $s; }
function wp_is_post_autosave( $id ) { return false; }
function wp_is_post_revision( $id ) { return false; }
function get_option( $k ) { global $OPTIONS; return $OPTIONS[ $k ] ?? false; }
function update_option( $k, $v ) { global $OPTIONS; $OPTIONS[ $k ] = $v; return true; }
function register_taxonomy( $tax, $object_types, $args = array() ) {
	global $DB;
	if ( ! isset( $DB['terms'][ $tax ] ) ) {
		$DB['terms'][ $tax ] = array();
	}
	return true;
}
function register_post_type( $pt, $args = array() ) { return true; }
function home_url( $path = '/' ) { return 'https://atlaschuti.cz' . $path; }

// =============================================================================
// Posts (identical to harness-step-03.php)
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
function get_page_by_path( $slug, $output = OBJECT, $post_type = 'page' ) {
	global $DB;
	foreach ( $DB['posts'] as $p ) {
		if ( $p['post_type'] === $post_type && $p['post_name'] === $slug ) {
			return db_post_to_object( $p );
		}
	}
	return null;
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
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
// Taxonomy terms (identical to harness-step-03.php)
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
	if ( 'slugs' === ( $args['fields'] ?? '' ) ) {
		return array_values(
			array_filter(
				array_map( fn( $tid ) => $DB['terms'][ $taxonomy ][ $tid ]['slug'] ?? null, $ids )
			)
		);
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
	if ( ! empty( $args['number'] ) ) {
		$out = array_slice( $out, 0, $args['number'] );
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

class Fake_WPDB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public function esc_like( $s ) { return $s; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_col( $sql ) { return array(); }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

/**
 * A bare-bones stand-in for WP_Query, just enough for
 * Atlas_Chuti_I18N::scope_query_to_locale() (which only ever calls ->get()/->set())
 * to run against for real, without needing the rest of WP_Query's query-building.
 */
class Fake_WP_Query {
	public $vars;
	public function __construct( $vars = array() ) { $this->vars = $vars; }
	public function get( $key ) { return $this->vars[ $key ] ?? ''; }
	public function set( $key, $val ) { $this->vars[ $key ] = $val; }
}

// =============================================================================
// Fake Polylang runtime — just enough of the real public API
// (https://polylang.pro/doc/function-reference/) for class-polylang-bridge.php's
// REAL, unmodified code to run against, without a real Polylang install. State lives
// in $PLL: 'current' (the simulated current-request language slug), 'post_lang'
// (post_id => slug), 'groups' (an arbitrary group id => [slug => post_id], populated
// by pll_save_post_translations() exactly like real Polylang links posts together).
// =============================================================================
$PLL = array(
	'current'    => 'cs',
	'post_lang'  => array(),
	'groups'     => array(),
	'next_group' => 1,
);

function pll_languages_list( $args = array() ) { return array( 'cs', 'en' ); }
function pll_current_language( $field = 'slug' ) { global $PLL; return $PLL['current']; }
function pll_set_post_language( $post_id, $slug ) { global $PLL; $PLL['post_lang'][ $post_id ] = $slug; }
function pll_save_post_translations( array $by_slug ) {
	global $PLL;
	// Real Polylang merges into any existing group any of these post IDs already
	// belong to; this stub does the same simplified version — enough to prove
	// link_recipe_translations() actually calls through with the right IDs.
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
function pll_get_term( $term_id, $target_slug ) { return 0; } // not exercised by these fixtures.
function pll_home_url( $slug ) { return home_url( 'en' === $slug ? '/en/' : '/' ); }

// =============================================================================
// Load the REAL plugin classes (unmodified requires).
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
require $PLUGIN . '/class-seo.php';
require $PLUGIN . '/functions.php';

Atlas_Chuti_Polylang_Bridge::instance();
Atlas_Chuti_I18N::instance();
Atlas_Chuti_Country_Sync::instance();
Atlas_Chuti_Ingredient_Sync::instance();
Atlas_Chuti_Taxonomies::instance()->register();
$importer = Atlas_Chuti_JSON_Importer::instance();

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
function row_by_title( $report, $group, $title ) {
	foreach ( $report['groups'][ $group ] ?? array() as $row ) {
		if ( $row['title'] === $title ) {
			return $row;
		}
	}
	return null;
}
function find_post_id_by_slug( $post_type, $slug ) {
	global $DB;
	foreach ( $DB['posts'] as $id => $p ) {
		if ( $post_type === $p['post_type'] && $p['post_name'] === $slug ) {
			return $id;
		}
	}
	return 0;
}
/** Calls a private/protected method via Reflection — used only for units genuinely
 * unreachable without a full WP front-end request context (see the file docblock). */
function call_private( $object, $method, ...$args ) {
	$ref = new ReflectionMethod( get_class( $object ), $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $object, $args );
}

echo "=== Group 1: Locale validation (4) ===\n";

// 1. "cs_CZ" (underscore, KROK 4 brief's own spelling) normalizes to the internal
// "cs-CZ" tag on import, not stored verbatim.
$g1_cs = array(
	'title' => 'Locale Test CS', 'slug' => 'locale-test-cs', 'recipe_key' => 'locale_test_cs',
	'locale' => 'cs_CZ', 'country' => 'IT', 'excerpt' => 'Fixture recipe testing locale normalization on import.',
	'servings_default' => 2, 'prep_minutes' => 5,
	'ingredients' => array( array( 'ingredient_key' => 'flour', 'display_name' => 'Mouka', 'quantity' => '100', 'unit' => 'g' ) ),
	'steps' => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$importer->run_import_sync( array( 'countries' => array( array( 'title' => 'Italy', 'iso_code' => 'IT', 'flag_emoji' => '🇮🇹', 'continent' => 'europe', 'intro' => 'Fixture.' ) ) ), false );
$importer->run_import_sync( array( 'recipes' => array( $g1_cs ) ), false );
$id_cs = find_post_id_by_slug( 'atlas_recipe', 'locale-test-cs' );
check( '1. "cs_CZ" (underscore) input normalizes to internal "cs-CZ"', 'cs-CZ' === get_post_meta( $id_cs, 'atlas_locale', true ) );

// 2. "en_US" normalizes to the internal "en" tag.
$importer->run_import_sync( array( 'countries' => array( array( 'title' => 'Italy', 'iso_code' => 'IT', 'flag_emoji' => '🇮🇹', 'continent' => 'europe', 'intro' => 'Fixture EN.', 'locale' => 'en' ) ) ), false );
$g2_en = array(
	'title' => 'Locale Test EN', 'slug' => 'locale-test-en', 'recipe_key' => 'locale_test_en',
	'locale' => 'en_US', 'country' => 'IT', 'excerpt' => 'Fixture recipe testing locale normalization on import.',
	'servings_default' => 2, 'prep_minutes' => 5,
	'ingredients' => array( array( 'ingredient_key' => 'flour', 'display_name' => 'Flour', 'quantity' => '100', 'unit' => 'g' ) ),
	'steps' => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$importer->run_import_sync( array( 'recipes' => array( $g2_en ) ), false );
$id_en = find_post_id_by_slug( 'atlas_recipe', 'locale-test-en' );
check( '2. "en_US" (underscore) input normalizes to internal "en"', 'en' === get_post_meta( $id_en, 'atlas_locale', true ) );

// 3. Unsupported locale -> hard validation error, no post created.
$g3_bad = $g1_cs;
$g3_bad['title'] = 'Locale Test Bad'; $g3_bad['slug'] = 'locale-test-bad'; $g3_bad['recipe_key'] = 'locale_test_bad'; $g3_bad['locale'] = 'de-DE';
$posts_before = count( $DB['posts'] );
$report = $importer->run_import_sync( array( 'recipes' => array( $g3_bad ) ), false );
$row = row_by_title( $report, 'recipes', 'Locale Test Bad' );
check( '3. unsupported locale "de-DE" -> chyba (hard validation error)', $row && 'chyba' === $row['status'] );
check( '3b. no post created for the rejected locale', count( $DB['posts'] ) === $posts_before );

// 4. Missing locale field -> defaults to DEFAULT_LOCALE (backward compatible).
$g4_nolocale = array(
	'title' => 'Locale Test Default', 'slug' => 'locale-test-default', 'recipe_key' => 'locale_test_default',
	'country' => 'IT', 'excerpt' => 'Fixture recipe testing the no-locale-field default.',
	'servings_default' => 2, 'prep_minutes' => 5,
	'ingredients' => array( array( 'ingredient_key' => 'flour', 'display_name' => 'Mouka', 'quantity' => '100', 'unit' => 'g' ) ),
	'steps' => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$importer->run_import_sync( array( 'recipes' => array( $g4_nolocale ) ), false );
$id_default = find_post_id_by_slug( 'atlas_recipe', 'locale-test-default' );
check( '4. missing locale field defaults to cs-CZ (backward compatible)', 'cs-CZ' === get_post_meta( $id_default, 'atlas_locale', true ) );

echo "\n=== Group 2: CZ/EN recipe pair creation + same-batch resolution (6) ===\n";

$pair = json_decode( file_get_contents( __DIR__ . '/fixtures/step-04-cz-en-pair.json' ), true );
$report = $importer->run_import_sync( $pair, false );
$row_cz = row_by_title( $report, 'recipes', 'Špagety Carbonara' );
$row_en = row_by_title( $report, 'recipes', 'Spaghetti Carbonara' );
check( '5. CZ+EN pair in the same batch both import successfully', $row_cz && 'vytvořeno' === $row_cz['status'] && $row_en && 'vytvořeno' === $row_en['status'] );

$cz_id = find_post_id_by_slug( 'atlas_recipe', 'spagety-carbonara' );
$en_id = find_post_id_by_slug( 'atlas_recipe', 'spaghetti-carbonara' );
check( '5b. two DISTINCT posts were created (not merged into one)', $cz_id > 0 && $en_id > 0 && $cz_id !== $en_id );

check( '6. both posts share the SAME atlas_recipe_key', 'spaghetti-carbonara' === get_post_meta( $cz_id, 'atlas_recipe_key', true )
	&& get_post_meta( $cz_id, 'atlas_recipe_key', true ) === get_post_meta( $en_id, 'atlas_recipe_key', true ) );

check( '7. each post has its own distinct atlas_locale', 'cs-CZ' === get_post_meta( $cz_id, 'atlas_locale', true ) && 'en' === get_post_meta( $en_id, 'atlas_locale', true ) );

check(
	'8. find_by_recipe_key() resolves each locale to the correct, distinct post',
	Atlas_Chuti_I18N::find_by_recipe_key( 'spaghetti-carbonara', 'cs-CZ' )->ID === $cz_id
	&& Atlas_Chuti_I18N::find_by_recipe_key( 'spaghetti-carbonara', 'en' )->ID === $en_id
);

check(
	'9. CZ and EN content is independent (no cross-locale content bleed)',
	'Vejce' === ( get_post_meta( $cz_id, 'atlas_ingredients', true )[1]['display_name'] ?? '' )
	&& 'Eggs' === ( get_post_meta( $en_id, 'atlas_ingredients', true )[1]['display_name'] ?? '' )
);

// atlas_country_tax has exactly ONE canonical term per ISO code shared across every
// locale (see class-country-sync.php's own docblock) — so both recipes tag the SAME
// term; what must never be confused across locales is the term's locale_post_map,
// which resolve_reference()'s country lookup populated from THIS SAME BATCH's own
// two country items (cs-CZ Itálie / en Italy).
$cz_country_term = get_post_meta( $cz_id, '_atlas_recipe_primary_country_term_id', true );
$en_country_term = get_post_meta( $en_id, '_atlas_recipe_primary_country_term_id', true );
check(
	'10. same-batch dependency resolution: the shared country term\'s locale_post_map resolves to the country post CREATED IN THE SAME BATCH for each recipe\'s OWN locale (never confused across locales)',
	$cz_country_term && $en_country_term
	&& get_post_meta( Atlas_Chuti_Country_Sync::get_country_post_for_term( $cz_country_term, 'cs-CZ' ), 'atlas_locale', true ) === 'cs-CZ'
	&& get_post_meta( Atlas_Chuti_Country_Sync::get_country_post_for_term( $en_country_term, 'en' ), 'atlas_locale', true ) === 'en'
);

echo "\n=== Group 3: Duplicate / identity handling (4) ===\n";

check( '11. same recipe_key across two DIFFERENT locales is not a duplicate (both posts from Group 2 exist)', $cz_id > 0 && $en_id > 0 );

// 12. same recipe_key + SAME locale twice in one batch -> error for both.
$dup_a = array( 'title' => 'Dup A', 'slug' => 'dup-a', 'recipe_key' => 'dup_key_g3', 'country' => 'IT', 'excerpt' => 'Fixture duplicate recipe_key test A.', 'servings_default' => 2, 'prep_minutes' => 5, 'ingredients' => array( array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '100', 'unit' => 'g' ) ), 'steps' => array( array( 'order' => 1, 'text' => 'Cook.' ) ) );
$dup_b = $dup_a; $dup_b['title'] = 'Dup B'; $dup_b['slug'] = 'dup-b';
$report = $importer->run_import_sync( array( 'recipes' => array( $dup_a, $dup_b ) ), false );
$row_a = row_by_title( $report, 'recipes', 'Dup A' );
$row_b = row_by_title( $report, 'recipes', 'Dup B' );
check( '12. duplicate recipe_key + SAME locale in one batch -> chyba for both', $row_a && 'chyba' === $row_a['status'] && $row_b && 'chyba' === $row_b['status'] );

// 13. translation_group MISMATCH between CZ/EN variants of the same recipe_key ->
// link_recipe_translations() reports a warning and does NOT call Polylang's linker.
$mismatch_cz = array( 'title' => 'Mismatch CZ', 'slug' => 'mismatch-cz', 'recipe_key' => 'mismatch_key', 'translation_group' => 'group_a', 'locale' => 'cs-CZ', 'country' => 'IT', 'excerpt' => 'Fixture translation_group mismatch test (CZ).', 'servings_default' => 2, 'prep_minutes' => 5, 'ingredients' => array( array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '100', 'unit' => 'g' ) ), 'steps' => array( array( 'order' => 1, 'text' => 'Cook.' ) ) );
$mismatch_en = $mismatch_cz;
$mismatch_en['title'] = 'Mismatch EN'; $mismatch_en['slug'] = 'mismatch-en'; $mismatch_en['locale'] = 'en'; $mismatch_en['translation_group'] = 'group_b';
$groups_before = count( $PLL['groups'] );
$report = $importer->run_import_sync( array( 'recipes' => array( $mismatch_cz, $mismatch_en ) ), false );
$row_mismatch_en = row_by_title( $report, 'recipes', 'Mismatch EN' );
check( '13. translation_group mismatch between CZ/EN variants -> warning message, never silently resolved', $row_mismatch_en && false !== strpos( $row_mismatch_en['message'], 'translation_group se liší' ) );
check( '13b. Polylang was NOT asked to link the mismatched pair (no new group created)', count( $PLL['groups'] ) === $groups_before );

// 14. translation_group MATCHING between variants -> Polylang IS asked to link them.
check( '14. matching translation_group (Group 2 fixture pair) -> Polylang translation link established', pll_get_post( $cz_id, 'en' ) === $en_id && pll_get_post( $en_id, 'cs' ) === $cz_id );

echo "\n=== Group 4: Idempotence (4) ===\n";

$NOW = 'T30';
$en_mod_before = $DB['posts'][ $en_id ]['post_modified'];
$pair_cz_changed = $pair;
$pair_cz_changed['recipes'][0]['excerpt'] = 'Změněný fixture perex pouze pro CZ verzi — EN verze se nesmí změnit.';
unset( $pair_cz_changed['recipes'][1] ); // only the CZ item in this batch.
$pair_cz_changed['recipes'] = array( $pair_cz_changed['recipes'][0] );
$report = $importer->run_import_sync( $pair_cz_changed, false );
$row = row_by_title( $report, 'recipes', 'Špagety Carbonara' );
check( '15. CZ-only update never touches the EN post (EN post_modified unchanged)', $row && 'aktualizováno' === $row['status'] && $DB['posts'][ $en_id ]['post_modified'] === $en_mod_before );

$NOW = 'T31';
$report = $importer->run_import_sync( array( 'recipes' => array( $pair['recipes'][1] ) ), false );
$row = row_by_title( $report, 'recipes', 'Spaghetti Carbonara' );
check( '16. reimporting the identical EN item -> beze změny, no write', $row && 'beze změny' === $row['status'] );

$groups_before_reimport = $PLL['groups'];
$importer->run_import_sync( $pair, false );
check( '17. reimporting the whole CZ+EN pair again does not create a SECOND Polylang translation group (still linked, idempotently)', count( $PLL['groups'] ) === count( $groups_before_reimport ) && pll_get_post( $cz_id, 'en' ) === $en_id );

check(
	'18. recipe_key/translation_group values are stable across multiple reimports (no drift)',
	'spaghetti-carbonara' === get_post_meta( $cz_id, 'atlas_recipe_key', true )
	&& 'recipe-spaghetti-carbonara' === get_post_meta( $cz_id, 'atlas_translation_group', true )
	&& get_post_meta( $cz_id, 'atlas_translation_group', true ) === get_post_meta( $en_id, 'atlas_translation_group', true )
);

echo "\n=== Group 5: Query isolation (2) ===\n";

// 19. A locale-scoped query for the DEFAULT locale (cs-CZ) falls back to also
// matching legacy content with NO atlas_locale meta at all (never silently excluded).
$legacy_id = find_post_id_by_slug( 'atlas_recipe', 'locale-test-cs' );
delete_post_meta( $legacy_id, 'atlas_locale' ); // simulate genuinely pre-KROK-4 content.
$PLL['current'] = 'cs';
$q = new Fake_WP_Query( array( 'post_type' => 'atlas_recipe' ) );
Atlas_Chuti_I18N::instance()->scope_query_to_locale( $q );
$meta_query = $q->get( 'meta_query' );
$has_or_not_exists = false;
foreach ( $meta_query as $clause ) {
	if ( is_array( $clause ) && ( 'OR' === ( $clause['relation'] ?? '' ) ) ) {
		foreach ( $clause as $sub ) {
			if ( is_array( $sub ) && 'NOT EXISTS' === ( $sub['compare'] ?? '' ) ) {
				$has_or_not_exists = true;
			}
		}
	}
}
check( '19. cs-CZ (default locale) query includes an OR/NOT-EXISTS fallback for locale-less legacy content', $has_or_not_exists );

// 20. The SAME query for the NON-default locale (en) requires an EXACT match only —
// legacy/locale-less content must never be mistaken for English content.
$PLL['current'] = 'en';
$q2 = new Fake_WP_Query( array( 'post_type' => 'atlas_recipe' ) );
Atlas_Chuti_I18N::instance()->scope_query_to_locale( $q2 );
$meta_query2 = $q2->get( 'meta_query' );
$has_exact_only = false;
foreach ( $meta_query2 as $clause ) {
	if ( is_array( $clause ) && ( $clause['key'] ?? '' ) === 'atlas_locale' && '=' === ( $clause['compare'] ?? '' ) && 'en' === ( $clause['value'] ?? '' ) ) {
		$has_exact_only = true;
	}
}
check( '20. en (non-default locale) query requires an EXACT atlas_locale=en match, no NOT-EXISTS fallback', $has_exact_only );
$PLL['current'] = 'cs'; // restore for the remaining checks.

echo "\n=== Group 6: SEO-adjacent data correctness (4) ===\n";
echo "    (full hreflang/canonical HTML output needs a real WP request context — see report section M)\n";

check( '21. Atlas_Chuti_Polylang_Bridge::locale_to_slug() maps our internal tags to Polylang slugs correctly', 'cs' === Atlas_Chuti_Polylang_Bridge::locale_to_slug( 'cs-CZ' ) && 'en' === Atlas_Chuti_Polylang_Bridge::locale_to_slug( 'en' ) );

$seo = Atlas_Chuti_SEO::instance();
check(
	'22. og:locale tag mapping (OpenGraph\'s OWN cs_CZ/en_US convention, distinct from our internal cs-CZ/en tag)',
	'cs_CZ' === call_private( $seo, 'og_locale_tag', 'cs-CZ' ) && 'en_US' === call_private( $seo, 'og_locale_tag', 'en' )
);

check( '23. the real Polylang translation link (Group 3, test 14) resolves both directions — what hreflang/schema code reads from once wired to a real request', Atlas_Chuti_Polylang_Bridge::get_post_translation_id( $cz_id, 'en' ) === $en_id && Atlas_Chuti_Polylang_Bridge::get_post_translation_id( $en_id, 'cs-CZ' ) === $cz_id );

check( '24. Recipe schema\'s inLanguage source (Atlas_Chuti_I18N::get_locale()) is correct per-post, not per-request', 'cs-CZ' === Atlas_Chuti_I18N::get_locale( $cz_id ) && 'en' === Atlas_Chuti_I18N::get_locale( $en_id ) );

echo "\n=== Group 7: Controlled tags / ingredient CZ+EN labels (3) ===\n";

check( '25. controlled tag "traditional" has a distinct label per locale, same shared key', 'Tradiční' === Atlas_Chuti_Taxonomy_Labels::label( 'atlas_recipe_tag', 'traditional', 'cs-CZ' ) && 'Traditional' === Atlas_Chuti_Taxonomy_Labels::label( 'atlas_recipe_tag', 'traditional', 'en' ) );

$cz_pasta = Atlas_Chuti_I18N::find_ingredient_by_key( 'pasta', 'cs-CZ' );
$en_pasta = Atlas_Chuti_I18N::find_ingredient_by_key( 'pasta', 'en' );
check(
	'26. the SAME ingredient_key ("pasta") resolves to TWO separate locale-scoped dictionary posts with locale-correct display names',
	$cz_pasta && $en_pasta && $cz_pasta->ID !== $en_pasta->ID
	&& 'Špagety' === get_post_field( 'post_title', $cz_pasta->ID )
	&& 'Spaghetti' === get_post_field( 'post_title', $en_pasta->ID )
);

$cz_ingredient_terms = wp_get_post_terms( $cz_id, 'atlas_ingredient_tax', array( 'fields' => 'ids' ) );
$en_ingredient_terms = wp_get_post_terms( $en_id, 'atlas_ingredient_tax', array( 'fields' => 'ids' ) );
check(
	'27. recipe->ingredient tagging resolves the CORRECT locale-scoped dictionary entry per recipe (CZ recipe tags the CZ "pasta" post\'s term, EN recipe tags the EN one)',
	Atlas_Chuti_Country_Sync::class && ! empty( $cz_ingredient_terms ) && ! empty( $en_ingredient_terms )
	&& (int) get_post_meta( $cz_pasta->ID, '_atlas_ingredient_term_id', true ) !== 0
	&& in_array( (int) get_post_meta( $cz_pasta->ID, '_atlas_ingredient_term_id', true ), $cz_ingredient_terms, true )
	&& in_array( (int) get_post_meta( $en_pasta->ID, '_atlas_ingredient_term_id', true ), $en_ingredient_terms, true )
);

echo "\n=== Group 8: Kulinářský pas / fallback language-independence (3) ===\n";

check(
	'28. the CZ and EN posts of the same dish expose the SAME atlas_recipe_key — what single-atlas_recipe.php feeds the Kulinářský pas as its stable identity, so switching locale never loses a saved recipe',
	get_post_meta( $cz_id, 'atlas_recipe_key', true ) === get_post_meta( $en_id, 'atlas_recipe_key', true )
);

// atlas_chuti_placeholder_image() takes only a $context string (e.g. "recipe") and
// never a locale — it cannot branch by language by construction. Proven here by
// calling it under two different simulated current-locale states and confirming an
// identical result (fallback images key off context/continent identity, not
// localized title or current locale — see functions.php / inc/fallback-images.php).
$PLL['current'] = 'cs';
$fallback_cs = atlas_chuti_placeholder_image( 'recipe' );
$PLL['current'] = 'en';
$fallback_en = atlas_chuti_placeholder_image( 'recipe' );
$PLL['current'] = 'cs';
check( '29. fallback image resolution is identical regardless of current locale (no locale parameter exists to vary it)', $fallback_cs === $fallback_en );

check(
	'30. unit canonical KEY is locale-independent (only the LABEL differs) — servings/unit-conversion logic never has to branch by language',
	array_key_exists( 'pcs', Atlas_Chuti_Units::canonical_units() )
	&& Atlas_Chuti_Units::label( 'pcs', 'cs-CZ' ) === 'ks'
	&& Atlas_Chuti_Units::label( 'pcs', 'en' ) === 'pcs'
);

echo "\n--- $TOTAL checks, $FAIL failing ---\n";
exit( $FAIL > 0 ? 1 : 0 );
