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
