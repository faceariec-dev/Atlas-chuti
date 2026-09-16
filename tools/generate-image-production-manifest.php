<?php
/**
 * CHECKPOINT 10E: generates the Europe-1 recipe image PRODUCTION manifest
 * (docs/image-production/europe-1-recipe-images.{csv,json,md}) — the
 * outward-facing, human/vendor-facing deliverable for commissioning and
 * QA'ing the 100 recipe photos, one per `recipe_key` concept (CZ+EN share
 * one physical image; see class-recipe-image-pipeline.php's own docblock).
 *
 * This is DELIBERATELY NOT a second, parallel pairing/validation engine.
 * All recipe_key/status/dimension logic is delegated to the REAL, unmodified
 * `Atlas_Chuti_Recipe_Image_Pipeline::build_manifest()` from Checkpoint 10C
 * — the same class `wp atlas image-manifest` calls. Since this repository
 * has no live WordPress install/database (no `wp` binary, no wp-config.php
 * — see Checkpoint 10E audit), and this checkpoint explicitly must NOT
 * import anything into a database, the WordPress functions the pipeline
 * calls are stubbed here against an in-memory post store built from the
 * REAL `production-data/europe-1{,-en}/` JSON — the exact same
 * stub-WP-against-real-class approach every prior checkpoint's test
 * harness already uses (see tests/harness-checkpoint-10c.php).
 *
 * This script only READS production-data/ and WRITES docs/image-production/
 * — it never touches production-data/, never imports/creates any image
 * file, and never writes to any WordPress database (there isn't one here).
 *
 * Run: `php tools/generate-image-production-manifest.php`
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-10e-fakeroot/' );

$ROOT       = dirname( __DIR__ );
$PLUGIN     = $ROOT . '/wp-content/plugins/atlas-chuti-core/includes';
$THEME_INC  = $ROOT . '/wp-content/themes/atlas-chuti/inc';
$OUT_DIR    = $ROOT . '/docs/image-production';
$PROD_CZ    = $ROOT . '/production-data/europe-1';
$PROD_EN    = $ROOT . '/production-data/europe-1-en';

// =============================================================================
// Minimal WP stub layer — an in-memory post store, not a database. Same
// contract as tests/harness-checkpoint-10c.php's stub (kept in sync
// deliberately: both exercise the exact same real pipeline class).
// =============================================================================
$FAKE_POSTS      = array(); // id => (object) [ 'ID', 'post_type', 'post_status', 'post_title' ]
$FAKE_POSTMETA    = array(); // id => [ key => value ]
$FAKE_THUMBNAILS  = array(); // id => attachment_id (none set — no images exist anywhere in this repo)

function add_post( $id, $type, $title, $meta = array() ) {
	global $FAKE_POSTS, $FAKE_POSTMETA;
	$FAKE_POSTS[ $id ]    = (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title );
	$FAKE_POSTMETA[ $id ] = $meta;
}
function get_posts( $args ) {
	global $FAKE_POSTS;
	$type = $args['post_type'] ?? null;
	$out  = array();
	foreach ( $FAKE_POSTS as $id => $post ) {
		if ( $type && $post->post_type !== $type ) {
			continue;
		}
		$out[] = ( 'ids' === ( $args['fields'] ?? '' ) ) ? $id : $post;
	}
	return $out;
}
function get_post_meta( $id, $key, $single = false ) {
	global $FAKE_POSTMETA;
	return $FAKE_POSTMETA[ $id ][ $key ] ?? '';
}
function has_post_thumbnail( $id ) {
	global $FAKE_THUMBNAILS;
	return ! empty( $FAKE_THUMBNAILS[ $id ] );
}
function get_the_title( $id ) {
	global $FAKE_POSTS;
	return $FAKE_POSTS[ $id ]->post_title ?? '';
}
function wp_list_pluck( $list, $field ) {
	return array_map( fn( $row ) => is_object( $row ) ? $row->$field : $row[ $field ], $list );
}
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}
function trailingslashit( $s ) {
	return rtrim( $s, '/' ) . '/';
}
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
function __( $s, $d = null ) { return $s; }
function apply_filters( $tag, $value, ...$args ) { return $value; } // no real filter registered anywhere in this repo for world_classics — confirmed by grep; default-empty passthrough is therefore the REAL behavior, not a stub shortcut.
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {} // homepage.php registers a render hook at file-scope; never fired here.
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {} // ditto for its seasonal-pick filter.

require_once $PLUGIN . '/class-i18n.php';
require_once $PLUGIN . '/class-recipe-image-pipeline.php';

// =============================================================================
// Fixture posts: built from the REAL production-data JSON, not invented.
// Two fake posts per recipe_key (cs-CZ + en), titled with the real, already
// human-authored CZ/EN titles. No thumbnail is ever set — there are zero
// image files anywhere in this repository (verified before writing this
// script), so every row's pipeline status is correctly 'missing'.
// =============================================================================
function load_recipes( $dir ) {
	$out = array();
	foreach ( glob( $dir . '/0[3-7]-recipes-*.json' ) as $file ) {
		$data = json_decode( file_get_contents( $file ), true );
		foreach ( $data['recipes'] as $r ) {
			$out[ $r['recipe_key'] ] = $r;
		}
	}
	ksort( $out );
	return $out;
}

$cz_recipes = load_recipes( $PROD_CZ );
$en_recipes = load_recipes( $PROD_EN );

if ( count( $cz_recipes ) !== 100 || count( $en_recipes ) !== 100 ) {
	fwrite( STDERR, sprintf( "FATAL: expected 100 CZ + 100 EN recipes, found %d CZ / %d EN.\n", count( $cz_recipes ), count( $en_recipes ) ) );
	exit( 1 );
}
if ( array_keys( $cz_recipes ) !== array_keys( $en_recipes ) ) {
	fwrite( STDERR, "FATAL: CZ and EN recipe_key sets differ.\n" );
	exit( 1 );
}

$next_id = 1;
$country_by_key = array();
foreach ( $cz_recipes as $recipe_key => $cz_r ) {
	$en_r = $en_recipes[ $recipe_key ];
	add_post( $next_id++, 'atlas_recipe', $cz_r['title'], array( 'atlas_recipe_key' => $recipe_key, 'atlas_locale' => 'cs-CZ' ) );
	add_post( $next_id++, 'atlas_recipe', $en_r['title'], array( 'atlas_recipe_key' => $recipe_key, 'atlas_locale' => 'en' ) );
	$country_by_key[ $recipe_key ] = $cz_r['country'];
}

// =============================================================================
// World Classics: only a REAL, currently-configured filter value counts —
// never guessed from a recipe's name or apparent international fame. Grepped
// this repo for any add_filter() populating
// 'atlas_chuti_world_classics_recipe_keys' before writing this script: there
// is none, so the filter's own documented empty-by-default (Checkpoint 10B)
// is the real, current value.
// =============================================================================
require_once $THEME_INC . '/homepage.php';
$world_classics_keys = array_flip( atlas_chuti_world_classics_recipe_keys() );

// =============================================================================
// Real, unmodified Checkpoint 10C pipeline call — no re-implementation of
// recipe_key parsing, pairing, or status logic.
// =============================================================================
$manifest = Atlas_Chuti_Recipe_Image_Pipeline::build_manifest( null );

if ( count( $manifest['rows'] ) !== 100 ) {
	fwrite( STDERR, sprintf( "FATAL: pipeline manifest has %d rows, expected 100.\n", count( $manifest['rows'] ) ) );
	exit( 1 );
}

// Pipeline status vocabulary ('present'/'missing'/'duplicate'/
// 'invalid_format'/'invalid_dimensions') mapped to this deliverable's own
// documented vocabulary (brief item 7: "status může být například
// TO_CREATE, READY, MISSING"). No row is ever marked READY without a real,
// validated file backing it — build_manifest() itself only ever returns
// 'present' when has_post_thumbnail() is true for every locale post, which
// never happens here (no thumbnails were ever set).
function map_status( $pipeline_status ) {
	switch ( $pipeline_status ) {
		case 'present':
			return 'READY';
		case 'missing':
			return 'TO_CREATE';
		default: // duplicate / invalid_format / invalid_dimensions
			return 'MISSING';
	}
}

// =============================================================================
// Build the production rows (brief section 7's exact column set).
// =============================================================================
$rows = array();
foreach ( $manifest['rows'] as $row ) {
	$recipe_key = $row['recipe_key'];
	$is_wc      = isset( $world_classics_keys[ $recipe_key ] );
	$rows[]     = array(
		'recipe_key'       => $recipe_key,
		'filename'         => $row['filename'],
		'title_cs'         => $row['title_cs'],
		'title_en'         => $row['title_en'],
		'alt_cs'           => $row['alt_cs'],
		'alt_en'           => $row['alt_en'],
		'required_width'   => $row['required_min_width'],
		'required_height'  => $row['required_min_height'],
		'country_iso'      => $country_by_key[ $recipe_key ],
		'status'           => map_status( $row['status'] ),
		'world_classic'    => $is_wc, // only ever true if the real filter (checked above) actually names this recipe_key — it never does today.
		'notes'            => 'present' === $row['status'] ? '' : 'No source photo yet — commission per docs/image-production/europe-1-recipe-images.md.',
	);
}

// Deterministic: build_manifest() already ksort()s by recipe_key; re-assert
// here so this script's own contract doesn't silently depend on that detail.
usort( $rows, fn( $a, $b ) => strcmp( $a['recipe_key'], $b['recipe_key'] ) );

// =============================================================================
// Write CSV
// =============================================================================
wp_mkdir_p( $OUT_DIR );
$columns = array( 'recipe_key', 'filename', 'title_cs', 'title_en', 'alt_cs', 'alt_en', 'required_width', 'required_height', 'country_iso', 'status', 'notes' );
$csv_path = $OUT_DIR . '/europe-1-recipe-images.csv';
$fh = fopen( $csv_path, 'w' );
fputcsv( $fh, $columns );
foreach ( $rows as $row ) {
	fputcsv( $fh, array_map( fn( $c ) => $row[ $c ] ?? '', $columns ) );
}
fclose( $fh );

// =============================================================================
// Write JSON (deterministic key order = $columns order, plus world_classic).
// =============================================================================
$json_rows = array();
foreach ( $rows as $row ) {
	$json_rows[] = array(
		'recipe_key'      => $row['recipe_key'],
		'filename'        => $row['filename'],
		'title_cs'        => $row['title_cs'],
		'title_en'        => $row['title_en'],
		'alt_cs'          => $row['alt_cs'],
		'alt_en'          => $row['alt_en'],
		'required_width'  => $row['required_width'],
		'required_height' => $row['required_height'],
		'country_iso'     => $row['country_iso'],
		'status'          => $row['status'],
		'world_classic'   => $row['world_classic'],
		'notes'           => $row['notes'],
	);
}
$json_path = $OUT_DIR . '/europe-1-recipe-images.json';
file_put_contents( $json_path, wp_json_encode( $json_rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );

// =============================================================================
// Write human checklist (brief section 9).
// =============================================================================
$to_create = count( array_filter( $rows, fn( $r ) => 'TO_CREATE' === $r['status'] ) );
$ready     = count( array_filter( $rows, fn( $r ) => 'READY' === $r['status'] ) );
$missing   = count( array_filter( $rows, fn( $r ) => 'MISSING' === $r['status'] ) );

$md = <<<MD
# Europe 1 — Recipe Image Production Checklist

Checkpoint 10E. One physical image per `recipe_key` **concept** — the CZ and
EN recipe posts of the same `recipe_key` share ONE file, never two. This
checklist governs the 100 recipe photos for `production-data/europe-1/` +
`production-data/europe-1-en/`.

Machine-readable companions: `europe-1-recipe-images.csv`,
`europe-1-recipe-images.json` (100 rows/objects, same `recipe_key` set).

## Current status

- 100 expected images
- TO_CREATE: {$to_create}
- READY: {$ready}
- MISSING (duplicate/invalid file already staged): {$missing}

No image file exists anywhere in this repository yet — every row is
`TO_CREATE` by default until a real, validated photo is staged and the
manifest is regenerated (`php tools/generate-image-production-manifest.php`).

## Naming convention

```
{recipe_key}.jpg
```

`recipe_key` is the SAME stable identity `class-json-importer.php` resolves
`related_recipes` by — never the localized title, never the slug, never file
order. One file serves both the `cs-CZ` and `en` post of that `recipe_key`.

## Source image rule

- Minimum source dimensions: **1600 × 900** (16:9).
- This covers the theme's real registered crops without upscaling:
  `atlas-hero` (1600×900, 16:9) and `atlas-card` (640×480, 4:3) — see
  `class-recipe-image-pipeline.php`'s own `REQUIRED_MIN_WIDTH`/
  `REQUIRED_MIN_HEIGHT` docblock (Checkpoint 10C, section 5 audit).
- A source narrower or shorter than 1600×900 is rejected by the importer as
  `invalid_dimensions` — commission photos at or above this size, never
  smaller.

## Allowed formats

- `.jpg` / `.jpeg`
- `.png`
- `.webp`

No SVG. Max 20 MB per source file.

## ALT policy

- `alt_cs` = the recipe's own localized CZ title (`title_cs` in the
  manifest) — never the file name, never a guessed phrase.
- `alt_en` = the recipe's own localized EN title (`title_en` in the
  manifest).
- Dimensions (e.g. "1600x900") must NEVER appear inside ALT text.
- The HTML `title=""` attribute is never used as an SEO/ALT field — ALT and
  `title=""` are two different things and only ALT carries accessibility/SEO
  meaning here.
- One shared image, two ALT values — resolved per-post at render time by
  `atlas_chuti_recipe_image_alt()` (`functions.php`), not by duplicating the
  attachment.

## ZIP naming and contents

Future delivery ZIP: **`europe-1-recipe-images.zip`**

Flat archive, source images only:

```
svickova.jpg
vepro-knedlo-zelo.jpg
...
```

Do NOT include inside the ZIP:
- the CSV or JSON manifest
- a README
- executables of any kind
- thumbnails / resized copies
- a duplicate CZ vs. EN copy of the same photo (one file per `recipe_key`,
  always)

## Verified workflow (Checkpoint 10C tooling — reused, not duplicated)

1. Regenerate this manifest after any content change:
   ```
   php tools/generate-image-production-manifest.php
   ```
2. Once `europe-1-recipe-images.zip` exists, stage it and dry-run first —
   this writes nothing to the Media Library or to any post:
   ```
   wp atlas image-import europe-1-recipe-images.zip --dry-run
   ```
3. Review the dry-run output: `missing_recipe`, `invalid_dimensions`,
   `invalid_format`, `duplicates`, `unmatched_files` must all be fixed
   (re-export/re-crop/rename) before a real import.
4. Only once the dry-run is fully clean, run the real import:
   ```
   wp atlas image-import europe-1-recipe-images.zip
   ```
5. To overwrite a recipe's existing featured image on a later batch, pass
   `--replace` explicitly — never implicit:
   ```
   wp atlas image-import europe-1-recipe-images.zip --replace
   ```

No import of any kind was run as part of Checkpoint 10E — this checklist
only documents the commands for the future asset batch.
MD;

file_put_contents( $OUT_DIR . '/europe-1-recipe-images.md', $md . "\n" );

fwrite( STDOUT, sprintf( "Wrote %d CSV rows, %d JSON objects, and the MD checklist to %s\n", count( $rows ), count( $json_rows ), $OUT_DIR ) );
fwrite( STDOUT, sprintf( "Status breakdown: TO_CREATE=%d READY=%d MISSING=%d\n", $to_create, $ready, $missing ) );
