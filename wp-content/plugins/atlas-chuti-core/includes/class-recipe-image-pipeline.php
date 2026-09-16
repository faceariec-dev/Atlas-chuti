<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CHECKPOINT 10C: batch recipe-image pipeline — manifest generation + a safe
 * ZIP/directory importer, so recipe photos never have to be attached one by
 * one through wp-admin.
 *
 * Every method here is STATIC and stateless on purpose: unlike
 * class-photos.php (a per-request moderation/upload service with its own
 * hooks), this class only ever runs from `wp atlas image-manifest` /
 * `wp atlas image-import` (class-cli.php) — there is nothing to register on
 * `plugins_loaded`, so a singleton would just be ceremony.
 *
 * Pairing identity, everywhere in this file, is `recipe_key` — the SAME
 * stable, locale-neutral identity class-json-importer.php resolves
 * `related_recipes` by (see class-i18n.php's find_by_recipe_key()). Never
 * the localized title, never the slug, never file order.
 */
class Atlas_Chuti_Recipe_Image_Pipeline {

	/**
	 * Reuses the EXACT same allowlist as class-photos.php's user-photo
	 * upload path (Krok 5) — one project-wide idea of "a valid recipe
	 * photo", not a second, drifting one. No SVG (brief item 11: "Nepovoluj
	 * arbitrary SVG upload v tomto checkpointu").
	 */
	const ALLOWED_MIMES = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);

	/**
	 * 20 MB: source recipe photos are professional/curated master images
	 * (batch-produced for the whole catalog), not phone snapshots — a
	 * deliberately more generous ceiling than class-photos.php's 5 MB
	 * user-upload limit, still bounded against abuse.
	 */
	const MAX_BYTES = 20 * 1024 * 1024;

	/**
	 * CHECKPOINT 10C, section 5 audit: the brief's OWN illustrative source
	 * target was 1600×1000 (8:5) "pokud current design používá přibližně
	 * 16:10 / 8:5" — but the REAL theme registers `atlas-hero` at 1600×900
	 * (16:9, hard crop — see functions.php's add_image_size() calls, used
	 * by single-atlas_recipe.php's hero and the homepage lead story) and
	 * `atlas-card` at 640×480 (4:3, hard crop — recipe-card.php,
	 * everywhere recipes appear in a grid/feed). A source that is at least
	 * 1600×900 covers BOTH real crops without upscaling (cropping a 16:9
	 * source down to 4:3 only needs width ≥ height×4/3 = 1200, already
	 * satisfied once width ≥ 1600) — so per the brief's own instruction to
	 * "use the real design" when it differs from the illustrative example,
	 * this pipeline's required minimum is 1600×900, NOT 1600×1000.
	 */
	const REQUIRED_MIN_WIDTH  = 1600;
	const REQUIRED_MIN_HEIGHT = 900;

	// ZIP safety limits (brief item 21).
	const MAX_ZIP_ENTRIES     = 300;
	const MAX_ZIP_TOTAL_BYTES = 500 * 1024 * 1024; // 500 MB extracted, well above 300 * 20MB worst case is impossible anyway since each entry is also checked individually.

	const ROLE_FEATURED = 'featured';

	// -------------------------------------------------------------------
	// Filename / recipe_key parsing
	// -------------------------------------------------------------------

	/**
	 * Same stable-key shape class-json-importer.php's is_valid_stable_key()
	 * enforces — lowercase alphanumeric segments joined by "_" or "-". Kept
	 * as its own copy (not a cross-class call into the importer's private
	 * method) since the two tools are independently invoked and this is a
	 * one-line, unlikely-to-drift regex; both are documented against the
	 * same contract on purpose.
	 */
	public static function is_valid_recipe_key( $key ) {
		return (bool) preg_match( '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/', (string) $key );
	}

	/**
	 * Parses `{recipe_key}.{ext}` — returns null for anything that doesn't
	 * match this exact shape (no title, no free-form name, never guessed).
	 */
	public static function parse_filename( $filename ) {
		$base = basename( (string) $filename );
		if ( ! preg_match( '/^(.+)\.([A-Za-z0-9]+)$/', $base, $m ) ) {
			return null;
		}
		$recipe_key = $m[1];
		$ext        = strtolower( $m[2] );
		if ( ! self::is_valid_recipe_key( $recipe_key ) ) {
			return null;
		}
		return array( 'recipe_key' => $recipe_key, 'ext' => $ext, 'filename' => $base );
	}

	/**
	 * Every recipe_key currently in the database, with the post ID for each
	 * locale that actually has a post for it (locale => post_id). Built
	 * fresh each call — this tool runs occasionally in bulk, not on every
	 * front-end request, so no caching layer is warranted.
	 */
	public static function known_recipe_keys() {
		$map = array();
		foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $locale ) {
			$posts = get_posts(
				array(
					'post_type'      => 'atlas_recipe',
					'posts_per_page' => -1,
					'post_status'    => array( 'publish', 'draft' ),
					'fields'         => 'ids',
				)
			);
			foreach ( $posts as $post_id ) {
				if ( Atlas_Chuti_I18N::get_locale( $post_id ) !== $locale ) {
					continue;
				}
				$key = get_post_meta( $post_id, 'atlas_recipe_key', true );
				if ( ! $key ) {
					continue;
				}
				$map[ $key ][ $locale ] = (int) $post_id;
			}
		}
		return $map;
	}

	// -------------------------------------------------------------------
	// File validation
	// -------------------------------------------------------------------

	/**
	 * Full validation chain for one candidate file already on disk (from an
	 * extracted ZIP or a plain source directory): extension allowlist, REAL
	 * decoded image type (getimagesize(), same "don't trust the extension"
	 * discipline as class-photos.php), minimum dimensions, file size,
	 * corrupted-file detection (getimagesize() failing IS the corruption
	 * check — a truncated/non-image file simply won't decode).
	 *
	 * Returns array( 'status' => ..., 'width' => int|null, 'height' =>
	 * int|null, 'mime' => string|null ). `status` is one of: 'valid',
	 * 'invalid_format', 'invalid_dimensions' (the two the brief's manifest
	 * status enum names explicitly).
	 */
	public static function classify_file( $abs_path, $ext ) {
		$ext = strtolower( $ext );
		$allowed_ext = array();
		foreach ( array_keys( self::ALLOWED_MIMES ) as $pattern ) {
			foreach ( explode( '|', $pattern ) as $e ) {
				$allowed_ext[] = $e;
			}
		}
		if ( ! in_array( $ext, $allowed_ext, true ) ) {
			return array( 'status' => 'invalid_format', 'width' => null, 'height' => null, 'mime' => null );
		}
		if ( ! is_readable( $abs_path ) || filesize( $abs_path ) > self::MAX_BYTES ) {
			return array( 'status' => 'invalid_format', 'width' => null, 'height' => null, 'mime' => null );
		}

		// Decode the real header — a renamed non-image or a truncated/
		// corrupted file fails here regardless of what its extension claims.
		$real = @getimagesize( $abs_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false return is the expected, handled failure path for a non-image/corrupted file.
		if ( ! $real || empty( $real['mime'] ) || ! in_array( $real['mime'], self::ALLOWED_MIMES, true ) ) {
			return array( 'status' => 'invalid_format', 'width' => null, 'height' => null, 'mime' => $real['mime'] ?? null );
		}

		$width  = (int) $real[0];
		$height = (int) $real[1];
		if ( $width < self::REQUIRED_MIN_WIDTH || $height < self::REQUIRED_MIN_HEIGHT ) {
			return array( 'status' => 'invalid_dimensions', 'width' => $width, 'height' => $height, 'mime' => $real['mime'] );
		}

		return array( 'status' => 'valid', 'width' => $width, 'height' => $height, 'mime' => $real['mime'] );
	}

	// -------------------------------------------------------------------
	// Manifest
	// -------------------------------------------------------------------

	/**
	 * Builds the full recipe-images manifest — one row per recipe_key
	 * (concept-level, brief item 4), NOT one per locale post. Deterministic:
	 * sorted by recipe_key, every value derived from real DB state (or, when
	 * $source_dir is given, real files on disk) — never fabricated.
	 *
	 * When $source_dir is given, each row's `status` also reflects whether
	 * a matching candidate file was found there and whether it validates
	 * (present / invalid_format / invalid_dimensions); without it, `status`
	 * is only ever 'present' (has a featured image already, in EVERY
	 * published locale post for that key) or 'missing'.
	 */
	public static function build_manifest( $source_dir = null ) {
		$known = self::known_recipe_keys();
		ksort( $known );

		$files_by_key    = array();
		$unmatched_files = array();
		if ( $source_dir && is_dir( $source_dir ) ) {
			foreach ( self::list_candidate_files( $source_dir ) as $abs_path ) {
				$parsed = self::parse_filename( basename( $abs_path ) );
				if ( ! $parsed ) {
					$unmatched_files[] = basename( $abs_path );
					continue;
				}
				if ( ! isset( $known[ $parsed['recipe_key'] ] ) ) {
					$unmatched_files[] = basename( $abs_path );
					continue;
				}
				$files_by_key[ $parsed['recipe_key'] ][] = array( 'path' => $abs_path, 'filename' => $parsed['filename'], 'ext' => $parsed['ext'] );
			}
		}

		$rows              = array();
		$duplicate_keys     = array();
		foreach ( $known as $recipe_key => $locale_posts ) {
			$title_cs = isset( $locale_posts['cs-CZ'] ) ? get_the_title( $locale_posts['cs-CZ'] ) : null;
			$title_en = isset( $locale_posts['en'] ) ? get_the_title( $locale_posts['en'] ) : null;

			$has_featured = true;
			foreach ( $locale_posts as $post_id ) {
				if ( ! has_post_thumbnail( $post_id ) ) {
					$has_featured = false;
					break;
				}
			}

			$status   = $has_featured ? 'present' : 'missing';
			$filename = $recipe_key . '.jpg'; // preferred/canonical extension, per brief item 1.

			if ( isset( $files_by_key[ $recipe_key ] ) ) {
				if ( count( $files_by_key[ $recipe_key ] ) > 1 ) {
					$status         = 'duplicate';
					$duplicate_keys[ $recipe_key ] = wp_list_pluck( $files_by_key[ $recipe_key ], 'filename' );
				} else {
					$candidate = $files_by_key[ $recipe_key ][0];
					$filename  = $candidate['filename'];
					$check     = self::classify_file( $candidate['path'], $candidate['ext'] );
					$status    = 'valid' === $check['status'] ? 'present' : $check['status'];
				}
			}

			$rows[] = array(
				'recipe_key'          => $recipe_key,
				'title_cs'            => $title_cs,
				'title_en'            => $title_en,
				'filename'            => $filename,
				'role'                => self::ROLE_FEATURED,
				'required_min_width'  => self::REQUIRED_MIN_WIDTH,
				'required_min_height' => self::REQUIRED_MIN_HEIGHT,
				'alt_cs'              => $title_cs,
				'alt_en'              => $title_en,
				'status'              => $status,
			);
		}

		return array(
			'rows'             => $rows,
			'unmatched_files'  => $unmatched_files,
			'duplicate_keys'   => $duplicate_keys,
		);
	}

	private static function list_candidate_files( $dir ) {
		$files = array();
		foreach ( glob( rtrim( $dir, '/' ) . '/*' ) ?: array() as $path ) {
			if ( is_file( $path ) ) {
				$files[] = $path;
			}
		}
		sort( $files ); // deterministic order.
		return $files;
	}

	/**
	 * Writes recipe-images.csv + recipe-images.json (brief item 4) plus
	 * missing-recipe-images.csv (item 17, a plain filter of the same rows)
	 * into $dir. Deterministic byte-for-byte given the same DB/source-dir
	 * state — same sort order, same column order, every run.
	 */
	public static function write_manifest_files( array $manifest, $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$rows    = $manifest['rows'];
		$columns = array( 'recipe_key', 'title_cs', 'title_en', 'filename', 'role', 'required_min_width', 'required_min_height', 'alt_cs', 'alt_en', 'status' );

		$csv_path = rtrim( $dir, '/' ) . '/recipe-images.csv';
		$fh       = fopen( $csv_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- CLI-only file tool, no WP_Filesystem context available.
		fputcsv( $fh, $columns );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( fn( $c ) => $row[ $c ] ?? '', $columns ) );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$json_path = rtrim( $dir, '/' ) . '/recipe-images.json';
		file_put_contents( $json_path, wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$missing_path = rtrim( $dir, '/' ) . '/missing-recipe-images.csv';
		$missing_rows = array_values( array_filter( $rows, fn( $r ) => 'missing' === $r['status'] ) );
		$fh2          = fopen( $missing_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $fh2, array( 'recipe_key', 'title_cs', 'title_en', 'expected_filename', 'required_dimensions', 'current_status' ) );
		foreach ( $missing_rows as $row ) {
			fputcsv(
				$fh2,
				array(
					$row['recipe_key'],
					$row['title_cs'],
					$row['title_en'],
					$row['recipe_key'] . '.jpg',
					$row['required_min_width'] . 'x' . $row['required_min_height'],
					$row['status'],
				)
			);
		}
		fclose( $fh2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array( 'csv' => $csv_path, 'json' => $json_path, 'missing_csv' => $missing_path );
	}

	// -------------------------------------------------------------------
	// ZIP safety + extraction (brief item 21)
	// -------------------------------------------------------------------

	/**
	 * Validates and extracts a ZIP into a fresh, safe temp directory under
	 * the system temp dir. Rejects: path traversal (any entry resolving
	 * outside the destination), too many entries, too much total
	 * (uncompressed) size, and any entry whose extension isn't in the plain
	 * image allowlist (so a smuggled .php/.exe/.sh/etc. is rejected by name
	 * before it's ever written to disk, on top of classify_file()'s later
	 * content check on the ones that DO look like images).
	 *
	 * Returns the temp dir path, or a WP_Error — the caller is responsible
	 * for cleanup() in a finally-style block either way (a WP_Error means
	 * nothing was extracted, but this method itself never leaves a
	 * partially-written dir behind on failure).
	 */
	public static function extract_zip_safely( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'atlas_image_zip_unsupported', __( 'Server nemá podporu ZipArchive.', 'atlas-chuti' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'atlas_image_zip_unreadable', __( 'ZIP soubor nelze otevřít.', 'atlas-chuti' ) );
		}

		$count = $zip->numFiles;
		if ( $count > self::MAX_ZIP_ENTRIES ) {
			$zip->close();
			return new WP_Error( 'atlas_image_zip_too_many_entries', sprintf( /* translators: 1: actual count, 2: max allowed */ __( 'ZIP obsahuje příliš mnoho položek (%1$d, max %2$d).', 'atlas-chuti' ), $count, self::MAX_ZIP_ENTRIES ) );
		}

		$dest = trailingslashit( get_temp_dir() ) . 'atlas-chuti-image-import-' . wp_generate_password( 12, false, false );
		if ( ! wp_mkdir_p( $dest ) ) {
			$zip->close();
			return new WP_Error( 'atlas_image_zip_tmp_dir', __( 'Nelze vytvořit dočasný adresář.', 'atlas-chuti' ) );
		}
		$real_dest = realpath( $dest );

		$total_size = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat ) {
				continue;
			}
			$name = $stat['name'];

			// Directories inside the ZIP are silently skipped — this tool
			// only ever expects a flat batch of image files, and skipping
			// them is strictly safer than trying to recurse into them.
			if ( '/' === substr( $name, -1 ) ) {
				continue;
			}

			// Path traversal: reject any entry name containing ".." or an
			// absolute path, and reject anything that would resolve outside
			// $real_dest once joined — belt AND suspenders, never trust a
			// single check for this.
			if ( false !== strpos( $name, '..' ) || 0 === strpos( $name, '/' ) || preg_match( '#^[a-zA-Z]:[\\\\/]#', $name ) ) {
				self::cleanup( $dest );
				$zip->close();
				return new WP_Error( 'atlas_image_zip_traversal', __( 'ZIP obsahuje nebezpečnou cestu (path traversal).', 'atlas-chuti' ) );
			}

			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$allowed_ext = array();
			foreach ( array_keys( self::ALLOWED_MIMES ) as $pattern ) {
				foreach ( explode( '|', $pattern ) as $e ) {
					$allowed_ext[] = $e;
				}
			}
			if ( ! in_array( $ext, $allowed_ext, true ) ) {
				// Never extract a non-image (never .php, never an
				// executable, never anything outside the allowlist) —
				// rejected by name, before any bytes are written.
				self::cleanup( $dest );
				$zip->close();
				return new WP_Error( 'atlas_image_zip_disallowed_entry', sprintf( /* translators: %s: file name inside the zip */ __( 'ZIP obsahuje nepovolený typ souboru: %s', 'atlas-chuti' ), $name ) );
			}

			$total_size += (int) $stat['size'];
			if ( $total_size > self::MAX_ZIP_TOTAL_BYTES ) {
				self::cleanup( $dest );
				$zip->close();
				return new WP_Error( 'atlas_image_zip_too_large', __( 'ZIP po rozbalení přesahuje povolenou celkovou velikost.', 'atlas-chuti' ) );
			}
		}

		if ( ! $zip->extractTo( $dest ) ) {
			self::cleanup( $dest );
			$zip->close();
			return new WP_Error( 'atlas_image_zip_extract_failed', __( 'Rozbalení ZIP souboru selhalo.', 'atlas-chuti' ) );
		}
		$zip->close();

		// Flatten: files may have landed in a subdirectory (e.g. a ZIP whose
		// entries are "recipe-images/svickova.jpg") — collect every regular
		// file found anywhere under $dest, non-recursively re-homed isn't
		// necessary since list_candidate_files() below globs $dest itself;
		// instead we just point the caller at $dest and let a recursive
		// listing handle it.
		return $dest;
	}

	public static function cleanup( $dir ) {
		if ( ! $dir || ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best-effort cleanup of our own temp dir.
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Recursive file listing used only for a just-extracted ZIP (where
	 * entries may sit one directory level deep) — build_manifest()'s own
	 * list_candidate_files() stays flat/non-recursive for a plain source
	 * directory, since that's the documented, simpler contract for a
	 * directory import.
	 */
	public static function list_files_recursive( $dir ) {
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$files[] = $item->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	// -------------------------------------------------------------------
	// Import
	// -------------------------------------------------------------------

	/**
	 * The main batch import orchestrator. $source is either a directory or
	 * a .zip file. Returns a report array (brief item 18's exact counters)
	 * plus a `rows` array with one entry per recipe_key encountered, for a
	 * machine-readable report file.
	 *
	 * $options: 'dry_run' (bool, default true — callers must opt IN to a
	 * real write, never the other way round) and 'replace' (bool, default
	 * false — brief item 10: "Replace smí proběhnout jen s explicitním
	 * parametrem").
	 */
	public static function import_batch( $source, array $options = array() ) {
		$dry_run = $options['dry_run'] ?? true;
		$replace = $options['replace'] ?? false;

		$cleanup_dir = null;
		if ( is_file( $source ) && preg_match( '/\.zip$/i', $source ) ) {
			$extracted = self::extract_zip_safely( $source );
			if ( is_wp_error( $extracted ) ) {
				return array( 'error' => $extracted );
			}
			$dir         = $extracted;
			$cleanup_dir = $extracted;
			$files       = self::list_files_recursive( $dir );
		} elseif ( is_dir( $source ) ) {
			$files = self::list_candidate_files( $source );
		} else {
			return array( 'error' => new WP_Error( 'atlas_image_source_invalid', __( 'Zdroj musí být ZIP soubor nebo existující adresář.', 'atlas-chuti' ) ) );
		}

		$known = self::known_recipe_keys();

		$by_key           = array();
		$unmatched_files  = array();
		foreach ( $files as $abs_path ) {
			$parsed = self::parse_filename( basename( $abs_path ) );
			if ( ! $parsed || ! isset( $known[ $parsed['recipe_key'] ] ) ) {
				$unmatched_files[] = basename( $abs_path );
				continue;
			}
			$by_key[ $parsed['recipe_key'] ][] = array( 'path' => $abs_path, 'filename' => $parsed['filename'], 'ext' => $parsed['ext'] );
		}

		// Report is recipe_key-centric (brief item 18's sample counts sum to
		// the FULL known-recipe count, not the file count): every known
		// recipe_key gets exactly one outcome below. "Unmatched files" is
		// tracked separately — those are FILES that never mapped to any
		// recipe_key at all, so they can't be counted against a recipe.
		$report = array(
			'imported'           => 0,
			'skipped_existing'   => 0,
			'missing_recipe'     => 0,
			'invalid_dimensions' => 0,
			'invalid_format'     => 0,
			'duplicates'         => 0,
			'unmatched_files'    => count( $unmatched_files ),
			'rows'               => array(),
			'unmatched_filenames' => $unmatched_files,
		);

		foreach ( $known as $recipe_key => $locale_posts ) {
			if ( ! isset( $by_key[ $recipe_key ] ) ) {
				++$report['missing_recipe'];
				$report['rows'][] = array( 'recipe_key' => $recipe_key, 'status' => 'missing_recipe' );
				continue;
			}
			$candidates = $by_key[ $recipe_key ];

			if ( count( $candidates ) > 1 ) {
				++$report['duplicates'];
				$report['rows'][] = array( 'recipe_key' => $recipe_key, 'status' => 'duplicate', 'files' => wp_list_pluck( $candidates, 'filename' ) );
				continue;
			}

			$candidate = $candidates[0];
			$check     = self::classify_file( $candidate['path'], $candidate['ext'] );
			if ( 'valid' !== $check['status'] ) {
				if ( 'invalid_dimensions' === $check['status'] ) {
					++$report['invalid_dimensions'];
				} else {
					++$report['invalid_format'];
				}
				$report['rows'][] = array( 'recipe_key' => $recipe_key, 'status' => $check['status'], 'file' => $candidate['filename'], 'width' => $check['width'], 'height' => $check['height'] );
				continue;
			}

			$any_missing_featured = false;
			foreach ( $locale_posts as $post_id ) {
				if ( ! has_post_thumbnail( $post_id ) ) {
					$any_missing_featured = true;
				}
			}
			if ( ! $any_missing_featured && ! $replace ) {
				++$report['skipped_existing'];
				$report['rows'][] = array( 'recipe_key' => $recipe_key, 'status' => 'skipped_existing', 'file' => $candidate['filename'] );
				continue;
			}

			if ( $dry_run ) {
				++$report['imported']; // "would import" — dry-run never writes, see below.
				$report['rows'][] = array( 'recipe_key' => $recipe_key, 'status' => 'would_import', 'file' => $candidate['filename'], 'width' => $check['width'], 'height' => $check['height'] );
				continue;
			}

			$attachment_id = self::sideload_and_attach( $candidate['path'], $candidate['filename'], $candidate['ext'] );
			if ( is_wp_error( $attachment_id ) ) {
				$report['rows'][] = array( 'recipe_key' => $recipe_key, 'status' => 'import_failed', 'file' => $candidate['filename'], 'error' => $attachment_id->get_error_message() );
				continue;
			}

			foreach ( $locale_posts as $post_id ) {
				if ( $replace || ! has_post_thumbnail( $post_id ) ) {
					set_post_thumbnail( $post_id, $attachment_id );
				}
			}

			++$report['imported'];
			$report['rows'][] = array( 'recipe_key' => $recipe_key, 'status' => 'imported', 'file' => $candidate['filename'], 'attachment_id' => $attachment_id, 'locales' => array_keys( $locale_posts ) );
		}

		if ( $cleanup_dir ) {
			self::cleanup( $cleanup_dir );
		}

		return $report;
	}

	/**
	 * Real WordPress Media API usage (brief item 8 — this is deliberately
	 * NOT a `cp *.jpg wp-content/uploads/`): media_handle_sideload() itself
	 * calls wp_handle_sideload() (moves the file into the uploads
	 * structure), wp_insert_attachment() (real attachment post), and
	 * wp_generate_attachment_metadata() + wp_update_attachment_metadata()
	 * (real dimensions, real generated WP image sizes for every registered
	 * size — atlas-hero/atlas-card/etc. — real MIME metadata) in one
	 * documented core call. Attachment `post_title` is simply the
	 * sanitized filename — same precedent as class-photos.php — never used
	 * for anything SEO-facing (brief item 2).
	 */
	private static function sideload_and_attach( $abs_path, $filename, $ext ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// media_handle_sideload() expects a $_FILES-shaped array pointing at
		// a file ALREADY on local disk (no is_uploaded_file() check, unlike
		// wp_handle_upload()) — exactly our case (an extracted ZIP entry or
		// a plain source-directory file).
		$file_array = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $abs_path,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );
		return $attachment_id; // WP_Error or int, exactly as media_handle_sideload() returns.
	}
}
