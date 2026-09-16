<?php
/**
 * CHECKPOINT 10C.1 test harness — exercises the REAL, unmodified
 * tools/content-quality-gate.php by actually running it (via shell_exec)
 * against small, purpose-built fixture batch directories, then asserting on
 * its real stdout. Subprocess-style testing, not a stub/mock of the tool's
 * internals — the tool is a standalone script (no class to unit-test in
 * isolation), and this is the same "run it as a shell command" convention
 * every prior harness already uses for this exact tool (see
 * harness-step-09.php's own trailing note).
 *
 * Covers the checkpoint's 9 numbered required-test scenarios (brief section
 * 6) — see docs/implementation-reports/
 * checkpoint-10c1-quality-gate-relations.md section E/F for the mapping.
 *
 * Run: `php tests/harness-checkpoint-10c1-quality-gate.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$ROOT  = dirname( __DIR__ );
$TOOL  = $ROOT . '/tools/content-quality-gate.php';
$WORK  = sys_get_temp_dir() . '/atlas-chuti-10c1-work-' . getmypid();
@mkdir( $WORK, 0777, true );

function run_gate( $dir ) {
	global $TOOL;
	return shell_exec( 'php ' . escapeshellarg( $TOOL ) . ' ' . escapeshellarg( $dir ) . ' 2>&1' );
}

function write_batch( $dir, array $data ) {
	@mkdir( $dir, 0777, true );
	file_put_contents( $dir . '/batch.json', json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}

/**
 * Minimal-but-valid recipe shape so unrelated ERROR/WARNING noise (missing
 * ingredients, empty steps, ...) never contaminates the relation-specific
 * assertions below — every fixture recipe carries the real required fields.
 */
function fixture_recipe( array $overrides = array() ) {
	return array_merge(
		array(
			'locale'           => 'cs-CZ',
			'title'            => 'Fixture recipe',
			'slug'             => 'fixture-recipe-slug',
			'recipe_key'       => 'fixture-recipe',
			'translation_group' => 'recipe_fixture-recipe',
			'country'          => 'CZ',
			'excerpt'          => str_repeat( 'Dostatecne dlouhy perex pro test. ', 5 ),
			'servings_default' => 4,
			'prep_minutes'     => 10,
			'ingredients'      => array( array( 'ingredient_key' => 'salt', 'display_name' => 'Salt', 'quantity' => '1', 'unit' => 'g' ) ),
			'steps'            => array( array( 'order' => 1, 'text' => 'Do the thing.' ) ),
			'related_recipes'  => array(),
			'related_glossary' => array(),
		),
		$overrides
	);
}

function fixture_glossary( array $overrides = array() ) {
	return array_merge(
		array(
			'locale'            => 'cs-CZ',
			'title'             => 'Fixture term',
			'slug'              => 'fixture-term-slug',
			'translation_group' => 'fixture-term',
			'category'          => 'technique',
			'short_definition'  => 'A short definition.',
		),
		$overrides
	);
}

$RESULTS = array();
function check( $label, $condition ) {
	global $RESULTS;
	$RESULTS[] = array( 'label' => $label, 'pass' => (bool) $condition );
	printf( "%s — %s\n", $condition ? 'PASS' : 'FAIL', $label );
}

echo "=== CHECKPOINT 10C.1: content quality gate relation validation ===\n\n";

// 1. Valid recipe relation via the REAL stable identifier (recipe_key) -> no warning.
$dir1 = $WORK . '/1-valid-recipe-key-relation';
write_batch(
	$dir1,
	array(
		'recipes' => array(
			fixture_recipe( array( 'recipe_key' => 'recipe-a', 'slug' => 'recipe-a-slug', 'translation_group' => 'recipe_recipe-a', 'related_recipes' => array( 'recipe-b' ) ) ),
			fixture_recipe( array( 'recipe_key' => 'recipe-b', 'slug' => 'totally-different-slug', 'translation_group' => 'recipe_recipe-b' ) ),
		),
	)
);
$out1 = run_gate( $dir1 );
check( '1. a related_recipes reference via the real recipe_key produces NO "unknown" warning', false === strpos( $out1, 'related_recipes references unknown' ) );

// 2. Valid translation_group relation (glossary — the post type the importer
// actually supports translation_group resolution for) -> no warning; AND a
// recipe-to-recipe reference via translation_group (NOT recipe_key) must
// still be flagged unknown, since the real importer never resolves recipe
// relations that way (item 4: no fallback identity the importer itself
// doesn't have).
$dir2 = $WORK . '/2-translation-group';
write_batch(
	$dir2,
	array(
		'recipes'  => array(
			fixture_recipe( array( 'recipe_key' => 'recipe-c', 'slug' => 'recipe-c-slug', 'translation_group' => 'recipe_recipe-c', 'related_glossary' => array( 'roux' ), 'related_recipes' => array( 'recipe_recipe-d' ) ) ),
			fixture_recipe( array( 'recipe_key' => 'recipe-d', 'slug' => 'recipe-d-slug', 'translation_group' => 'recipe_recipe-d' ) ),
		),
		'glossary' => array( fixture_glossary( array( 'translation_group' => 'roux', 'slug' => 'jiska' ) ) ),
	)
);
$out2 = run_gate( $dir2 );
check( '2a. a related_glossary reference via the real translation_group produces NO "unknown" warning', false === strpos( $out2, 'related_glossary references unknown' ) );
check( '2b. a related_recipes reference via a target\'s translation_group (not its recipe_key) is STILL flagged unknown — translation_group is not a recipe-relation identity in the real importer either', false !== strpos( $out2, 'related_recipes references unknown recipe_key "recipe_recipe-d"' ) );

// 3. Nonexistent recipe relation -> warning.
$dir3 = $WORK . '/3-nonexistent-recipe';
write_batch( $dir3, array( 'recipes' => array( fixture_recipe( array( 'related_recipes' => array( 'does-not-exist-anywhere' ) ) ) ) ) );
$out3 = run_gate( $dir3 );
check( '3. a related_recipes reference to a genuinely nonexistent recipe_key is flagged', false !== strpos( $out3, 'related_recipes references unknown recipe_key "does-not-exist-anywhere"' ) );

// 4. A localized slug the importer does NOT support for recipe relations must
// never be falsely treated as valid.
$dir4 = $WORK . '/4-slug-not-valid-identity';
write_batch(
	$dir4,
	array(
		'recipes' => array(
			fixture_recipe( array( 'recipe_key' => 'recipe-e', 'slug' => 'recipe-e-slug', 'translation_group' => 'recipe_recipe-e', 'related_recipes' => array( 'recipe-f-public-slug' ) ) ),
			fixture_recipe( array( 'recipe_key' => 'recipe-f', 'slug' => 'recipe-f-public-slug', 'translation_group' => 'recipe_recipe-f' ) ),
		),
	)
);
$out4 = run_gate( $dir4 );
check( '4. referencing a target by its SLUG (not its recipe_key) is flagged unknown, never silently accepted as a valid identity', false !== strpos( $out4, 'related_recipes references unknown recipe_key "recipe-f-public-slug"' ) );

// 5. Valid glossary stable reference -> no warning (distinct fixture from #2).
$dir5 = $WORK . '/5-valid-glossary';
write_batch(
	$dir5,
	array(
		'recipes'  => array( fixture_recipe( array( 'related_glossary' => array( 'blanching' ) ) ) ),
		'glossary' => array( fixture_glossary( array( 'translation_group' => 'blanching', 'slug' => 'blansirovani' ) ) ),
	)
);
$out5 = run_gate( $dir5 );
check( '5. a valid glossary translation_group reference produces no warning', false === strpos( $out5, 'related_glossary references unknown' ) );

// 6. Nonexistent glossary reference -> warning.
$dir6 = $WORK . '/6-nonexistent-glossary';
write_batch( $dir6, array( 'recipes' => array( fixture_recipe( array( 'related_glossary' => array( 'no-such-term' ) ) ) ) ) );
$out6 = run_gate( $dir6 );
check( '6. a related_glossary reference to a genuinely nonexistent term is flagged', false !== strpos( $out6, 'related_glossary references unknown translation_group/slug "no-such-term"' ) );

// 7. Duplicate stable identity (recipe_key) within the same batch/locale -> conflict ERROR.
$dir7 = $WORK . '/7-duplicate-recipe-key';
write_batch(
	$dir7,
	array(
		'recipes' => array(
			fixture_recipe( array( 'recipe_key' => 'dup-key', 'slug' => 'dup-key-one', 'translation_group' => 'recipe_dup-key-one' ) ),
			fixture_recipe( array( 'recipe_key' => 'dup-key', 'slug' => 'dup-key-two', 'translation_group' => 'recipe_dup-key-two' ) ),
		),
	)
);
$out7 = run_gate( $dir7 );
check( '7. a recipe_key duplicated by two recipes in the same batch/locale is reported as an ERROR (matches the real importer\'s hard-reject)', 2 === substr_count( $out7, 'recipe_key "dup-key" is duplicated' ) );

// 8. The current Europe batch: the original 63 false positives are gone.
$europe_dir = $ROOT . '/production-data/europe-1';
$out8       = run_gate( $europe_dir );
preg_match( '/--- (\d+) ERROR, (\d+) WARNING, (\d+) INFO ---/', $out8, $m );
$related_warnings = substr_count( $out8, 'related_recipes references unknown' ) + substr_count( $out8, 'related_glossary references unknown' );
check( '8a. the Europe batch now produces ZERO related_recipes/related_glossary warnings (was 63 before this checkpoint)', 0 === $related_warnings );
check( '8b. the Europe batch still produces 0 ERROR overall (no new false-positive duplicate/etc. introduced)', isset( $m[1] ) && '0' === $m[1] );
check( '8c. the Europe batch INFO count is unchanged at 220 (nothing else in the tool\'s output regressed)', isset( $m[3] ) && '220' === $m[3] );

// 9. production-data/ diff stays empty (this checkpoint only ever reads it).
$prod_diff = shell_exec( 'git -C ' . escapeshellarg( $ROOT ) . ' diff --stat -- production-data/ 2>/dev/null' );
check( '9. production-data/ has zero diff (read-only checkpoint, content batch never modified)', '' === trim( (string) $prod_diff ) );

// =============================================================================
$total  = count( $RESULTS );
$failed = count( array_filter( $RESULTS, fn( $r ) => ! $r['pass'] ) );
printf( "\n--- %d checks, %d failing ---\n", $total, $failed );

function rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) { return; }
	foreach ( scandir( $dir ) as $f ) {
		if ( '.' === $f || '..' === $f ) { continue; }
		$path = $dir . '/' . $f;
		is_dir( $path ) ? rrmdir( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
}
rrmdir( $WORK );

exit( $failed > 0 ? 1 : 0 );
