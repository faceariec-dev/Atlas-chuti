<?php
/**
 * KROK 9, item 54: a lightweight, READ-ONLY content quality gate for future
 * production-data batches — classifies findings as ERROR (the real importer,
 * class-json-importer.php, will hard-reject the item) / WARNING (imports
 * fine but is a real content-quality risk — thin SEO, missing image, etc.) /
 * INFO (minor/cosmetic, non-blocking). Deliberately NO pseudo SEO score
 * (never "87/100" — the brief's own explicit prohibition, item 54).
 *
 * This never imports, never writes to production-data/, never touches a
 * WordPress database or the live site — it only reads the batch JSON files
 * and reports. It intentionally reuses the SAME two dependency-free plugin
 * classes the real importer/theme already use for unit/tag validation
 * (Atlas_Chuti_Units::normalize(), Atlas_Chuti_Taxonomy_Labels::keys()) so
 * this tool can never silently drift into a second, incompatible idea of
 * "valid unit" or "known tag".
 *
 * Usage: `php tools/content-quality-gate.php [path-to-batch-dir]`
 * (defaults to production-data/europe-1/). Exit code is always 0 — this is
 * a report, not a CI gate that blocks anything by itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // only so the two required classes' own top-of-file guard passes; nothing else in this script touches WordPress.
}
require_once __DIR__ . '/../wp-content/plugins/atlas-chuti-core/includes/class-units.php';
require_once __DIR__ . '/../wp-content/plugins/atlas-chuti-core/includes/class-taxonomy-labels.php';

$dir = $argv[1] ?? __DIR__ . '/../production-data/europe-1';
$dir = rtrim( $dir, '/' );

if ( ! is_dir( $dir ) ) {
	fwrite( STDERR, "Directory not found: {$dir}\n" );
	exit( 1 );
}

$counts = array( 'ERROR' => 0, 'WARNING' => 0, 'INFO' => 0 );

function report( $level, $entity, $identifier, $message ) {
	global $counts;
	++$counts[ $level ];
	printf( "%-7s [%s %s] %s\n", $level, $entity, $identifier, $message );
}

/**
 * Recipe slugs/glossary slugs seen across the WHOLE batch, gathered in a
 * first pass, so "broken relation" checks work across files (a recipe in
 * file 03 may legitimately relate to one in file 05).
 */
$known_recipe_slugs   = array();
$known_glossary_slugs = array();
$known_country_slugs  = array();

$files = glob( $dir . '/*.json' );
sort( $files );

$parsed = array();
foreach ( $files as $file ) {
	$json = json_decode( file_get_contents( $file ), true );
	if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
		report( 'ERROR', 'FILE', basename( $file ), 'invalid JSON: ' . json_last_error_msg() );
		continue;
	}
	$parsed[ $file ] = $json;
	foreach ( ( $json['recipes'] ?? array() ) as $r ) {
		if ( ! empty( $r['slug'] ) ) {
			$known_recipe_slugs[ $r['slug'] ] = true;
		}
	}
	foreach ( ( $json['glossary'] ?? array() ) as $g ) {
		if ( ! empty( $g['slug'] ) ) {
			$known_glossary_slugs[ $g['slug'] ] = true;
		}
	}
	foreach ( ( $json['countries'] ?? array() ) as $c ) {
		if ( ! empty( $c['slug'] ) ) {
			$known_country_slugs[ $c['slug'] ] = true;
		}
		if ( ! empty( $c['iso_code'] ) ) {
			$known_country_slugs[ $c['iso_code'] ] = true;
		}
	}
}

$known_tag_keys = Atlas_Chuti_Taxonomy_Labels::keys( 'atlas_recipe_tag' );

foreach ( $parsed as $file => $json ) {
	$label = basename( $file );

	foreach ( ( $json['recipes'] ?? array() ) as $r ) {
		$id = $r['slug'] ?? ( $r['title'] ?? '(untitled)' );

		// ERROR — the real importer will hard-reject these.
		if ( empty( $r['recipe_key'] ) ) {
			report( 'ERROR', 'recipe', $id, 'missing recipe_key — class-json-importer.php will reject this item outright (no translation_group/slug fallback, by design)' );
		}
		if ( empty( $r['locale'] ) ) {
			report( 'ERROR', 'recipe', $id, 'missing locale' );
		}
		if ( empty( $r['title'] ) ) {
			report( 'ERROR', 'recipe', $id, 'missing title' );
		}
		if ( empty( $r['ingredients'] ) || ! is_array( $r['ingredients'] ) ) {
			report( 'ERROR', 'recipe', $id, 'missing/empty ingredients' );
		}
		if ( empty( $r['steps'] ) || ! is_array( $r['steps'] ) ) {
			report( 'ERROR', 'recipe', $id, 'missing/empty steps' );
		}

		// WARNING — imports, but a real content/SEO quality risk.
		$excerpt = trim( (string) ( $r['excerpt'] ?? '' ) );
		if ( '' === $excerpt ) {
			report( 'WARNING', 'recipe', $id, 'no perex/excerpt — class-seo.php falls back to atlas_intro/site description' );
		} elseif ( mb_strlen( $excerpt ) < 60 ) {
			report( 'WARNING', 'recipe', $id, sprintf( 'perex is very short (%d chars) — weak meta description/Discover snippet', mb_strlen( $excerpt ) ) );
		}
		if ( empty( trim( (string) ( $r['about'] ?? '' ) ) ) ) {
			report( 'WARNING', 'recipe', $id, 'missing "O receptu" (about) text' );
		}
		if ( is_array( $r['ingredients'] ?? null ) && count( $r['ingredients'] ) < 3 ) {
			report( 'WARNING', 'recipe', $id, 'fewer than 3 ingredients — unusually thin recipe' );
		}
		if ( is_array( $r['steps'] ?? null ) && count( $r['steps'] ) < 2 ) {
			report( 'WARNING', 'recipe', $id, 'fewer than 2 instruction steps' );
		}
		foreach ( ( $r['ingredients'] ?? array() ) as $ing ) {
			$unit = trim( (string) ( $ing['unit'] ?? '' ) );
			if ( '' !== $unit && null === Atlas_Chuti_Units::normalize( $unit ) ) {
				report( 'WARNING', 'recipe', $id, sprintf( 'ingredient "%s" has an unrecognized unit "%s" (Atlas_Chuti_Units::normalize() returns null — will still import as free text, but won\'t get a localized label)', $ing['display_name'] ?? '?', $unit ) );
			}
		}
		foreach ( ( $r['tags'] ?? array() ) as $tag ) {
			if ( ! in_array( $tag, $known_tag_keys, true ) ) {
				report( 'WARNING', 'recipe', $id, sprintf( 'unknown controlled tag "%s" — not in Atlas_Chuti_Taxonomy_Labels\' closed atlas_recipe_tag catalog, importer will skip it', $tag ) );
			}
		}
		foreach ( ( $r['related_recipes'] ?? array() ) as $rel ) {
			if ( ! isset( $known_recipe_slugs[ $rel ] ) ) {
				report( 'WARNING', 'recipe', $id, sprintf( 'related_recipes references unknown slug "%s"', $rel ) );
			}
		}
		foreach ( ( $r['related_glossary'] ?? array() ) as $rel ) {
			if ( ! isset( $known_glossary_slugs[ $rel ] ) ) {
				report( 'WARNING', 'recipe', $id, sprintf( 'related_glossary references unknown slug "%s"', $rel ) );
			}
		}

		// INFO — minor/cosmetic, non-blocking.
		if ( ! array_key_exists( 'image', $r ) && ! array_key_exists( 'photo', $r ) ) {
			report( 'INFO', 'recipe', $id, 'no image reference in this batch — recipe will render with fallback art and no og:image/Recipe-schema image until a photo is attached separately' );
		}
		if ( empty( $r['tips'] ) ) {
			report( 'INFO', 'recipe', $id, 'no tips' );
		}
		if ( empty( $r['tags'] ?? array() ) ) {
			report( 'INFO', 'recipe', $id, 'no controlled recipe tags assigned' );
		}
	}

	foreach ( ( $json['countries'] ?? array() ) as $c ) {
		$id = $c['slug'] ?? ( $c['iso_code'] ?? '(untitled)' );
		if ( empty( $c['iso_code'] ) ) {
			report( 'ERROR', 'country', $id, 'missing iso_code' );
		}
		if ( empty( $c['locale'] ) ) {
			report( 'ERROR', 'country', $id, 'missing locale' );
		}
		if ( empty( trim( (string) ( $c['intro'] ?? '' ) ) ) ) {
			report( 'WARNING', 'country', $id, 'missing intro' );
		}
		if ( ! array_key_exists( 'image', $c ) && ! array_key_exists( 'photo', $c ) ) {
			report( 'INFO', 'country', $id, 'no image reference in this batch' );
		}
	}

	foreach ( ( $json['glossary'] ?? array() ) as $g ) {
		$id = $g['slug'] ?? ( $g['title'] ?? '(untitled)' );
		if ( empty( $g['slug'] ) ) {
			report( 'ERROR', 'glossary', $id, 'missing slug' );
		}
		if ( empty( $g['locale'] ) ) {
			report( 'ERROR', 'glossary', $id, 'missing locale' );
		}
		if ( empty( trim( (string) ( $g['short_definition'] ?? '' ) ) ) ) {
			report( 'WARNING', 'glossary', $id, 'missing short_definition' );
		}
	}
}

echo "\n--- {$counts['ERROR']} ERROR, {$counts['WARNING']} WARNING, {$counts['INFO']} INFO ---\n";
echo "(This is a report only — nothing was imported or modified. No pseudo score is produced by design.)\n";
