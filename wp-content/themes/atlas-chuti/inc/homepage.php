<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Homepage section queries (item 18 of the brief). Every section prefers an
 * editor's manual pick (a checkbox on the Země/Recept meta box) and falls back to a
 * sensible automatic choice — seeded by the current date so it's stable for the
 * whole day (friendlier to page caching than pure random on every request).
 */

function atlas_chuti_day_seed() {
	return (int) gmdate( 'z' ); // day of year, 0-365
}

/**
 * "Dnes ochutnejte" — up to 3 countries: manually flagged ones first, topped up
 * with a day-seeded random pick from the rest.
 */
function atlas_chuti_home_today_countries( $limit = 3 ) {
	$featured = get_posts(
		array(
			'post_type'      => 'atlas_country',
			'posts_per_page' => $limit,
			'meta_key'       => 'atlas_featured_today',
			'meta_value'     => '1',
		)
	);

	if ( count( $featured ) >= $limit ) {
		return array_slice( $featured, 0, $limit );
	}

	$exclude = wp_list_pluck( $featured, 'ID' );
	$rest    = get_posts(
		array(
			'post_type'      => 'atlas_country',
			'posts_per_page' => -1,
			'post__not_in'   => $exclude,
			'fields'         => 'ids',
		)
	);
	if ( $rest ) {
		shuffle_by_seed( $rest, atlas_chuti_day_seed() );
		$needed = $limit - count( $featured );
		foreach ( array_slice( $rest, 0, $needed ) as $id ) {
			$featured[] = get_post( $id );
		}
	}
	return $featured;
}

/**
 * "Oblíbené kuchyně" chips — manually flagged countries, or the most recent ones.
 */
function atlas_chuti_home_favorite_cuisines( $limit = 6 ) {
	$featured = get_posts(
		array(
			'post_type'      => 'atlas_country',
			'posts_per_page' => $limit,
			'meta_key'       => 'atlas_featured_cuisine',
			'meta_value'     => '1',
		)
	);
	if ( count( $featured ) >= $limit ) {
		return $featured;
	}
	$exclude = wp_list_pluck( $featured, 'ID' );
	$rest    = get_posts(
		array(
			'post_type'      => 'atlas_country',
			'posts_per_page' => $limit - count( $featured ),
			'post__not_in'   => $exclude,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	return array_merge( $featured, $rest );
}

/**
 * "Co dnes uvařit?" — one manually flagged recipe, else a day-seeded pick.
 */
function atlas_chuti_home_featured_recipe() {
	$featured = get_posts(
		array(
			'post_type'      => 'atlas_recipe',
			'posts_per_page' => 1,
			'meta_key'       => 'atlas_featured_cook_today',
			'meta_value'     => '1',
		)
	);
	if ( $featured ) {
		return $featured[0];
	}
	$ids = get_posts( array( 'post_type' => 'atlas_recipe', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	if ( ! $ids ) {
		return null;
	}
	sort( $ids );
	$index = atlas_chuti_day_seed() % count( $ids );
	return get_post( $ids[ $index ] );
}

function atlas_chuti_home_new_recipes( $limit = 4 ) {
	return get_posts( array( 'post_type' => 'atlas_recipe', 'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC' ) );
}

function atlas_chuti_home_glossary_preview( $limit = 3 ) {
	return get_posts( array( 'post_type' => 'atlas_glossary', 'posts_per_page' => $limit, 'orderby' => 'rand' ) );
}

/**
 * Resolves the Země CPT post for Czechia (ISO "CZ") in the current locale, or null
 * if that country doesn't exist yet in this installation — the "Česká kuchyně"
 * homepage block (KROK 1, 6.4) must gracefully hide rather than assume the ID.
 */
function atlas_chuti_home_czech_country() {
	if ( ! class_exists( 'Atlas_Chuti_Country_Sync' ) ) {
		return null;
	}
	$term_id = Atlas_Chuti_Country_Sync::find_term_id_by_iso( 'CZ' );
	if ( ! $term_id ) {
		return null;
	}
	$post_id = Atlas_Chuti_Country_Sync::get_country_post_for_term( $term_id );
	return $post_id ? get_post( $post_id ) : null;
}

/**
 * "Co se vaří ve světě" (6.5): one dominant country (manually flagged "featured
 * cuisine" first, else the day-seeded pick already used for the chips row) with its
 * best recipe, plus a handful of smaller recipes each from a DIFFERENT country, so
 * the block never repeats the same cuisine twice. Real data only — if there isn't
 * enough variety yet, the arrays come back short and front-page.php's own
 * conditionals decide whether the block renders at all.
 */
function atlas_chuti_home_world_picks( $limit_others = 3 ) {
	$cuisines = atlas_chuti_home_favorite_cuisines( 1 );
	if ( ! $cuisines ) {
		return array(
			'dominant_country' => null,
			'dominant_recipe'  => null,
			'others'           => array(),
		);
	}
	$dominant_country = $cuisines[0];
	$dominant_recipes = atlas_chuti_get_recipes_for_country( $dominant_country->ID, 1 );
	$dominant_recipe  = $dominant_recipes ? $dominant_recipes[0] : null;

	$pool = get_posts(
		array(
			'post_type'      => 'atlas_recipe',
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post__not_in'   => $dominant_recipe ? array( $dominant_recipe->ID ) : array(),
		)
	);

	$others     = array();
	$seen_iso   = array( strtoupper( (string) get_post_meta( $dominant_country->ID, 'atlas_iso_code', true ) ) );
	foreach ( $pool as $recipe ) {
		if ( count( $others ) >= $limit_others ) {
			break;
		}
		$country = atlas_chuti_get_recipe_primary_country( $recipe->ID );
		if ( ! $country ) {
			continue;
		}
		$iso = strtoupper( (string) get_post_meta( $country->ID, 'atlas_iso_code', true ) );
		if ( in_array( $iso, $seen_iso, true ) ) {
			continue;
		}
		$seen_iso[] = $iso;
		$others[]   = array( 'recipe' => $recipe, 'country' => $country );
	}

	return array(
		'dominant_country' => $dominant_country,
		'dominant_recipe'  => $dominant_recipe,
		'others'           => $others,
	);
}

/**
 * Magazín preview (6.8): standard WordPress `post`s — no dedicated CPT exists for
 * the magazine yet (by design; it's out of scope for KROK 1). Returns an empty
 * array until real articles are published, so the caller can hide the block
 * instead of ever inventing placeholder articles.
 */
function atlas_chuti_home_magazine_posts( $limit = 3 ) {
	return get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $limit ) );
}

/**
 * Deterministic shuffle so the same day always produces the same order (good for caching)
 * without needing a persistent "shown today" flag in the database.
 */
function shuffle_by_seed( &$array, $seed ) {
	mt_srand( $seed );
	usort(
		$array,
		function () {
			return mt_rand( -1, 1 );
		}
	);
	mt_srand(); // reseed randomly again for anything else on the request
}
