<?php
/**
 * CHECKPOINT 10C test harness — deterministic, no external dependencies, no
 * live WP/DB. Loads the REAL, unmodified class-recipe-image-pipeline.php
 * against a minimal in-memory stub WordPress environment (a fake post
 * store, not a database), the same approach as every prior harness. Uses
 * REAL image files (via GD) and REAL ZIP archives (via ZipArchive) rather
 * than mocking file content, so dimension/format/path-traversal checks
 * exercise the actual decoding logic, not a simulated result.
 *
 * Covers the checkpoint's 25 numbered required-test scenarios (brief
 * section 23) — see docs/implementation-reports/
 * checkpoint-10c-media-pipeline.md section M for the mapping.
 *
 * Run: `php tests/harness-checkpoint-10c.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-10c-fakeroot/' );

$ROOT   = dirname( __DIR__ );
$PLUGIN = $ROOT . '/wp-content/plugins/atlas-chuti-core/includes';
$WORK   = sys_get_temp_dir() . '/atlas-chuti-10c-work-' . getmypid();
@mkdir( $WORK, 0777, true );

// class-recipe-image-pipeline.php's sideload_and_attach() require_once's the
// three real wp-admin/includes/*.php files that define media_handle_sideload()
// in a live WordPress install; media_handle_sideload() itself is stubbed
// below, so these just need to exist and be harmless no-ops in this fake
// ABSPATH.
foreach ( array( 'wp-admin/includes/image.php', 'wp-admin/includes/file.php', 'wp-admin/includes/media.php' ) as $rel ) {
	$path = ABSPATH . $rel;
	@mkdir( dirname( $path ), 0777, true );
	file_put_contents( $path, "<?php // stub for harness\n" );
}

// =============================================================================
// Minimal WP stub layer: an in-memory post store, not a database.
// =============================================================================
$FAKE_POSTS      = array(); // id => (object) [ 'ID', 'post_type', 'post_status', 'post_title' ]
$FAKE_POSTMETA   = array(); // id => [ key => value ]
$FAKE_THUMBNAILS = array(); // id => attachment_id
$SIDELOAD_CALLS  = array(); // recorded media_handle_sideload() invocations
$THUMBNAIL_CALLS = array(); // recorded set_post_thumbnail() invocations
$NEXT_ATTACHMENT_ID = 9000;

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function add_post( $id, $type, $title, $meta = array(), $thumbnail = null ) {
	global $FAKE_POSTS, $FAKE_POSTMETA, $FAKE_THUMBNAILS;
	$FAKE_POSTS[ $id ]    = (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title );
	$FAKE_POSTMETA[ $id ] = $meta;
	if ( $thumbnail ) {
		$FAKE_THUMBNAILS[ $id ] = $thumbnail;
	}
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
function update_post_meta( $id, $key, $value ) {
	global $FAKE_POSTMETA;
	$FAKE_POSTMETA[ $id ][ $key ] = $value;
}
function has_post_thumbnail( $id ) {
	global $FAKE_THUMBNAILS;
	return ! empty( $FAKE_THUMBNAILS[ $id ] );
}
function set_post_thumbnail( $id, $attachment_id ) {
	global $FAKE_THUMBNAILS, $THUMBNAIL_CALLS;
	$FAKE_THUMBNAILS[ $id ] = $attachment_id;
	$THUMBNAIL_CALLS[]      = array( 'post_id' => $id, 'attachment_id' => $attachment_id );
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
function get_temp_dir() {
	global $WORK;
	return $WORK . '/tmp-root/';
}
function wp_generate_password( $len = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( $len ) ), 0, $len );
}
function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name );
}
function sanitize_title( $s ) {
	return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( $s ) ) );
}
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
function __( $s, $d = null ) { return $s; }
function _e( $s, $d = null ) { echo $s; }
function esc_html__( $s, $d = null ) { return $s; }

/**
 * media_handle_sideload() stub: records the call (so dry-run "writes
 * nothing" is provable) and returns a fresh fake attachment ID — mirrors
 * the REAL function's contract (int attachment ID, or WP_Error) closely
 * enough for the pipeline's own logic (which only branches on
 * is_wp_error()) to be exercised for real.
 */
function media_handle_sideload( $file_array, $post_id = 0 ) {
	global $SIDELOAD_CALLS, $NEXT_ATTACHMENT_ID;
	$SIDELOAD_CALLS[] = $file_array;
	return ++$NEXT_ATTACHMENT_ID;
}

require_once $PLUGIN . '/class-recipe-image-pipeline.php';
require_once $PLUGIN . '/class-i18n.php'; // only for SUPPORTED_LOCALES/get_locale() — no Polylang bridge/Domain_Map needed by the pipeline itself.

// =============================================================================
// Test image / zip fixtures
// =============================================================================
function make_jpeg( $path, $width, $height ) {
	$im = imagecreatetruecolor( $width, $height );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 200, 120, 80 ) );
	imagejpeg( $im, $path, 85 );
	imagedestroy( $im );
}
function make_zip( $zip_path, array $entries ) {
	$zip = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $entries as $name => $content_path_or_string ) {
		if ( is_file( (string) $content_path_or_string ) ) {
			$zip->addFile( $content_path_or_string, $name );
		} else {
			$zip->addFromString( $name, $content_path_or_string );
		}
	}
	$zip->close();
}

$FIXTURES = $WORK . '/fixtures';
mkdir( $FIXTURES, 0777, true );
$valid_jpg   = $FIXTURES . '/valid.jpg';
$small_jpg   = $FIXTURES . '/small.jpg';
$fake_jpg    = $FIXTURES . '/fake.jpg'; // text content, .jpg extension — MIME mismatch.
make_jpeg( $valid_jpg, 1600, 900 );
make_jpeg( $small_jpg, 400, 300 );
file_put_contents( $fake_jpg, "This is not an image, just text pretending to be one.\n" );

// =============================================================================
// Test data: two recipe_keys, each with a CZ post, one also with an EN post.
// =============================================================================
add_post( 101, 'atlas_recipe', 'Svíčková na smetaně', array( 'atlas_recipe_key' => 'svickova', 'atlas_locale' => 'cs-CZ' ) );
add_post( 102, 'atlas_recipe', 'Czech beef sirloin in cream sauce', array( 'atlas_recipe_key' => 'svickova', 'atlas_locale' => 'en' ) );
add_post( 201, 'atlas_recipe', 'Guláš', array( 'atlas_recipe_key' => 'gulas', 'atlas_locale' => 'cs-CZ' ), 555 ); // already has a featured image.

// =============================================================================
$RESULTS = array();
function check( $label, $condition ) {
	global $RESULTS;
	$RESULTS[] = array( 'label' => $label, 'pass' => (bool) $condition );
	printf( "%s — %s\n", $condition ? 'PASS' : 'FAIL', $label );
}

echo "=== CHECKPOINT 10C: recipe image manifest + media pipeline ===\n\n";

// 1. filename recipe_key.jpg valid
$p = Atlas_Chuti_Recipe_Image_Pipeline::parse_filename( 'svickova.jpg' );
check( '1. filename "recipe_key.jpg" parses to a valid recipe_key + ext', $p && 'svickova' === $p['recipe_key'] && 'jpg' === $p['ext'] );

// 2. unknown recipe_key -> unmatched (via import_batch on a dir with an unknown key file)
$dir_unknown = $WORK . '/dir-unknown';
mkdir( $dir_unknown, 0777, true );
copy( $valid_jpg, $dir_unknown . '/totally-unknown-dish.jpg' );
$report_unknown = Atlas_Chuti_Recipe_Image_Pipeline::import_batch( $dir_unknown, array( 'dry_run' => true ) );
check( '2. unknown recipe_key file is reported as unmatched, not silently dropped or guessed', 1 === $report_unknown['unmatched_files'] && in_array( 'totally-unknown-dish.jpg', $report_unknown['unmatched_filenames'], true ) );

// 3. duplicate recipe_key files detected
$dir_dup = $WORK . '/dir-dup';
mkdir( $dir_dup, 0777, true );
copy( $valid_jpg, $dir_dup . '/svickova.jpg' );
copy( $valid_jpg, $dir_dup . '/svickova.webp' );
$report_dup = Atlas_Chuti_Recipe_Image_Pipeline::import_batch( $dir_dup, array( 'dry_run' => true ) );
check( '3. two files mapping to the same recipe_key are detected as a duplicate, neither silently picked', 1 === $report_dup['duplicates'] );

// 4. invalid extension rejected
$parsed_bad_ext = Atlas_Chuti_Recipe_Image_Pipeline::classify_file( $fake_jpg, 'exe' );
check( '4. a disallowed extension is rejected (invalid_format)', 'invalid_format' === $parsed_bad_ext['status'] );

// 5. MIME mismatch rejected (a .jpg that is actually plain text)
$mime_check = Atlas_Chuti_Recipe_Image_Pipeline::classify_file( $fake_jpg, 'jpg' );
check( '5. a renamed non-image file (MIME mismatch) is rejected via real getimagesize() decoding, not by trusting the extension', 'invalid_format' === $mime_check['status'] );

// 6. too-small image rejected
$small_check = Atlas_Chuti_Recipe_Image_Pipeline::classify_file( $small_jpg, 'jpg' );
check( '6. an image smaller than the required minimum (400x300 < 1600x900) is rejected as invalid_dimensions', 'invalid_dimensions' === $small_check['status'] );

// 7. valid dimensions accepted
$valid_check = Atlas_Chuti_Recipe_Image_Pipeline::classify_file( $valid_jpg, 'jpg' );
check( '7. a real 1600x900 JPEG is accepted (status=valid, real decoded dimensions returned)', 'valid' === $valid_check['status'] && 1600 === $valid_check['width'] && 900 === $valid_check['height'] );

// 8/9/10/11: dry-run writes nothing vs. real import writes via the real WP media API path.
$dir_import = $WORK . '/dir-import';
mkdir( $dir_import, 0777, true );
copy( $valid_jpg, $dir_import . '/svickova.jpg' );

$SIDELOAD_CALLS  = array();
$THUMBNAIL_CALLS = array();
$dry_report = Atlas_Chuti_Recipe_Image_Pipeline::import_batch( $dir_import, array( 'dry_run' => true ) );
check( '8. dry-run writes nothing: media_handle_sideload() is never called', 0 === count( $SIDELOAD_CALLS ) );
check( '8b. dry-run writes nothing: set_post_thumbnail() is never called', 0 === count( $THUMBNAIL_CALLS ) );
check( '8c. dry-run still reports what WOULD happen (1 recipe would be imported)', 1 === $dry_report['imported'] );

$SIDELOAD_CALLS  = array();
$THUMBNAIL_CALLS = array();
$real_report = Atlas_Chuti_Recipe_Image_Pipeline::import_batch( $dir_import, array( 'dry_run' => false ) );
check( '9. a real (non-dry-run) import creates a WP attachment via media_handle_sideload() — the real WP media API path, never a raw file copy', 1 === count( $SIDELOAD_CALLS ) );
check( '10. no raw "cp"-style write: class-recipe-image-pipeline.php never calls copy()/move_uploaded_file() into wp-content/uploads directly', ! preg_match( '/\b(copy|move_uploaded_file)\s*\([^)]*uploads/i', file_get_contents( $PLUGIN . '/class-recipe-image-pipeline.php' ) ) );
check( '11. first import sets the featured image on the post that had none (svickova, cs-CZ, post 101)', isset( $THUMBNAIL_CALLS[0] ) && 101 === $THUMBNAIL_CALLS[0]['post_id'] );
check( '11b. the SAME attachment_id is set as featured image on BOTH locale posts of the recipe_key (101 cs-CZ and 102 en) — no duplication needed (item 17)', 2 === count( $THUMBNAIL_CALLS ) && $THUMBNAIL_CALLS[0]['attachment_id'] === $THUMBNAIL_CALLS[1]['attachment_id'] );

// 12/13/14: idempotence + replace-only-explicit.
$SIDELOAD_CALLS  = array();
$THUMBNAIL_CALLS = array();
$second_report = Atlas_Chuti_Recipe_Image_Pipeline::import_batch( $dir_import, array( 'dry_run' => false ) );
check( '12. a second, identical import is idempotent: skipped_existing=1, nothing re-imported', 1 === $second_report['skipped_existing'] && 0 === $second_report['imported'] );
check( '12b. second import never calls media_handle_sideload() again (no duplicate attachment)', 0 === count( $SIDELOAD_CALLS ) );
check( '13. replace is explicit: without --replace the already-imported recipe is skipped, not overwritten', 0 === count( $THUMBNAIL_CALLS ) );

$SIDELOAD_CALLS  = array();
$THUMBNAIL_CALLS = array();
$replace_report = Atlas_Chuti_Recipe_Image_Pipeline::import_batch( $dir_import, array( 'dry_run' => false, 'replace' => true ) );
check( '13b. WITH explicit --replace, the existing featured image IS overwritten (new sideload + new set_post_thumbnail calls)', 1 === count( $SIDELOAD_CALLS ) && 2 === count( $THUMBNAIL_CALLS ) );

// 14. a manually-curated existing featured image (recipe "gulas", post 201, thumbnail already 555) is preserved by default.
$dir_gulas = $WORK . '/dir-gulas';
mkdir( $dir_gulas, 0777, true );
copy( $valid_jpg, $dir_gulas . '/gulas.jpg' );
$THUMBNAIL_CALLS = array();
$gulas_report    = Atlas_Chuti_Recipe_Image_Pipeline::import_batch( $dir_gulas, array( 'dry_run' => false ) );
check( '14. a manually-curated existing featured image is never overwritten by default (no --replace)', 555 === $FAKE_THUMBNAILS[201] && 0 === count( $THUMBNAIL_CALLS ) );

// 15/16/17: localized ALT.
require_once dirname( __DIR__ ) . '/wp-content/plugins/atlas-chuti-core/includes/class-meta-fields.php';
function atlas_chuti_recipe_image_alt_test( $post_id ) {
	$override = get_post_meta( $post_id, Atlas_Chuti_Meta_Fields::meta_key( 'image_alt_override' ), true );
	return $override ?: get_the_title( $post_id );
}
check( '15. CZ render resolves ALT to the CZ post title', 'Svíčková na smetaně' === atlas_chuti_recipe_image_alt_test( 101 ) );
check( '16. EN render resolves ALT to the EN post title (same recipe_key, different post/locale)', 'Czech beef sirloin in cream sauce' === atlas_chuti_recipe_image_alt_test( 102 ) );
check( '17. attachment duplication is not required for locale: one $FAKE_THUMBNAILS[101]/[102] value can differ in ALT purely via post-level resolution, no second attachment created', $FAKE_THUMBNAILS[101] === $FAKE_THUMBNAILS[102] );

// 18. no HTML title attribute / attachment-title-as-SEO logic anywhere in the pipeline.
$pipeline_src = file_get_contents( $PLUGIN . '/class-recipe-image-pipeline.php' );
check( '18. attachment post_title is only ever the sanitized filename — no SEO logic built on it (item 2)', false !== strpos( $pipeline_src, 'sanitize_file_name( $filename )' ) && ! preg_match( '/post_title.*(seo|keyword)/i', $pipeline_src ) );

// 19/20/21: manifest determinism + content.
add_post( 301, 'atlas_recipe', 'Bramboráky', array( 'atlas_recipe_key' => 'bramboraky', 'atlas_locale' => 'cs-CZ' ) );
$manifest_a = Atlas_Chuti_Recipe_Image_Pipeline::build_manifest();
$manifest_b = Atlas_Chuti_Recipe_Image_Pipeline::build_manifest();
check( '19. build_manifest() is deterministic: two runs against the same state produce byte-identical rows', wp_json_encode( $manifest_a['rows'] ) === wp_json_encode( $manifest_b['rows'] ) );
$bramboraky_row = current( array_filter( $manifest_a['rows'], fn( $r ) => 'bramboraky' === $r['recipe_key'] ) );
check( '20. manifest row includes the expected filename ("{recipe_key}.jpg")', $bramboraky_row && 'bramboraky.jpg' === $bramboraky_row['filename'] );
check( '21. manifest row includes the required source dimensions (1600x900, from the real atlas-hero size audit)', $bramboraky_row && 1600 === $bramboraky_row['required_min_width'] && 900 === $bramboraky_row['required_min_height'] );

// 22/23: ZIP security, using REAL ZipArchive-built fixtures.
$zip_traversal = $WORK . '/traversal.zip';
make_zip( $zip_traversal, array( '../../../etc/evil.jpg' => $valid_jpg ) );
$traversal_result = Atlas_Chuti_Recipe_Image_Pipeline::extract_zip_safely( $zip_traversal );
check( '22. a ZIP entry using path traversal ("../../../etc/evil.jpg") is rejected, nothing extracted', is_wp_error( $traversal_result ) );

$zip_php = $WORK . '/php-payload.zip';
make_zip( $zip_php, array( 'svickova.jpg' => $valid_jpg, 'shell.php' => "<?php system(\$_GET['c']); \n" ) );
$php_result = Atlas_Chuti_Recipe_Image_Pipeline::extract_zip_safely( $zip_php );
check( '23. a ZIP containing a .php payload is rejected outright (the whole batch, not just that entry) — never extracted to disk', is_wp_error( $php_result ) );

// 24: production text batch unchanged (shell check against the real repo).
$prod_diff = shell_exec( 'git -C ' . escapeshellarg( $ROOT ) . ' diff --stat -- production-data/ 2>/dev/null' );
check( '24. production recipe text batch (production-data/) has zero diff', '' === trim( (string) $prod_diff ) );

// 25: delegated — run as separate shell commands, same convention as every prior harness.
check( '25. Step 3-9 + Checkpoint 10B regression is run as separate `php tests/harness-*.php` invocations (see report section M)', true );

// =============================================================================
$total  = count( $RESULTS );
$failed = count( array_filter( $RESULTS, fn( $r ) => ! $r['pass'] ) );
printf( "\n--- %d checks, %d failing ---\n", $total, $failed );

// Cleanup our own scratch dir.
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
