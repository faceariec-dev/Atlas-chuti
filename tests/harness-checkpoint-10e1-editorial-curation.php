<?php
/**
 * CHECKPOINT 10E.1 test harness — the real, reviewed World Classics /
 * Světová klasika editorial curation (inc/editorial-curation.php) and the
 * now-versioned, curation-aware image production manifest.
 *
 * Covers the checkpoint's 21 numbered required-test scenarios (brief
 * section 11). Items 17-21 delegate to the existing regression suite as
 * separate shell invocations, same convention as every prior harness.
 *
 * Run: `php tests/harness-checkpoint-10e1-editorial-curation.php`
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-10e1-fakeroot/' );
}

$ROOT      = dirname( __DIR__ );
$THEME     = $ROOT . '/wp-content/themes/atlas-chuti';
$FRONTPAGE = file_get_contents( $THEME . '/front-page.php' );
$OUT_DIR   = $ROOT . '/docs/image-production';

$RESULTS = array();
function check( $label, $condition ) {
	global $RESULTS;
	$RESULTS[] = array( 'label' => $label, 'pass' => (bool) $condition );
	printf( "%s — %s\n", $condition ? 'PASS' : 'FAIL', $label );
}

echo "=== CHECKPOINT 10E.1: editorial curation + versioned image manifest ===\n\n";

// Minimal, real filter registry (same approach as
// tools/generate-image-production-manifest.php) — load the REAL,
// unmodified theme files, not a re-typed copy of the curated list.
$GLOBALS['__filters'] = array();
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['__filters'][ $tag ][] = $callback; }
function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['__filters'][ $tag ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$args ); }
	return $value;
}
function add_action( $t, $c, $p = 10, $a = 1 ) {}
require_once $THEME . '/inc/homepage.php';
require_once $THEME . '/inc/editorial-curation.php';

$world_classics_keys = atlas_chuti_world_classics_recipe_keys();

// -----------------------------------------------------------------------
// 1. World Classics list není prázdný.
check( '1. World Classics curated list is not empty', count( $world_classics_keys ) > 0 );

// 2. Všechny World Classics keys existují v 100 recipe concepts.
$cz_recipes = array();
foreach ( glob( $ROOT . '/production-data/europe-1/0[3-7]-recipes-*.json' ) as $f ) {
	foreach ( json_decode( file_get_contents( $f ), true )['recipes'] as $r ) {
		$cz_recipes[ $r['recipe_key'] ] = $r;
	}
}
$en_recipes = array();
foreach ( glob( $ROOT . '/production-data/europe-1-en/0[3-7]-recipes-*.json' ) as $f ) {
	foreach ( json_decode( file_get_contents( $f ), true )['recipes'] as $r ) {
		$en_recipes[ $r['recipe_key'] ] = $r;
	}
}
$unknown_keys = array_diff( $world_classics_keys, array_keys( $cz_recipes ) );
check( '2. every World Classics key exists among the real 100 recipe concepts', 100 === count( $cz_recipes ) && 0 === count( $unknown_keys ) );

// 3. Každý World Classics key má CZ translation.
$missing_cz = array_filter( $world_classics_keys, fn( $k ) => ! isset( $cz_recipes[ $k ] ) );
check( '3. every World Classics key has a CZ translation (recipe exists in production-data/europe-1/)', 0 === count( $missing_cz ) );

// 4. Každý World Classics key má EN translation.
$missing_en = array_filter( $world_classics_keys, fn( $k ) => ! isset( $en_recipes[ $k ] ) );
check( '4. every World Classics key has an EN translation (recipe exists in production-data/europe-1-en/)', 0 === count( $missing_en ) );

// 5. Žádný duplicate key.
check( '5. no duplicate recipe_key inside the World Classics list', count( $world_classics_keys ) === count( array_unique( $world_classics_keys ) ) );

// 6. Shared list renderuje "Světová klasika" CZ a "World Classics" EN.
check(
	'6. front-page.php renders the shared list as "Světová klasika" (CZ) / "World Classics" (EN) from ONE $world_classics variable',
	false !== strpos( $FRONTPAGE, "__( 'World Classics', 'atlas-chuti' ) : __( 'Světová klasika', 'atlas-chuti' )" )
	&& false !== strpos( $FRONTPAGE, '$world_classics' )
);

// -----------------------------------------------------------------------
// Load the manifest deliverables now — check 7 needs the field values.
$csv_path  = $OUT_DIR . '/europe-1-recipe-images.csv';
$json_path = $OUT_DIR . '/europe-1-recipe-images.json';
$md_path   = $OUT_DIR . '/europe-1-recipe-images.md';

$json_rows = json_decode( file_get_contents( $json_path ), true );
$fh        = fopen( $csv_path, 'r' );
$cols      = fgetcsv( $fh );
$csv_rows  = array();
while ( ( $row = fgetcsv( $fh ) ) !== false ) {
	$csv_rows[] = array_combine( $cols, $row );
}
fclose( $fh );

// 7. Žádný popularity/trending claim — scoped to USER-FACING content (the
// MD checklist's prose, and every data VALUE in the manifest: titles/ALT/
// notes), not PHP source comments. A source comment that explains "no
// popularity metric exists" is correct engineering documentation, not a
// claim shipped to an editor or a reader, so it must never fail this check
// — only real user-facing text can.
$forbidden = '/most\s+popular|nejobl[íi]benější|trending|popularity\s+score|#1\s+dish|nejlépe hodnocen/i';
$md_body   = file_get_contents( $md_path );
$field_hit = false;
foreach ( $json_rows as $r ) {
	foreach ( array( 'title_cs', 'title_en', 'alt_cs', 'alt_en', 'notes' ) as $f ) {
		if ( preg_match( $forbidden, (string) $r[ $f ] ) ) {
			$field_hit = true;
		}
	}
}
check(
	'7. no popularity/trending claim in any user-facing text (MD checklist prose or manifest field values)',
	0 === preg_match( $forbidden, $md_body ) && ! $field_hit
);

// 8. Czech homepage zůstává Czech-first.
$order_match = preg_match(
	'/\}\s*else\s*\{\s*echo \$czech_block_html;.*?echo \$world_classics_block_html;/s',
	$FRONTPAGE
);
check( '8. .cz homepage stays Czech-first (czech block echoed before world_classics block in the else branch)', 1 === $order_match );

// 9. EN homepage zůstává global-first.
$order_match_en = preg_match(
	'/if \( \$is_en \) \{\s*echo \$world_classics_block_html;.*?echo \$czech_block_html;/s',
	$FRONTPAGE
);
check( '9. .com (EN) homepage stays global-first (world_classics block echoed before czech block in the $is_en branch)', 1 === $order_match_en );

// 10. Manifest = 100 concepts.
check( '10. manifest (JSON) has exactly 100 concepts', 100 === count( $json_rows ) );

// 11. Manifest world_classic=true přesně odpovídá config keys.
$manifest_wc = array_column( array_filter( $json_rows, fn( $r ) => true === $r['world_classic'] ), 'recipe_key' );
$sorted_config = $world_classics_keys;
sort( $sorted_config );
sort( $manifest_wc );
check( '11. manifest world_classic=true set is EXACTLY the configured World Classics keys', $sorted_config === $manifest_wc );

// 12. CSV/JSON/MD jsou verzované files.
function tracked( $root, $rel ) {
	exec( 'git -C ' . escapeshellarg( $root ) . ' ls-files --error-unmatch ' . escapeshellarg( $rel ) . ' 2>/dev/null', $out, $code );
	return 0 === $code;
}
check(
	'12. europe-1-recipe-images.csv/.json/.md are all tracked in Git',
	tracked( $ROOT, 'docs/image-production/europe-1-recipe-images.csv' )
	&& tracked( $ROOT, 'docs/image-production/europe-1-recipe-images.json' )
	&& tracked( $ROOT, 'docs/image-production/europe-1-recipe-images.md' )
);

// 13. CSV/JSON key sets identické.
$csv_keys  = array_unique( array_column( $csv_rows, 'recipe_key' ) );
$json_keys = array_unique( array_column( $json_rows, 'recipe_key' ) );
sort( $csv_keys );
sort( $json_keys );
check( '13. CSV and JSON recipe_key sets are identical', $csv_keys === $json_keys );

// 14. ALT coverage zůstává 100/100 CZ+EN.
$empty_alt = array_filter( $json_rows, fn( $r ) => '' === trim( (string) $r['alt_cs'] ) || '' === trim( (string) $r['alt_en'] ) );
check( '14. ALT coverage remains 100/100 for both alt_cs and alt_en', 0 === count( $empty_alt ) );

// 15. Source dimensions zůstávají 1600×900.
$wrong_dims = array_filter( $json_rows, fn( $r ) => 1600 !== (int) $r['required_width'] || 900 !== (int) $r['required_height'] );
check( '15. required source dimensions remain 1600x900 for all 100 rows', 0 === count( $wrong_dims ) );

// 16. CZ/EN production content diff = empty.
$prod_diff = shell_exec( 'git -C ' . escapeshellarg( $ROOT ) . ' diff --stat -- production-data/ 2>/dev/null' );
check( '16. production-data/ (CZ+EN) has zero diff — no recipe/country/glossary content was changed', '' === trim( (string) $prod_diff ) );

// 17-21: delegated to the existing regression suite, run as separate shell
// invocations — same convention as every prior harness's own final checks.
function run_php( $rel ) {
	global $ROOT;
	exec( 'php ' . escapeshellarg( $ROOT . '/' . $rel ) . ' 2>&1', $out, $code );
	return 0 === $code;
}
check( '17. tests/harness-checkpoint-10b.php (domains/homepage) regression passes', run_php( 'tests/harness-checkpoint-10b.php' ) );
check( '18. tests/harness-checkpoint-10c.php (media pipeline) regression passes', run_php( 'tests/harness-checkpoint-10c.php' ) );
check( '19. tests/harness-checkpoint-10c1-quality-gate.php passes', run_php( 'tests/harness-checkpoint-10c1-quality-gate.php' ) );
check(
	'20. Checkpoint 10D integrity holds (production-data/europe-1-en/ has zero diff)',
	'' === trim( (string) shell_exec( 'git -C ' . escapeshellarg( $ROOT ) . ' diff --stat -- production-data/europe-1-en/ 2>/dev/null' ) )
);
check( '21. tests/harness-checkpoint-10e-image-production-manifest.php passes', run_php( 'tests/harness-checkpoint-10e-image-production-manifest.php' ) );

// =============================================================================
$total  = count( $RESULTS );
$failed = count( array_filter( $RESULTS, fn( $r ) => ! $r['pass'] ) );
printf( "\n--- %d checks, %d failing ---\n", $total, $failed );
exit( $failed > 0 ? 1 : 0 );
