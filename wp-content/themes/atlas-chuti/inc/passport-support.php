<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Kulinářský pas (item 14) is v1 localStorage-only — no accounts. This just
 * supplies the one number/list PHP alone knows: how many countries are actually
 * published on the site right now, broken down by continent, so progress never
 * shows a fabricated denominator (e.g. a hardcoded "195 countries").
 */
function atlas_chuti_continent_totals() {
	$continents = get_terms( array( 'taxonomy' => 'atlas_continent', 'hide_empty' => false ) );
	$out        = array();

	foreach ( $continents as $continent ) {
		$countries = get_posts(
			array(
				'post_type'      => 'atlas_country',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => 'atlas_continent', 'field' => 'term_id', 'terms' => $continent->term_id ) ),
			)
		);

		$flags = array();
		foreach ( $countries as $country_id ) {
			$term_id = Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $country_id );
			$flags[] = array(
				'slug'  => get_post_field( 'post_name', $country_id ),
				'flag'  => get_post_meta( $country_id, 'atlas_flag_emoji', true ),
				'name'  => get_the_title( $country_id ),
			);
		}

		$out[] = array(
			'slug'  => $continent->slug,
			'name'  => $continent->name,
			'total' => count( $countries ),
			'countries' => $flags,
		);
	}

	return $out;
}

/**
 * Total published countries across all continents — used for the homepage's short
 * "X / Y zemí" teaser.
 */
function atlas_chuti_total_countries() {
	return (int) wp_count_posts( 'atlas_country' )->publish;
}
