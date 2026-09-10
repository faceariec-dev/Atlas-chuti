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
			WP_CLI::error( "Soubor nenalezen: $file" );
		}

		$json = file_get_contents( $file );
		$data = json_decode( $json, true );
		if ( null === $data ) {
			WP_CLI::error( 'Neplatný JSON: ' . json_last_error_msg() );
		}

		$dry_run  = isset( $assoc_args['dry-run'] );
		$importer = Atlas_Chuti_JSON_Importer::instance();

		// run_import() is private; use reflection so CLI and admin UI share one implementation.
		$method = new ReflectionMethod( $importer, 'run_import' );
		$method->setAccessible( true );
		$report = $method->invoke( $importer, $data, $dry_run );

		foreach ( $report['groups'] as $type => $rows ) {
			foreach ( $rows as $row ) {
				WP_CLI::log( sprintf( '[%s] %s — %s %s', $type, $row['title'], $row['status'], $row['message'] ? '(' . $row['message'] . ')' : '' ) );
			}
		}

		WP_CLI::success( $dry_run ? 'Dry-run dokončen.' : 'Import dokončen.' );
	}
}

WP_CLI::add_command( 'atlas', 'Atlas_Chuti_CLI' );
