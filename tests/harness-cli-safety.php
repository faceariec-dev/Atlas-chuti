<?php

define( 'ABSPATH', __DIR__ . '/../' );

function __( $text, $domain = null ) {
	return $text;
}

class WP_CLI {
	public static $successes = array();

	public static function log( $message ) {}

	public static function success( $message ) {
		self::$successes[] = $message;
	}

	public static function error( $message ) {
		throw new RuntimeException( $message );
	}

	public static function add_command( $name, $class ) {}
}

class Atlas_Chuti_JSON_Importer {
	public static $report = array();

	public static function instance() {
		return new self();
	}

	public function run_import_sync( $data, $dry_run ) {
		return self::$report;
	}
}

class Atlas_Chuti_Page_Setup {
	public static $last_write = null;
	public static $report = array( 'rows' => array() );

	public static function instance() {
		return new self();
	}

	public function bootstrap_pages( $write = false ) {
		self::$last_write = $write;
		return self::$report;
	}
}

require __DIR__ . '/../wp-content/plugins/atlas-chuti-core/includes/class-cli.php';

$FAIL = 0;
$TOTAL = 0;

function check( $label, $condition ) {
	global $FAIL, $TOTAL;
	++$TOTAL;
	echo ( $condition ? 'PASS' : 'FAIL' ) . " — {$label}\n";
	if ( ! $condition ) {
		++$FAIL;
	}
}

$tmp = tempnam( sys_get_temp_dir(), 'atlas-cli-' );
file_put_contents( $tmp, '{"recipes":[]}' );

$cli = new Atlas_Chuti_CLI();

Atlas_Chuti_JSON_Importer::$report = array(
	'groups' => array(
		'recipes' => array(
			array(
				'title'   => 'Valid recipe',
				'status'  => 'ok',
				'css'     => 'success',
				'message' => '',
			),
		),
	),
);

WP_CLI::$successes = array();
$threw = false;
try {
	$cli->import( array( $tmp ), array( 'dry-run' => true ) );
} catch ( RuntimeException $e ) {
	$threw = true;
}
check( '1. valid import dry-run succeeds', ! $threw && 1 === count( WP_CLI::$successes ) );

Atlas_Chuti_JSON_Importer::$report['groups']['recipes'][0] = array(
	'title'   => 'Broken recipe',
	'status'  => 'invalid',
	'css'     => 'error',
	'message' => 'recipe_key missing',
);

WP_CLI::$successes = array();
$threw = false;
try {
	$cli->import( array( $tmp ), array( 'dry-run' => true ) );
} catch ( RuntimeException $e ) {
	$threw = true;
}
check( '2. importer css=error makes WP-CLI fail', $threw && 0 === count( WP_CLI::$successes ) );

$cli->bootstrap_pages( array(), array() );
check( '3. bootstrap-pages is read-only by default', false === Atlas_Chuti_Page_Setup::$last_write );

$cli->bootstrap_pages( array(), array( 'write' => true ) );
check( '4. bootstrap-pages writes only with explicit --write', true === Atlas_Chuti_Page_Setup::$last_write );

@unlink( $tmp );

echo "\n--- {$TOTAL} checks, {$FAIL} failing ---\n";
exit( $FAIL > 0 ? 1 : 0 );
