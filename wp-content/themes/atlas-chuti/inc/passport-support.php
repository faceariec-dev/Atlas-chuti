<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Kulinářský pas (item 14/18 of this phase's brief) is v1 localStorage-only — no
 * accounts. This supplies the numbers PHP alone knows, from one master source of
 * truth: every atlas_country post, published OR draft. A country can exist in the
 * master dataset (ISO, name, continent — see item 15) before its gastro article is
 * finished; the passport's "12 / 195 zemí" denominator must count all of them, not
 * just the ones with a public page, or importing the full country list without
 * writing every article first would make the counter go backwards.
 */
function atlas_chuti_continent_totals() {
	$continents = get_terms( array( 'taxonomy' => 'atlas_continent', 'hide_empty' => false ) );
	$out        = array();

	foreach ( $continents as $continent ) {
		$countries = get_posts(
			array(
				'post_type'      => 'atlas_country',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft' ),
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => 'atlas_continent', 'field' => 'term_id', 'terms' => $continent->term_id ) ),
			)
		);

		$flags = array();
		foreach ( $countries as $country_id ) {
			$iso = get_post_meta( $country_id, 'atlas_iso_code', true );
			if ( ! $iso ) {
				continue; // Not a real master-dataset entry without a stable ISO identity.
			}
			$flags[] = array(
				'iso'  => $iso,
				'slug' => 'publish' === get_post_status( $country_id ) ? get_post_field( 'post_name', $country_id ) : '',
				'flag' => get_post_meta( $country_id, 'atlas_flag_emoji', true ),
				'name' => get_the_title( $country_id ),
			);
		}

		$out[] = array(
			'slug'      => $continent->slug,
			'name'      => $continent->name,
			'total'     => count( $flags ),
			'countries' => $flags,
		);
	}

	return $out;
}

/**
 * Total countries known to the master dataset (publish + draft) — used for the
 * homepage's short "X / Y zemí" teaser. See the docblock above for why draft counts.
 */
function atlas_chuti_total_countries() {
	$counts = wp_count_posts( 'atlas_country' );
	return (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 );
}
