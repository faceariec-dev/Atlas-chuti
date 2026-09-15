<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 6, item 7-8: theme-facing helpers for Magazín cross-linking — thin
 * resolvers that turn the stable keys stored on an article (recipe_key/ISO/
 * glossary translation_group, see class-magazine-meta-box.php) into real,
 * current-locale posts to link to. Same "theme = presentation, plugin = data
 * model" split as inc/my-atlas.php's own resolvers; an unresolvable key is
 * silently skipped, never a broken link.
 */

/**
 * "Související recepty" for an article — recipe_key list on the post resolved
 * to real atlas_recipe posts in the current locale (falls back across locales
 * exactly like atlas_chuti_resolve_recipe_key() in inc/my-atlas.php, so a
 * relation set from a CZ article doesn't just vanish before an EN translation
 * of that recipe exists).
 */
function atlas_chuti_magazine_related_recipes( $post_id ) {
	$keys = get_post_meta( $post_id, 'atlas_related_recipe_keys', true );
	$out  = array();
	foreach ( (array) $keys as $key ) {
		$resolved = atlas_chuti_resolve_recipe_key( $key );
		if ( $resolved ) {
			$out[] = $resolved['post'];
		}
	}
	return $out;
}

/**
 * "Související země" for an article — ISO list resolved to real atlas_country posts.
 */
function atlas_chuti_magazine_related_countries( $post_id ) {
	$isos = get_post_meta( $post_id, 'atlas_related_country_iso', true );
	$out  = array();
	foreach ( (array) $isos as $iso ) {
		$resolved = atlas_chuti_resolve_country_iso( $iso );
		if ( $resolved ) {
			$out[] = $resolved['post'];
		}
	}
	return $out;
}

/**
 * "Související pojmy" for an article — glossary translation_group keys resolved
 * to real atlas_glossary posts, current locale first then any supported locale.
 */
function atlas_chuti_magazine_related_glossary( $post_id ) {
	$keys   = get_post_meta( $post_id, 'atlas_related_glossary_keys', true );
	$locale = Atlas_Chuti_I18N::current_locale();
	$out    = array();
	foreach ( (array) $keys as $key ) {
		$post = Atlas_Chuti_I18N::find_by_translation_group( 'atlas_glossary', $key, $locale );
		if ( ! $post ) {
			foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $fallback_locale ) {
				if ( $fallback_locale === $locale ) {
					continue;
				}
				$post = Atlas_Chuti_I18N::find_by_translation_group( 'atlas_glossary', $key, $fallback_locale );
				if ( $post ) {
					break;
				}
			}
		}
		if ( $post ) {
			$out[] = $post;
		}
	}
	return $out;
}

/**
 * Reverse hook (item 8: "recipe detail MŮŽE později zobrazit relevantní
 * články") — a small, capped, locale-scoped meta query, never a scan of the
 * whole Magazín on every recipe page view (item 37's own performance rule).
 * Shown from the recipe sidebar only when it actually resolves to something.
 */
function atlas_chuti_related_magazine_articles_for_recipe_key( $recipe_key, $limit = 3 ) {
	if ( ! $recipe_key ) {
		return array();
	}
	return get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => 'atlas_related_recipe_keys',
					'value'   => '"' . $recipe_key . '"',
					'compare' => 'LIKE',
				),
			),
		)
	);
}
