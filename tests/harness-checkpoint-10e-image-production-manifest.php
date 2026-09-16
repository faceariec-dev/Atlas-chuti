<?php
/**
 * CHECKPOINT 10E test harness — validates the generated Europe-1 recipe
 * image PRODUCTION manifest (docs/image-production/europe-1-recipe-images.
 * {csv,json,md}), produced by tools/generate-image-production-manifest.php,
 * which itself delegates all pairing/status/dimension logic to the REAL,
 * unmodified Checkpoint 10C class (class-recipe-image-pipeline.php) — this
 * harness reads the deliverable files directly, it does not re-implement
 * any pairing/validation logic of its own.
 *
 * Covers the checkpoint's 18 numbered required-test scenarios (brief
 * section 16). Items 15-18 delegate to the existing regression suite as
 * separate shell invocations, same convention as every prior harness.
 *
 * Run: `php tests/harness-checkpoint-10e-image-production-manifest.php`
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$ROOT    = dirname( __DIR__ );
$OUT_DIR = $ROOT . '/docs/image-production';
$CSV     = $OUT_DIR . '/europe-1-recipe-images.csv';
$JSON    = $OUT_DIR . '/europe-1-recipe-images.json';
$MD      = $OUT_DIR . '/europe-1-recipe-images.md';

$RESULTS = array();
function check( $label, $condition ) {
	global $RESULTS;
	$RESULTS[] = array( 'label' => $label, 'pass' => (bool) $condition );
	printf( "%s — %s\n", $condition ? 'PASS' : 'FAIL', $label );
}

echo "=== CHECKPOINT 10E: recipe image production manifest ===\n\n";

if ( ! is_file( $CSV ) || ! is_file( $JSON ) || ! is_file( $MD ) ) {
	fwrite( STDERR, "FATAL: docs/image-production/ deliverables not found. Run tools/generate-image-production-manifest.php first.\n" );
	exit( 1 );
}

// Parse CSV.
$fh  = fopen( $CSV, 'r' );
$cols = fgetcsv( $fh );
$csv_rows = array();
while ( ( $row = fgetcsv( $fh ) ) !== false ) {
	$csv_rows[] = array_combine( $cols, $row );
}
fclose( $fh );

// Parse JSON.
$json_rows = json_decode( file_get_contents( $JSON ), true );

// -----------------------------------------------------------------------
// 1. 100 recipe concepts.
check( '1. manifest represents exactly 100 recipe concepts (JSON)', 100 === count( $json_rows ) );

// 2. 100 unique recipe_key.
$json_keys = array_column( $json_rows, 'recipe_key' );
check( '2. 100 unique recipe_key values in the JSON manifest', 100 === count( array_unique( $json_keys ) ) );

// 3. 100 unique expected filenames.
$filenames = array_column( $json_rows, 'filename' );
check( '3. 100 unique expected filenames', 100 === count( array_unique( $filenames ) ) );

// 4. CZ/EN variants map to the same image filename — cross-checked against
// the real production-data JSON: both locale recipe files share the exact
// same recipe_key set, and the manifest is built keyed by recipe_key alone
// (never per-locale), so filename is the same string regardless of which
// locale post is being rendered.
$cz_keys = array();
foreach ( glob( $ROOT . '/production-data/europe-1/0[3-7]-recipes-*.json' ) as $f ) {
	foreach ( json_decode( file_get_contents( $f ), true )['recipes'] as $r ) {
		$cz_keys[] = $r['recipe_key'];
	}
}
$en_keys = array();
foreach ( glob( $ROOT . '/production-data/europe-1-en/0[3-7]-recipes-*.json' ) as $f ) {
	foreach ( json_decode( file_get_contents( $f ), true )['recipes'] as $r ) {
		$en_keys[] = $r['recipe_key'];
	}
}
sort( $cz_keys );
sort( $en_keys );
$sorted_json_keys = $json_keys;
sort( $sorted_json_keys );
check(
	'4. CZ and EN recipe_key sets are identical to each other and to the manifest (one shared filename per concept, never per locale)',
	$cz_keys === $en_keys && $cz_keys === $sorted_json_keys
);

// 5. 100 non-empty alt_cs.
$empty_alt_cs = array_filter( $json_rows, fn( $r ) => '' === trim( (string) $r['alt_cs'] ) );
check( '5. 100/100 rows have a non-empty alt_cs', 0 === count( $empty_alt_cs ) );

// 6. 100 non-empty alt_en.
$empty_alt_en = array_filter( $json_rows, fn( $r ) => '' === trim( (string) $r['alt_en'] ) );
check( '6. 100/100 rows have a non-empty alt_en', 0 === count( $empty_alt_en ) );

// 7. No dimensions in ALT.
$dims_in_alt = array_filter(
	$json_rows,
	fn( $r ) => preg_match( '/\b1600\b|\b900\b|\d+\s*[x×]\s*\d+/i', $r['alt_cs'] . ' ' . $r['alt_en'] )
);
check( '7. no row has pixel dimensions leaked into alt_cs/alt_en', 0 === count( $dims_in_alt ) );

// 8. Required dimensions 1600x900 for all unless documented exception.
$wrong_dims = array_filter( $json_rows, fn( $r ) => 1600 !== (int) $r['required_width'] || 900 !== (int) $r['required_height'] );
check( '8. all 100 rows require 1600x900 source dimensions (no undocumented exception)', 0 === count( $wrong_dims ) );

// 9. CSV = 100 rows.
check( '9. CSV has exactly 100 data rows', 100 === count( $csv_rows ) );

// 10. JSON = 100 objects.
check( '10. JSON has exactly 100 objects', 100 === count( $json_rows ) );

// 11. CSV/JSON recipe_key sets identical.
$csv_keys_set  = array_unique( array_column( $csv_rows, 'recipe_key' ) );
$json_keys_set = array_unique( $json_keys );
sort( $csv_keys_set );
sort( $json_keys_set );
check( '11. CSV and JSON recipe_key sets are identical', $csv_keys_set === $json_keys_set );

// 12. Manifest deterministic: regenerate and diff byte-for-byte.
$before_csv  = file_get_contents( $CSV );
$before_json = file_get_contents( $JSON );
$gen_output  = shell_exec( 'php ' . escapeshellarg( $ROOT . '/tools/generate-image-production-manifest.php' ) . ' 2>&1' );
$after_csv   = file_get_contents( $CSV );
$after_json  = file_get_contents( $JSON );
check( '12. manifest generation is deterministic (byte-identical CSV+JSON across two runs)', $before_csv === $after_csv && $before_json === $after_json );

// 13. No fake image path/reference in production content.
$prod_diff = shell_exec( 'git -C ' . escapeshellarg( $ROOT ) . ' diff --stat -- production-data/ 2>/dev/null' );
check( '13. production-data/ has zero diff — no image path/reference was added to production content', '' === trim( (string) $prod_diff ) );

// 14. World Classics flags only from real config (filter is empty by
// design as of this checkpoint — see docs/implementation-reports/
// checkpoint-10d-en-localization.md section L — so every row must be false).
$true_world_classic = array_filter( $json_rows, fn( $r ) => true === $r['world_classic'] );
check( '14. world_classic is true only where the real (currently empty) filter names the recipe_key — 0/100 today', 0 === count( $true_world_classic ) );

// 15-18: delegated to the existing regression suite, run as separate shell
// invocations — same convention as every prior harness's own final checks.
function run_php( $rel ) {
	global $ROOT;
	exec( 'php ' . escapeshellarg( $ROOT . '/' . $rel ) . ' 2>&1', $out, $code );
	return 0 === $code;
}
check( '15. tests/harness-checkpoint-10c.php (media pipeline) passes', run_php( 'tests/harness-checkpoint-10c.php' ) );
check( '16. tests/harness-checkpoint-10c1-quality-gate.php passes', run_php( 'tests/harness-checkpoint-10c1-quality-gate.php' ) );
check(
	'17. Checkpoint 10D content remains unchanged (production-data/europe-1-en/ has zero diff)',
	'' === trim( (string) shell_exec( 'git -C ' . escapeshellarg( $ROOT ) . ' diff --stat -- production-data/europe-1-en/ 2>/dev/null' ) )
);
check( '18. tests/harness-step-09.php (Step 9 regression) passes', run_php( 'tests/harness-step-09.php' ) );

// =============================================================================
$total  = count( $RESULTS );
$failed = count( array_filter( $RESULTS, fn( $r ) => ! $r['pass'] ) );
printf( "\n--- %d checks, %d failing ---\n", $total, $failed );
exit( $failed > 0 ? 1 : 0 );
