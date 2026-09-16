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
// CHECKPOINT 10C.1: class-i18n.php is ALSO dependency-free at the static-method
// level used here (normalize_locale()/DEFAULT_LOCALE are pure string/constant
// lookups — the only WP calls in the class live inside __construct()/instance
// methods this script never touches) — same "one source of truth, never a
// second drifting copy" reasoning as the two requires above. This is what lets
// the relation checks below locale-scope exactly the way
// class-json-importer.php's resolve_item_locale() does, instead of guessing.
require_once __DIR__ . '/../wp-content/plugins/atlas-chuti-core/includes/class-i18n.php';

/**
 * CHECKPOINT 10C.1: the real importer's relation-validity contract
 * (class-json-importer.php's build_planned_index() / reference_is_valid() /
 * stable_key_for() / resolve_recipe_key()) is reproduced here, NOT copied
 * blindly — this tool has no WordPress DB to query (it never did, by
 * design), so the only part of that contract that applies to it is the
 * "planned batch index" half: every relation in a fresh batch is checked
 * against the OTHER items present in that same batch, exactly the way
 * build_planned_index() + reference_is_valid() do for a dry-run.
 *
 * The identity used is NEVER `slug` (the bug this checkpoint fixes — slug
 * is a locale-specific URL segment, not a relation identity for either
 * post type):
 *   - atlas_recipe  -> recipe_key ONLY (resolve_recipe_key()/stable_key_for()
 *     — deliberately no translation_group/slug fallback, matching KROK 3B's
 *     explicit removal of exactly that fallback from the real importer).
 *   - atlas_glossary -> translation_group, falling back to slug ONLY when
 *     translation_group is genuinely absent (stable_key_for()'s own
 *     fallback for every non-country/non-ingredient/non-recipe post type).
 * Both are scoped by locale (class-json-importer.php's planned_key()) —
 * a reference inside a cs-CZ item only ever resolves to a cs-CZ target,
 * exactly like the real importer.
 */

/**
 * A dependency-free stand-in for WordPress core's sanitize_title() — NOT
 * reused directly because sanitize_title() lives in wp-includes/formatting.php
 * and pulls in a wide slice of WordPress core (remove_accents(),
 * apply_filters(), seems_utf8(), ...) that this deliberately WP-DB-free tool
 * has never depended on and shouldn't start now just for this one call.
 * Safe for THIS tool's actual inputs: recipe_key/translation_group/slug
 * values in this project's production data are already validated,
 * already-clean lowercase-alnum-with-separators strings (see
 * class-json-importer.php's is_valid_stable_key()) — this only needs to
 * normalize casing/whitespace/separators the same way sanitize_title()
 * would for that already-clean shape, not handle arbitrary raw title text.
 */
function atlas_quality_gate_sanitize_title( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( $value, '-' );
}

/**
 * class-json-importer.php's resolve_item_locale(): normalize_locale() first
 * (accepts both "cs-CZ" and "cs_CZ" spellings, see class-i18n.php), falling
 * back to DEFAULT_LOCALE for a missing/unrecognized value — never a hard
 * failure, exactly like the real importer (a recipe with a missing locale is
 * already its own separate ERROR below, this never has to guess "wrong").
 */
function atlas_quality_gate_resolve_locale( $item ) {
	if ( ! empty( $item['locale'] ) ) {
		$normalized = Atlas_Chuti_I18N::normalize_locale( $item['locale'] );
		if ( $normalized ) {
			return $normalized;
		}
	}
	return Atlas_Chuti_I18N::DEFAULT_LOCALE;
}

/**
 * class-json-importer.php's planned_key(): the SAME "locale|stable_key"
 * composite key both the known-identity index and every reference lookup
 * below use — a reference is only ever valid against a target in its own
 * locale.
 */
function atlas_quality_gate_planned_key( $locale, $stable_key ) {
	return $locale . '|' . $stable_key;
}

/**
 * class-json-importer.php's resolve_recipe_key(): recipe_key is read
 * verbatim — no slug/translation_group fallback, by design (KROK 3B).
 */
function atlas_quality_gate_recipe_key( $item ) {
	return trim( (string) ( $item['recipe_key'] ?? '' ) );
}

/**
 * class-json-importer.php's stable_key_for() for the generic (glossary)
 * branch: translation_group, falling back to slug only when
 * translation_group is genuinely absent.
 */
function atlas_quality_gate_glossary_key( $item, $slug ) {
	return ! empty( $item['translation_group'] ) ? $item['translation_group'] : $slug;
}

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
 * CHECKPOINT 10C.1: known IDENTITIES (recipe_key / translation_group-or-slug),
 * locale-scoped exactly like class-json-importer.php's build_planned_index(),
 * gathered in a first pass so "broken relation" checks work across files (a
 * recipe in file 03 may legitimately relate to one in file 05). Keyed by
 * `locale|stable_key` (atlas_quality_gate_planned_key()) — NOT by slug, which
 * was this tool's bug (see the report's section A/C): slug is a
 * locale-specific URL segment, never the identity either post type's
 * relations actually resolve by.
 *
 * $recipe_key_counts_by_planned_key tallies same-batch collisions, mirroring
 * build_planned_index()'s own `_duplicate_recipe_keys` — the real importer
 * treats a same-locale recipe_key collision as a hard ERROR (KROK 3B
 * hardening), so this tool now does too.
 */
$known_recipe_keys                = array();
$known_glossary_keys              = array();
$known_country_slugs              = array();
$recipe_key_counts_by_planned_key = array();

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
		$recipe_key = atlas_quality_gate_recipe_key( $r );
		if ( '' === $recipe_key ) {
			continue; // Already its own ERROR below (missing recipe_key) — nothing to index.
		}
		$locale      = atlas_quality_gate_resolve_locale( $r );
		$planned_key = atlas_quality_gate_planned_key( $locale, atlas_quality_gate_sanitize_title( $recipe_key ) );
		$known_recipe_keys[ $planned_key ]                = true;
		$recipe_key_counts_by_planned_key[ $planned_key ] = ( $recipe_key_counts_by_planned_key[ $planned_key ] ?? 0 ) + 1;
	}
	foreach ( ( $json['glossary'] ?? array() ) as $g ) {
		$key = atlas_quality_gate_glossary_key( $g, $g['slug'] ?? '' );
		if ( '' === $key ) {
			continue; // Already its own ERROR below (missing slug) — nothing to index.
		}
		$locale = atlas_quality_gate_resolve_locale( $g );
		$known_glossary_keys[ atlas_quality_gate_planned_key( $locale, atlas_quality_gate_sanitize_title( $key ) ) ] = true;
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

$duplicate_recipe_keys = array_filter( $recipe_key_counts_by_planned_key, fn( $count ) => $count > 1 );

$known_tag_keys = Atlas_Chuti_Taxonomy_Labels::keys( 'atlas_recipe_tag' );

foreach ( $parsed as $file => $json ) {
	$label = basename( $file );

	foreach ( ( $json['recipes'] ?? array() ) as $r ) {
		$id = $r['slug'] ?? ( $r['title'] ?? '(untitled)' );

		// ERROR — the real importer will hard-reject these.
		if ( empty( $r['recipe_key'] ) ) {
			report( 'ERROR', 'recipe', $id, 'missing recipe_key — class-json-importer.php will reject this item outright (no translation_group/slug fallback, by design)' );
		} else {
			// CHECKPOINT 10C.1: same-batch recipe_key collision — the real
			// importer's own KROK 3B hardening (build_planned_index()'s
			// `_duplicate_recipe_keys`) turns this into a hard error, so this
			// tool now matches that instead of silently letting two recipe
			// objects fight over one identity.
			$this_locale = atlas_quality_gate_resolve_locale( $r );
			$this_key    = atlas_quality_gate_planned_key( $this_locale, atlas_quality_gate_sanitize_title( atlas_quality_gate_recipe_key( $r ) ) );
			if ( isset( $duplicate_recipe_keys[ $this_key ] ) ) {
				report( 'ERROR', 'recipe', $id, sprintf( 'recipe_key "%s" is duplicated by another recipe in this same batch/locale — class-json-importer.php will hard-reject it (recipe_key must be unique per locale)', $r['recipe_key'] ) );
			}
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
		// CHECKPOINT 10C.1: matches class-json-importer.php's real relation
		// contract — related_recipes resolves via recipe_key, related_glossary
		// via translation_group (slug fallback only if translation_group is
		// absent), both scoped to THIS recipe's own locale. Never slug for
		// recipes (the bug this checkpoint fixes) and never a new title/slug
		// fallback introduced for recipes, since the real importer doesn't
		// have one either.
		$recipe_locale = atlas_quality_gate_resolve_locale( $r );
		foreach ( ( $r['related_recipes'] ?? array() ) as $rel ) {
			$rel_key = atlas_quality_gate_planned_key( $recipe_locale, atlas_quality_gate_sanitize_title( $rel ) );
			if ( ! isset( $known_recipe_keys[ $rel_key ] ) ) {
				report( 'WARNING', 'recipe', $id, sprintf( 'related_recipes references unknown recipe_key "%s" (locale %s)', $rel, $recipe_locale ) );
			}
		}
		foreach ( ( $r['related_glossary'] ?? array() ) as $rel ) {
			$rel_key = atlas_quality_gate_planned_key( $recipe_locale, atlas_quality_gate_sanitize_title( $rel ) );
			if ( ! isset( $known_glossary_keys[ $rel_key ] ) ) {
				report( 'WARNING', 'recipe', $id, sprintf( 'related_glossary references unknown translation_group/slug "%s" (locale %s)', $rel, $recipe_locale ) );
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
