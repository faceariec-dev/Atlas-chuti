<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp atlas import <file>` — the same importer the admin UI uses, from the command
 * line. Handy for loading /sample-data on a fresh install without clicking through
 * wp-admin, and for scripted deploys.
 */
class Atlas_Chuti_CLI {

	/**
	 * Imports a JSON file (countries/recipes/glossary/ingredients).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON file.
	 *
	 * [--dry-run]
	 * : Preview only, write nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atlas import sample-data/batch-import-sample.json
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc_args ) {
		list( $file ) = $args;
		if ( ! file_exists( $file ) ) {
			/* translators: %s: file path */
			WP_CLI::error( sprintf( __( 'Soubor nenalezen: %s', 'atlas-chuti' ), $file ) );
		}

		$json = file_get_contents( $file );
		$data = json_decode( $json, true );
		if ( null === $data ) {
			/* translators: %s: JSON parser error message */
			WP_CLI::error( sprintf( __( 'Neplatný JSON: %s', 'atlas-chuti' ), json_last_error_msg() ) );
		}

		$dry_run  = isset( $assoc_args['dry-run'] );
		$importer = Atlas_Chuti_JSON_Importer::instance();
		$report   = $importer->run_import_sync( $data, $dry_run );

		foreach ( $report['groups'] as $type => $rows ) {
			foreach ( $rows as $row ) {
				WP_CLI::log( sprintf( '[%s] %s — %s %s', $type, $row['title'], $row['status'], $row['message'] ? '(' . $row['message'] . ')' : '' ) );
			}
		}

		WP_CLI::success( $dry_run ? __( 'Dry-run dokončen.', 'atlas-chuti' ) : __( 'Import dokončen.', 'atlas-chuti' ) );
	}

	/**
	 * CHECKPOINT 10C: generates the recipe-images manifest (CSV + JSON +
	 * missing-recipe-images.csv) — one row per recipe_key, deterministic,
	 * read-only (never writes to Media Library or to any recipe post).
	 *
	 * ## OPTIONS
	 *
	 * [--source-dir=<dir>]
	 * : Optional directory of candidate image files to cross-check against
	 *   the manifest (adds invalid_format/invalid_dimensions/duplicate
	 *   statuses for files actually found there). Without it, status is
	 *   only ever 'present' or 'missing', based on the current Media
	 *   Library state alone.
	 *
	 * [--output-dir=<dir>]
	 * : Where to write the manifest files. Default: generated/image-manifests
	 *   relative to the WordPress root.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atlas image-manifest
	 *     wp atlas image-manifest --source-dir=/tmp/recipe-photos
	 *
	 * @subcommand image-manifest
	 * @when after_wp_load
	 */
	public function image_manifest( $args, $assoc_args ) {
		$source_dir = $assoc_args['source-dir'] ?? null;
		$output_dir = $assoc_args['output-dir'] ?? ABSPATH . 'generated/image-manifests';

		$manifest = Atlas_Chuti_Recipe_Image_Pipeline::build_manifest( $source_dir );
		$paths    = Atlas_Chuti_Recipe_Image_Pipeline::write_manifest_files( $manifest, $output_dir );

		$by_status = array_count_values( wp_list_pluck( $manifest['rows'], 'status' ) );
		foreach ( $by_status as $status => $count ) {
			WP_CLI::log( sprintf( '%s: %d', $status, $count ) );
		}
		if ( $manifest['unmatched_files'] ) {
			WP_CLI::log( sprintf( 'unmatched_files: %d (%s)', count( $manifest['unmatched_files'] ), implode( ', ', $manifest['unmatched_files'] ) ) );
		}
		WP_CLI::log( sprintf( 'CSV: %s', $paths['csv'] ) );
		WP_CLI::log( sprintf( 'JSON: %s', $paths['json'] ) );
		WP_CLI::log( sprintf( 'Missing-only CSV: %s', $paths['missing_csv'] ) );
		WP_CLI::success( sprintf( /* translators: %d: number of recipes in the manifest */ __( 'Manifest vygenerován pro %d receptů.', 'atlas-chuti' ), count( $manifest['rows'] ) ) );
	}

	/**
	 * CHECKPOINT 10C: imports a batch of recipe photos from a ZIP file or a
	 * plain directory, paired to recipes by `recipe_key` (never by title,
	 * slug, or file order — see class-recipe-image-pipeline.php). Always
	 * validates first; writes to the Media Library / sets featured images
	 * only when --dry-run is NOT passed.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Path to a .zip file or a directory of {recipe_key}.{ext} images.
	 *
	 * [--dry-run]
	 * : Validate and report pairing only — writes nothing to the Media
	 *   Library or to any recipe post. This is always the first,
	 *   mandatory staging step (see the checkpoint report's staging
	 *   checklist).
	 *
	 * [--replace]
	 * : Also overwrite a recipe's EXISTING featured image when a new,
	 *   valid file is supplied for it. Without this flag, a recipe that
	 *   already has a featured image is always skipped, never silently
	 *   overwritten.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atlas image-import recipe-images.zip --dry-run
	 *     wp atlas image-import recipe-images.zip
	 *     wp atlas image-import recipe-images.zip --replace
	 *
	 * @subcommand image-import
	 * @when after_wp_load
	 */
	public function image_import( $args, $assoc_args ) {
		list( $source ) = $args;
		if ( ! file_exists( $source ) ) {
			/* translators: %s: source path */
			WP_CLI::error( sprintf( __( 'Zdroj nenalezen: %s', 'atlas-chuti' ), $source ) );
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$replace = isset( $assoc_args['replace'] );

		$report = Atlas_Chuti_Recipe_Image_Pipeline::import_batch(
			$source,
			array( 'dry_run' => $dry_run, 'replace' => $replace )
		);

		if ( isset( $report['error'] ) ) {
			WP_CLI::error( $report['error']->get_error_message() );
		}

		WP_CLI::log( ( $dry_run ? '[DRY-RUN] ' : '' ) . sprintf( 'Imported: %d', $report['imported'] ) );
		WP_CLI::log( sprintf( 'Skipped existing: %d', $report['skipped_existing'] ) );
		WP_CLI::log( sprintf( 'Missing recipe: %d', $report['missing_recipe'] ) );
		WP_CLI::log( sprintf( 'Invalid dimensions: %d', $report['invalid_dimensions'] ) );
		WP_CLI::log( sprintf( 'Invalid format: %d', $report['invalid_format'] ) );
		WP_CLI::log( sprintf( 'Duplicates: %d', $report['duplicates'] ) );
		WP_CLI::log( sprintf( 'Unmatched files: %d', $report['unmatched_files'] ) );

		$report_path = trailingslashit( ABSPATH . 'generated/image-manifests' ) . 'image-import-report.json';
		wp_mkdir_p( dirname( $report_path ) );
		file_put_contents( $report_path, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		WP_CLI::log( sprintf( 'Machine-readable report: %s', $report_path ) );

		WP_CLI::success( $dry_run ? __( 'Dry-run importu obrázků dokončen, nic nebylo zapsáno.', 'atlas-chuti' ) : __( 'Import obrázků dokončen.', 'atlas-chuti' ) );
	}
}

WP_CLI::add_command( 'atlas', 'Atlas_Chuti_CLI' );
