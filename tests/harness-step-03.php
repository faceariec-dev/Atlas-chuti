<?php
/**
 * KROK 3 test harness — deterministic, no external dependencies, no live WP/DB.
 *
 * Stubs just enough of the WordPress API (posts/meta/terms/term-meta/hooks) to run
 * the REAL, unmodified plugin classes (Atlas_Chuti_I18N, Atlas_Chuti_Taxonomies,
 * Atlas_Chuti_Country_Sync, Atlas_Chuti_Ingredient_Sync, Atlas_Chuti_Meta_Fields,
 * Atlas_Chuti_JSON_Importer) against an in-memory fake database, and asserts the 21
 * scenarios docs/implementation-reports/step-03-recipe-data-model-importer.md
 * section I requires. Run: `php tests/harness-step-03.php`.
 *
 * This is intentionally NOT a WordPress installation — no true `wp_insert_post()`
 * semantics beyond what the importer itself actually relies on (see the docblocks
 * below each stub for exactly what's approximated and why it's safe to approximate
 * here). It exists because no live WP/DB is available in this environment; it is
 * the "maximum static checking" the KROK 3 brief asks for in that situation, not a
 * replacement for a real staging-site dry-run before the production batch is ever
 * actually imported.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', '/tmp/' );

$PLUGIN = dirname( __DIR__ ) . '/wp-content/plugins/atlas-chuti-core/includes';

// =============================================================================
// Fake database
// =============================================================================
$DB = array(
	'next_post_id'       => 1,
	'posts'              => array(),   // id => [ID,post_type,post_title,post_name,post_status,post_author,post_modified]
	'postmeta'           => array(),   // id => [key => value]
	'next_term_id'        => 1,
	'terms'              => array(),   // taxonomy => [term_id => [term_id,slug,name]]
	'termmeta'           => array(),   // term_id => [key => value]
	'term_relationships' => array(),   // post_id => [taxonomy => [term_id,...]]
);
$OPTIONS = array();
$HOOKS   = array();
$NOW     = 'T0';

function db_post_to_object( $p ) { return (object) $p; }
function db_term_to_object( $t ) { return (object) $t; }

// =============================================================================
// Hooks (real dispatch, incl. per-callback accepted_args — needed because
// class-country-sync.php/class-ingredient-sync.php/class-i18n.php all rely on
// save_post_{post_type} and set_object_terms actually firing during wp_insert_post()/
// wp_set_post_terms(), exactly like the real importer depends on in production.)
// =============================================================================
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
function add_filter( $tag, $cb, $priority = 10, $accepted_args = 1 ) { /* no filter in this codebase's import path changes behavior; no-op */ }
function apply_filters( $tag, $value, ...$args ) { return $value; }

// =============================================================================
// WP_Error / is_wp_error
// =============================================================================
class WP_Error {
	public $code;
	public $msg;
	public function __construct( $code = '', $msg = '' ) { $this->code = $code; $this->msg = $msg; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

// =============================================================================
// Sanitizers (close approximations — test fixtures are ASCII so the accent-
// stripping edge cases real sanitize_title()/remove_accents() handle never matter
// here; only the shape — lowercase, hyphenated, no HTML — matters for these tests).
// =============================================================================
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
function remove_accents( $s ) { return (string) $s; } // fixtures are ASCII, see docblock above.
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
/**
 * resolve_reference()'s final slug-only fallback (no locale filter at this level —
 * the caller re-checks locale itself). "path" here is just the slug for every post
 * type this codebase uses it with (no hierarchical parent/child paths involved).
 */
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
function has_post_thumbnail( $id = null ) { return false; } // no attachments in this harness — out of scope for KROK 3.
function get_post_thumbnail_id( $id ) { return 0; }

/**
 * Minimal but faithful enough for every call site in class-json-importer.php /
 * class-i18n.php: post_type + post_status(in) + name(slug) + title(exact) +
 * post__not_in + meta_query (AND, compare='=' only — the only compare this codebase
 * ever uses) + tax_query (single clause, AND — ditto) + posts_per_page + fields=>ids.
 */
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
				$have      = $DB['term_relationships'][ $id ][ $clause['taxonomy'] ] ?? array();
				$wanted    = array_map( 'intval', (array) $clause['terms'] );
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
	// Real wp_insert_term() ALWAYS runs the slug (explicit or derived) through
	// sanitize_title() before storing — e.g. "one_pot" is stored as "one-pot" either
	// way. Skipping that here for an explicit $args['slug'] would silently diverge
	// from real WordPress and mask a real underscore-vs-hyphen mismatch.
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

/**
 * Only reached by class-ingredient-sync.php's alias/keyword LIKE-search fallback,
 * which every fixture in this harness avoids (every ingredient row carries a real
 * ingredient_key, exactly like the actual production batch — see the read-only
 * batch audit in the report, section G: 880/880 ingredient rows already have one).
 */
class Fake_WPDB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public function esc_like( $s ) { return $s; }
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_col( $sql ) { return array(); }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

// =============================================================================
// Load the REAL plugin classes (unmodified requires — this is the actual code
// under test, not a reimplementation of it).
// =============================================================================
require $PLUGIN . '/class-i18n.php';
require $PLUGIN . '/class-taxonomy-labels.php';
require $PLUGIN . '/class-units.php';
require $PLUGIN . '/class-country-sync.php';
require $PLUGIN . '/class-ingredient-sync.php';
require $PLUGIN . '/class-taxonomies.php';
require $PLUGIN . '/class-meta-fields.php';
require $PLUGIN . '/class-json-importer.php';

// Same bootstrap order as atlas-chuti-core.php's real 'plugins_loaded'/'init' path.
Atlas_Chuti_I18N::instance();
Atlas_Chuti_Country_Sync::instance();
Atlas_Chuti_Ingredient_Sync::instance();
Atlas_Chuti_Taxonomies::instance()->register(); // registers taxonomies + seeds the controlled catalogs
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
function find_recipe_id_by_slug( $slug ) {
	global $DB;
	foreach ( $DB['posts'] as $id => $p ) {
		if ( 'atlas_recipe' === $p['post_type'] && $p['post_name'] === $slug ) {
			return $id;
		}
	}
	return 0;
}

// =============================================================================
// Fixture data
// =============================================================================
$country_it = array(
	'title'    => 'Italy',
	'iso_code' => 'IT',
	'flag_emoji' => '🇮🇹',
	'continent' => 'europe',
	'intro'    => 'Fixture country.',
);

$base_recipe = array(
	'title'             => 'Fixture Pasta',
	'slug'              => 'fixture-pasta',
	'recipe_key'        => 'fixture_pasta',
	'translation_group' => 'fixture_pasta',
	'country'           => 'IT',
	'excerpt'           => 'A short fixture perex used only by the KROK 3 test harness, not real editorial content.',
	'servings_default'  => 4,
	'prep_minutes'      => 10,
	'cook_minutes'      => 15,
	'difficulty'        => 'easy',
	'meal_type'         => array( 'main-course' ),
	'about'             => 'Fixture about text.',
	'ingredients'       => array(
		array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '400', 'unit' => 'g' ),
		array( 'ingredient_key' => 'tomato', 'display_name' => 'Tomato', 'quantity' => '2', 'unit' => 'ks' ),
	),
	'steps'             => array(
		array( 'order' => 1, 'text' => 'Boil the pasta.' ),
		array( 'order' => 2, 'text' => 'Add the sauce.' ),
	),
	'tags'              => array( 'traditional', 'one_pot' ),
);

echo "=== 1. Import základ ===\n";

// 1. new recipe -> "bude vytvořeno"
$country_report = $importer->run_import_sync( array( 'countries' => array( $country_it ) ), false );
if ( getenv( 'HARNESS_DEBUG' ) ) { var_dump( $country_report ); }
$report = $importer->run_import_sync( array( 'recipes' => array( $base_recipe ) ), false );
if ( getenv( 'HARNESS_DEBUG' ) ) { var_dump( $report ); }
$row    = row_by_title( $report, 'recipes', 'Fixture Pasta' );
check( '1. new recipe -> vytvořeno', $row && 'vytvořeno' === $row['status'] );

$recipe_id = find_recipe_id_by_slug( 'fixture-pasta' );
check( '1b. recipe actually persisted', $recipe_id > 0 );
$modified_after_create = $DB['posts'][ $recipe_id ]['post_modified'] ?? null;
if ( getenv( 'HARNESS_DEBUG' ) ) {
	echo "DEBUG recipe_id=$recipe_id\n";
	var_dump( $DB['postmeta'][ $recipe_id ] ?? null );
	var_dump( Atlas_Chuti_I18N::find_by_translation_group( 'atlas_recipe', 'fixture-pasta', 'cs-CZ' ) );
}

// 2. same recipe again -> "beze změny", no post_modified change
$NOW    = 'T1';
$report = $importer->run_import_sync( array( 'recipes' => array( $base_recipe ) ), false );
if ( getenv( 'HARNESS_DEBUG' ) ) { var_dump( $report ); }
$row    = row_by_title( $report, 'recipes', 'Fixture Pasta' );
check( '2. identical reimport -> beze změny', $row && 'beze změny' === $row['status'] );
check( '16. identical reimport does not change post_modified', $DB['posts'][ $recipe_id ]['post_modified'] === $modified_after_create );

$terms_before_reimport = $DB['term_relationships'][ $recipe_id ]['atlas_recipe_tag'] ?? array();
check( '17. identical reimport does not rewrite taxonomy relationships (same term IDs, same count)', $terms_before_reimport === ( $DB['term_relationships'][ $recipe_id ]['atlas_recipe_tag'] ?? array() ) );

// 3. change only the perex -> "bude aktualizováno"
$NOW           = 'T2';
$changed_perex = $base_recipe;
$changed_perex['excerpt'] = 'A different short fixture perex, still not real content, just changed for the test.';
$report = $importer->run_import_sync( array( 'recipes' => array( $changed_perex ) ), false );
$row    = row_by_title( $report, 'recipes', 'Fixture Pasta' );
check( '3. perex-only change -> aktualizováno', $row && 'aktualizováno' === $row['status'] );
check( '3b. excerpt meta actually updated', $DB['postmeta'][ $recipe_id ]['atlas_excerpt'] === $changed_perex['excerpt'] );

// 4. change only "about" -> "bude aktualizováno"
$NOW          = 'T3';
$changed_about = $changed_perex;
$changed_about['about'] = 'A different fixture about text.';
$report = $importer->run_import_sync( array( 'recipes' => array( $changed_about ) ), false );
$row    = row_by_title( $report, 'recipes', 'Fixture Pasta' );
check( '4. about-only change -> aktualizováno', $row && 'aktualizováno' === $row['status'] );

// 5. change title, same translation_group/recipe_key -> update, not a new recipe
$NOW            = 'T4';
$renamed        = $changed_about;
$renamed['title'] = 'Fixture Pasta (renamed)';
$before_count   = count( array_filter( $DB['posts'], fn( $p ) => 'atlas_recipe' === $p['post_type'] ) );
$report         = $importer->run_import_sync( array( 'recipes' => array( $renamed ) ), false );
$after_count    = count( array_filter( $DB['posts'], fn( $p ) => 'atlas_recipe' === $p['post_type'] ) );
$row            = row_by_title( $report, 'recipes', 'Fixture Pasta (renamed)' );
check( '5. rename with same recipe_key -> aktualizováno (not created)', $row && 'aktualizováno' === $row['status'] );
check( '5b. rename does not create a second recipe post', $after_count === $before_count );
check( '5c. same underlying post ID as before the rename', get_post_field( 'post_title', $recipe_id ) === 'Fixture Pasta (renamed)' );

echo "\n=== 2. Tagy ===\n";

// 6. valid controlled tag keys -> OK (already proven above, re-confirm term assignment).
// The fixture deliberately spells this one "one_pot" (underscore) while the real
// catalog key is "one-pot" (hyphen) — sanitize_title() normalizes both to the same
// slug, exactly like it would for a hand-typed JSON file that used either spelling.
$tag_ids = $DB['term_relationships'][ $recipe_id ]['atlas_recipe_tag'] ?? array();
$tag_slugs = array_map( fn( $tid ) => $DB['terms']['atlas_recipe_tag'][ $tid ]['slug'] ?? null, $tag_ids );
sort( $tag_slugs );
check( '6. valid controlled tags resolved and assigned (underscore input normalized to the real hyphenated key)', $tag_slugs === array( 'one-pot', 'traditional' ) );

// 7. unknown tag key -> hard error
$NOW         = 'T5';
$bad_tag     = $renamed;
$bad_tag['tags'] = array( 'traditional', 'definitely_not_a_real_tag' );
$report      = $importer->run_import_sync( array( 'recipes' => array( $bad_tag ) ), false );
$row         = row_by_title( $report, 'recipes', 'Fixture Pasta (renamed)' );
check( '7. unknown tag key -> chyba (hard error)', $row && 'chyba' === $row['status'] );
check( '7b. unknown tag never silently creates a new atlas_recipe_tag term', ! get_term_by( 'slug', 'definitely-not-a-real-tag', 'atlas_recipe_tag' ) );
// The failed import must not have touched the recipe's real tags.
$tag_ids_after_failed = $DB['term_relationships'][ $recipe_id ]['atlas_recipe_tag'] ?? array();
check( '7c. failed import left existing tags untouched', $tag_ids_after_failed === $tag_ids );

// 8. duplicate tag in input -> normalized, no duplicate relationship
$NOW        = 'T6';
$dup_tags   = $renamed;
$dup_tags['tags'] = array( 'traditional', 'traditional', 'one_pot' );
$report     = $importer->run_import_sync( array( 'recipes' => array( $dup_tags ) ), false );
$ids_now    = $DB['term_relationships'][ $recipe_id ]['atlas_recipe_tag'] ?? array();
check( '8. duplicate tag key normalized (no duplicate term IDs stored)', count( $ids_now ) === count( array_unique( $ids_now ) ) && 2 === count( $ids_now ) );

// 9. same tags, different order -> beze změny
$NOW            = 'T7';
$reordered_tags = $dup_tags;
$reordered_tags['tags'] = array( 'one_pot', 'traditional' );
$mod_before     = $DB['posts'][ $recipe_id ]['post_modified'];
$report         = $importer->run_import_sync( array( 'recipes' => array( $reordered_tags ) ), false );
$row            = row_by_title( $report, 'recipes', 'Fixture Pasta (renamed)' );
check( '9. same tags, different order -> beze změny', $row && 'beze změny' === $row['status'] );
check( '9b. no write happened (post_modified unchanged)', $DB['posts'][ $recipe_id ]['post_modified'] === $mod_before );

// 10. removing a tag -> update
$NOW          = 'T8';
$fewer_tags   = $reordered_tags;
$fewer_tags['tags'] = array( 'traditional' );
$report       = $importer->run_import_sync( array( 'recipes' => array( $fewer_tags ) ), false );
$row          = row_by_title( $report, 'recipes', 'Fixture Pasta (renamed)' );
$ids_now      = $DB['term_relationships'][ $recipe_id ]['atlas_recipe_tag'] ?? array();
check( '10. removing a tag -> aktualizováno', $row && 'aktualizováno' === $row['status'] );
check( '10b. tag actually removed', 1 === count( $ids_now ) );

// 11. adding a tag -> update
$NOW         = 'T9';
$more_tags   = $fewer_tags;
$more_tags['tags'] = array( 'traditional', 'family' );
$report      = $importer->run_import_sync( array( 'recipes' => array( $more_tags ) ), false );
$row         = row_by_title( $report, 'recipes', 'Fixture Pasta (renamed)' );
$ids_now     = $DB['term_relationships'][ $recipe_id ]['atlas_recipe_tag'] ?? array();
check( '11. adding a tag -> aktualizováno', $row && 'aktualizováno' === $row['status'] );
check( '11b. tag actually added', 2 === count( $ids_now ) );

// explicit "tags": [] must clear all tags (item 12 of the brief).
$NOW          = 'T10';
$no_tags      = $more_tags;
$no_tags['tags'] = array();
$report       = $importer->run_import_sync( array( 'recipes' => array( $no_tags ) ), false );
$row          = row_by_title( $report, 'recipes', 'Fixture Pasta (renamed)' );
$ids_now      = $DB['term_relationships'][ $recipe_id ]['atlas_recipe_tag'] ?? array();
check( 'extra: explicit empty tags array clears all tags and is a real update', $row && 'aktualizováno' === $row['status'] && array() === $ids_now );

echo "\n=== 3. Relationships (same-batch dependency resolution) ===\n";

// 12. same-batch ingredient recognized
$new_ingredient_recipe = array(
	'title'             => 'Fixture Salad',
	'slug'              => 'fixture-salad',
	'recipe_key'        => 'fixture_salad',
	'country'           => 'IT',
	'excerpt'           => 'Another short fixture perex, not real content, only for the test harness.',
	'servings_default'  => 2,
	'prep_minutes'      => 5,
	'ingredients'       => array(
		array( 'ingredient_key' => 'brand-new-ingredient', 'display_name' => 'Brand New Ingredient', 'quantity' => '1', 'unit' => 'ks' ),
	),
	'steps'             => array( array( 'order' => 1, 'text' => 'Mix.' ) ),
);
$NOW    = 'T11';
$report = $importer->run_import_sync( array( 'recipes' => array( $new_ingredient_recipe ) ), false );
$row    = row_by_title( $report, 'recipes', 'Fixture Salad' );
check( '12. recipe referencing a brand-new ingredient_key still imports (auto-created dictionary entry)', $row && 'vytvořeno' === $row['status'] );
check( '12b. the ingredient dictionary entry now really exists', (bool) Atlas_Chuti_I18N::find_ingredient_by_key( 'brand-new-ingredient', 'cs-CZ' ) );

// 13. unknown/unresolvable ingredient — the importer's own design (item 12, "variant
// A") is to auto-create any ingredient_key+display_name pair rather than error, so
// this scenario is really "an ingredient row with NO key and NO resolvable name" —
// confirmed as a WARNING (not a hard error) per warnings_for_recipe_refs(), matching
// the deliberate asymmetry with tags documented in the report (section E).
$unresolvable = array(
	'title'             => 'Fixture Soup',
	'slug'              => 'fixture-soup',
	'recipe_key'        => 'fixture_soup',
	'country'           => 'IT',
	'excerpt'          => 'Yet another short fixture perex for the test harness only.',
	'servings_default' => 2,
	'prep_minutes'     => 5,
	'ingredients'      => array( array( 'display_name' => 'Mystery Item', 'quantity' => '1' ) ),
	'steps'            => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$NOW    = 'T12';
$report = $importer->run_import_sync( array( 'recipes' => array( $unresolvable ) ), false );
$row    = row_by_title( $report, 'recipes', 'Fixture Soup' );
check( '13. ingredient with no key is a warning, recipe still imports', $row && 'vytvořeno' === $row['status'] && false !== strpos( $row['message'], 'ingredient_key' ) );

// 14. valid country ISO
check( '14. valid country ISO resolves', (bool) Atlas_Chuti_I18N::find_country_by_iso( 'IT', 'cs-CZ' ) );

// 15. invalid country ISO -> hard error
$bad_country_recipe = $base_recipe;
$bad_country_recipe['slug']  = 'fixture-bad-country';
$bad_country_recipe['title'] = 'Fixture Bad Country';
$bad_country_recipe['country'] = 'ZZ';
$NOW    = 'T13';
$report = $importer->run_import_sync( array( 'recipes' => array( $bad_country_recipe ) ), false );
$row    = row_by_title( $report, 'recipes', 'Fixture Bad Country' );
check( '15. unresolvable country ISO -> chyba', $row && 'chyba' === $row['status'] );

echo "\n=== 4. Idempotence (duplicates) ===\n";
check( '18. identical reimport does not rewrite unrelated metadata (about unchanged since step 4)', $DB['postmeta'][ $recipe_id ]['atlas_about'] === $changed_about['about'] );
check( '19. identical reimport never created duplicate recipe posts (still exactly one "fixture-pasta"/"Fixture Pasta (renamed)" post)', 1 === count( array_filter( $DB['posts'], fn( $p ) => 'atlas_recipe' === $p['post_type'] && 'fixture-pasta' === $p['post_name'] ) ) );

echo "\n=== 5. Legacy compatibility ===\n";

// 20. KROK 3B — "legacy" shape (only the OLD translation_group field name, no
// recipe_key at all) is now a HARD ERROR for a NEW import, full stop — this is
// TEST B from the KROK 3B fix brief ("missing recipe_key + VALID translation_group
// -> ERROR"), the key regression test that closes the loophole the previous fix
// left open. Legacy-data backward compatibility (section 2 of that brief) applies
// only to READING already-existing DB posts/frontend display — never to what a NEW
// JSON import is allowed to skip. No post may be created for this fixture.
$legacy = json_decode( file_get_contents( __DIR__ . '/fixtures/step-03-legacy-recipe.json' ), true );
$NOW    = 'T14';
$recipe_posts_before_legacy = count( array_filter( $DB['posts'], fn( $p ) => 'atlas_recipe' === $p['post_type'] ) );
$report = $importer->run_import_sync( $legacy, false );
$row    = row_by_title( $report, 'recipes', 'Legacy Bread' );
check( '20. translation_group-only "legacy" shape -> chyba (recipe_key is never substituted)', $row && 'chyba' === $row['status'] );
check( '20b. the error message names recipe_key, not translation_group, as the actual problem', false !== strpos( $row['message'], 'recipe_key' ) );
// The fixture's own "Legacyland" country IS still valid and legitimately gets
// created here (this fixture's job is only to test the RECIPE's recipe_key
// rejection) — so this checks the recipe post count specifically, not all posts.
check( '20c. no recipe post was created for the rejected legacy-shaped item', count( array_filter( $DB['posts'], fn( $p ) => 'atlas_recipe' === $p['post_type'] ) ) === $recipe_posts_before_legacy );
check( '20d. and no recipe with this slug exists', 0 === find_recipe_id_by_slug( 'legacy-bread' ) );

// 21. read-only validation of a larger payload never mutates the DB (dry-run)
$post_count_before = count( $DB['posts'] );
$importer->run_import_sync( array( 'recipes' => array( $base_recipe ) ), true ); // dry-run
$post_count_after = count( $DB['posts'] );
check( '21. dry-run never writes (post count unchanged)', $post_count_before === $post_count_after );

echo "\n=== 6. recipe_key hardening (Step 3 fix) ===\n";

// TEST 1 — missing recipe_key (and no translation_group fallback either) -> ERROR.
$no_key = array(
	'title'            => 'No Key Recipe',
	'slug'             => 'no-key-recipe',
	'country'          => 'IT',
	'excerpt'          => 'A fixture recipe with no recipe_key and no translation_group at all.',
	'servings_default' => 2,
	'prep_minutes'     => 5,
	'ingredients'      => array( array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '100', 'unit' => 'g' ) ),
	'steps'            => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$NOW              = 'T17';
$posts_before     = count( $DB['posts'] );
$report           = $importer->run_import_sync( array( 'recipes' => array( $no_key ) ), false );
$row              = row_by_title( $report, 'recipes', 'No Key Recipe' );
check( 'TEST 1 — missing recipe_key (no fallback) -> chyba', $row && 'chyba' === $row['status'] );
check( 'TEST 1b — no post was created for the rejected item', count( $DB['posts'] ) === $posts_before );

// TEST 2 — empty / whitespace-only recipe_key -> ERROR.
foreach ( array( '', '   ' ) as $i => $blank ) {
	$blank_key            = $no_key;
	$blank_key['title']   = 'Blank Key Recipe ' . $i;
	$blank_key['slug']    = 'blank-key-recipe-' . $i;
	$blank_key['recipe_key'] = $blank;
	$NOW    = 'T18';
	$report = $importer->run_import_sync( array( 'recipes' => array( $blank_key ) ), false );
	$row    = row_by_title( $report, 'recipes', 'Blank Key Recipe ' . $i );
	check( "TEST 2 — recipe_key " . ( '' === $blank ? '\"\"' : 'whitespace-only' ) . ' -> chyba', $row && 'chyba' === $row['status'] );
}

// TEST 3 — invalid recipe_key format -> ERROR (several bad shapes).
foreach ( array( 'Spaghetti Carbonara', 'foo bar', 'foo--bar', '-leading', 'trailing-', 'ÚPLNĚ ŠPATNĚ' ) as $i => $bad_format ) {
	$bad_key              = $no_key;
	$bad_key['title']     = 'Bad Format Recipe ' . $i;
	$bad_key['slug']      = 'bad-format-recipe-' . $i;
	$bad_key['recipe_key'] = $bad_format;
	$NOW    = 'T19';
	$report = $importer->run_import_sync( array( 'recipes' => array( $bad_key ) ), false );
	$row    = row_by_title( $report, 'recipes', 'Bad Format Recipe ' . $i );
	check( "TEST 3 — invalid recipe_key format \"$bad_format\" -> chyba", $row && 'chyba' === $row['status'] );
}

// TEST 4 — duplicate recipe_key within the SAME batch -> ERROR for both.
$dup_a = array(
	'title'             => 'Duplicate A',
	'slug'              => 'duplicate-a',
	'recipe_key'        => 'dup-key-test',
	'country'           => 'IT',
	'excerpt'           => 'First of two fixture recipes sharing one recipe_key on purpose.',
	'servings_default'  => 2,
	'prep_minutes'      => 5,
	'ingredients'       => array( array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '100', 'unit' => 'g' ) ),
	'steps'             => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$dup_b = $dup_a;
$dup_b['title'] = 'Duplicate B';
$dup_b['slug']  = 'duplicate-b';
$NOW    = 'T20';
$report = $importer->run_import_sync( array( 'recipes' => array( $dup_a, $dup_b ) ), false );
$row_a  = row_by_title( $report, 'recipes', 'Duplicate A' );
$row_b  = row_by_title( $report, 'recipes', 'Duplicate B' );
check( 'TEST 4 — duplicate recipe_key in same batch -> chyba for BOTH items', $row_a && 'chyba' === $row_a['status'] && $row_b && 'chyba' === $row_b['status'] );
check( 'TEST 4b — neither duplicate was actually created', 0 === find_recipe_id_by_slug( 'duplicate-a' ) && 0 === find_recipe_id_by_slug( 'duplicate-b' ) );

// Sanity: the SAME recipe_key across two DIFFERENT locales is NOT a duplicate — the
// multilingual-test-dataset.json sample file in this repo relies on exactly this
// (same recipe_key, cs-CZ + en) and must keep working once Krok 4 lands.
$dup_diff_locale        = $dup_a;
$dup_diff_locale['title'] = 'Duplicate Diff Locale';
$dup_diff_locale['slug']  = 'duplicate-diff-locale';
$dup_diff_locale['locale'] = 'en';
$NOW    = 'T21';
$report = $importer->run_import_sync( array( 'recipes' => array( $dup_a, $dup_diff_locale ) ), false );
$row_en = row_by_title( $report, 'recipes', 'Duplicate Diff Locale' );
// "IT" only exists as a cs-CZ post in this harness, so the "en" item correctly still
// fails — on an UNRELATED, already-covered rule (country must be resolvable in the
// item's own locale, TEST 15/item 2 of the original KROK 3 brief) — but the actual
// thing TEST 4c checks is that it's never rejected for the WRONG reason: the
// duplicate-recipe_key check must never fire across two different locales.
check( 'TEST 4c — same recipe_key across two different locales is NOT flagged as a duplicate', $row_en && false === strpos( $row_en['message'], 'opakuje' ) );

// TEST 5 — change title, same recipe_key -> UPDATE, never a new recipe.
$rk_original = array(
	'title'             => 'Spaghetti Carbonara',
	'slug'              => 'spaghetti-carbonara-rk',
	'recipe_key'        => 'spaghetti_carbonara',
	'country'           => 'IT',
	'excerpt'           => 'A fixture recipe used only to test the recipe_key identity rules.',
	'servings_default'  => 4,
	'prep_minutes'      => 10,
	'ingredients'       => array( array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '400', 'unit' => 'g' ) ),
	'steps'             => array( array( 'order' => 1, 'text' => 'Boil.' ) ),
);
$NOW          = 'T22';
$importer->run_import_sync( array( 'recipes' => array( $rk_original ) ), false );
$rk_id        = find_recipe_id_by_slug( 'spaghetti-carbonara-rk' );
$posts_before = count( array_filter( $DB['posts'], fn( $p ) => 'atlas_recipe' === $p['post_type'] ) );

$rk_renamed          = $rk_original;
$rk_renamed['title'] = 'Pravá římská Carbonara';
$NOW    = 'T23';
$report = $importer->run_import_sync( array( 'recipes' => array( $rk_renamed ) ), false );
$row    = row_by_title( $report, 'recipes', 'Pravá římská Carbonara' );
$posts_after = count( array_filter( $DB['posts'], fn( $p ) => 'atlas_recipe' === $p['post_type'] ) );
check( 'TEST 5 — title change with same recipe_key -> aktualizováno', $row && 'aktualizováno' === $row['status'] );
check( 'TEST 5b — no new recipe post was created', $posts_after === $posts_before );
check( 'TEST 5c — same underlying post, new title', get_post_field( 'post_title', $rk_id ) === 'Pravá římská Carbonara' );

// TEST 6 — identical recipe + same recipe_key -> beze změny, post_modified untouched.
$NOW        = 'T24';
$mod_before = $DB['posts'][ $rk_id ]['post_modified'];
$report     = $importer->run_import_sync( array( 'recipes' => array( $rk_renamed ) ), false );
$row        = row_by_title( $report, 'recipes', 'Pravá římská Carbonara' );
check( 'TEST 6 — identical reimport with same recipe_key -> beze změny', $row && 'beze změny' === $row['status'] );
check( 'TEST 6b — post_modified unchanged', $DB['posts'][ $rk_id ]['post_modified'] === $mod_before );

// TEST 7 — valid recipe_key, but translation_group is missing -> WARNING, not error.
$rk_no_tgroup = array(
	'title'             => 'Recipe Key Only',
	'slug'              => 'recipe-key-only',
	'recipe_key'        => 'recipe_key_only_fixture',
	'country'           => 'IT',
	'excerpt'           => 'A fixture recipe with a valid recipe_key but no translation_group at all.',
	'servings_default'  => 2,
	'prep_minutes'      => 5,
	'ingredients'       => array( array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '100', 'unit' => 'g' ) ),
	'steps'             => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$NOW    = 'T25';
$report = $importer->run_import_sync( array( 'recipes' => array( $rk_no_tgroup ) ), false );
$row    = row_by_title( $report, 'recipes', 'Recipe Key Only' );
check( 'TEST 7 — valid recipe_key + missing translation_group -> vytvořeno (not chyba)', $row && 'vytvořeno' === $row['status'] );
check( 'TEST 7b — the missing translation_group is a WARNING message, not a blocking error', false !== strpos( $row['message'], 'translation_group' ) );
$rk_only_id = find_recipe_id_by_slug( 'recipe-key-only' );
check( 'TEST 7c — recipe_key itself resolved correctly into atlas_translation_group storage', 'recipe-key-only-fixture' === get_post_meta( $rk_only_id, 'atlas_translation_group', true ) );

echo "\n=== 7. KROK 3B — translation_group NEVER substitutes for recipe_key (fix brief scenarios A-H) ===\n";
// A and B are already covered above (TEST 1 = A: neither field present; TEST 20 =
// B: translation_group present and valid, recipe_key absent — both -> chyba). C and
// D close the remaining gap: recipe_key present but blank/invalid, WITH a validly
// filled translation_group right alongside it — translation_group must not rescue
// either case.
$c_base = array(
	'title'             => 'Scenario C Recipe',
	'slug'              => 'scenario-c-recipe',
	'recipe_key'        => '',
	'translation_group' => 'scenario_c_recipe',
	'country'           => 'IT',
	'excerpt'           => 'A fixture recipe with an empty recipe_key but a perfectly valid translation_group.',
	'servings_default'  => 2,
	'prep_minutes'      => 5,
	'ingredients'       => array( array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '100', 'unit' => 'g' ) ),
	'steps'             => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$NOW    = 'T26';
$posts_before_c = count( $DB['posts'] );
$report = $importer->run_import_sync( array( 'recipes' => array( $c_base ) ), false );
$row    = row_by_title( $report, 'recipes', 'Scenario C Recipe' );
check( 'TEST C — empty recipe_key + valid translation_group -> chyba (translation_group does not rescue it)', $row && 'chyba' === $row['status'] );
check( 'TEST C b — no post created', count( $DB['posts'] ) === $posts_before_c );

$d_base                      = $c_base;
$d_base['title']             = 'Scenario D Recipe';
$d_base['slug']               = 'scenario-d-recipe';
$d_base['recipe_key']         = 'Not A Valid Key!';
$d_base['translation_group']  = 'scenario_d_recipe';
$NOW    = 'T27';
$posts_before_d = count( $DB['posts'] );
$report = $importer->run_import_sync( array( 'recipes' => array( $d_base ) ), false );
$row    = row_by_title( $report, 'recipes', 'Scenario D Recipe' );
check( 'TEST D — invalid recipe_key format + valid translation_group -> chyba (translation_group does not rescue it)', $row && 'chyba' === $row['status'] );
check( 'TEST D b — no post created', count( $DB['posts'] ) === $posts_before_d );

// F — the "everything is fine" case: valid recipe_key AND valid translation_group
// together import cleanly, with no warning about either. $base_recipe (TEST 1/2 at
// the very top) already exercises exactly this on every call; this is a direct,
// explicit confirmation using a fixture built for this scenario alone.
$f_base = array(
	'title'             => 'Scenario F Recipe',
	'slug'              => 'scenario-f-recipe',
	'recipe_key'        => 'scenario_f_recipe',
	'translation_group' => 'scenario_f_recipe',
	'country'           => 'IT',
	'excerpt'           => 'A fixture recipe with both recipe_key and translation_group validly filled in.',
	'servings_default'  => 2,
	'prep_minutes'      => 5,
	'ingredients'       => array( array( 'ingredient_key' => 'pasta', 'display_name' => 'Pasta', 'quantity' => '100', 'unit' => 'g' ) ),
	'steps'             => array( array( 'order' => 1, 'text' => 'Cook.' ) ),
);
$NOW    = 'T28';
$report = $importer->run_import_sync( array( 'recipes' => array( $f_base ) ), false );
$row    = row_by_title( $report, 'recipes', 'Scenario F Recipe' );
check( 'TEST F — valid recipe_key + valid translation_group -> vytvořeno, no identity warning', $row && 'vytvořeno' === $row['status'] && false === strpos( $row['message'], 'translation_group' ) );

echo "\n=== Extra: quality warnings never affect unchanged-detection / status ===\n";
$NOW              = 'T15';
$short_perex_item = $renamed;
$short_perex_item['excerpt'] = 'Too short.';
$report = $importer->run_import_sync( array( 'recipes' => array( $short_perex_item ) ), false );
$row    = row_by_title( $report, 'recipes', 'Fixture Pasta (renamed)' );
check( 'quality warning (short perex) still allows a real update to go through with the warning attached', $row && 'aktualizováno' === $row['status'] && false !== strpos( $row['message'], 'slov' ) );

// Reimporting the exact same short-perex item must be "beze změny" WITH the warning
// still shown — not silently promoted to "aktualizováno" just because there's a
// warning (item 22 of the brief).
$NOW    = 'T16';
$mod_before = $DB['posts'][ $recipe_id ]['post_modified'];
$report = $importer->run_import_sync( array( 'recipes' => array( $short_perex_item ) ), false );
$row    = row_by_title( $report, 'recipes', 'Fixture Pasta (renamed)' );
check( 'a warning alone never forces "aktualizováno" on an otherwise-identical reimport', $row && 'beze změny' === $row['status'] && false !== strpos( $row['message'], 'slov' ) );
check( 'and no write actually happened', $DB['posts'][ $recipe_id ]['post_modified'] === $mod_before );

echo "\n--- $TOTAL checks, $FAIL failing ---\n";
exit( $FAIL > 0 ? 1 : 0 );
