<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Kulinářský pas is v1 localStorage-only — no accounts. This supplies the numbers
 * PHP alone knows, from one master source of truth: every atlas_country post,
 * published OR draft. A country can exist in the master dataset (ISO, name,
 * continent) before its gastro article is finished; the passport's "12 / 195 zemí"
 * denominator must count all of them, not just the ones with a public page, or
 * importing the full country list without writing every article first would make the
 * counter go backwards.
 *
 * Counted by UNIQUE ISO code, never by post count (item 7 of this phase's brief):
 * once a second locale exists, one country ("Italy") is represented by TWO posts in
 * this one database (cs-CZ Itálie + en Italy) — a raw post count would then show 196
 * "countries" instead of 195. The front-end query itself is already locale-scoped by
 * class-i18n.php's central pre_get_posts filter, so today this would already return
 * only cs-CZ posts — the ISO dedup below is an explicit belt-and-braces guarantee
 * that doesn't rely on that filter alone.
 */
function atlas_chuti_continent_totals() {
	$continents      = get_terms( array( 'taxonomy' => 'atlas_continent', 'hide_empty' => false ) );
	$current_locale  = Atlas_Chuti_I18N::current_locale();
	$out             = array();

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

		// One entry per ISO code, preferring the post that matches the current locale
		// for display (name/flag/slug) — see the docblock above.
		$by_iso = array();
		foreach ( $countries as $country_id ) {
			$iso = get_post_meta( $country_id, 'atlas_iso_code', true );
			if ( ! $iso ) {
				continue; // Not a real master-dataset entry without a stable ISO identity.
			}
			$iso    = strtoupper( $iso );
			$locale = Atlas_Chuti_I18N::get_locale( $country_id );
			if ( ! isset( $by_iso[ $iso ] ) || $current_locale === $locale ) {
				$by_iso[ $iso ] = $country_id;
			}
		}

		$flags = array();
		foreach ( $by_iso as $iso => $country_id ) {
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
 * Total countries known to the master dataset (publish + draft), counted by unique
 * ISO code — see the docblock above for why draft counts and why ISO, not post count.
 */
function atlas_chuti_total_countries() {
	$ids  = get_posts(
		array(
			'post_type'      => 'atlas_country',
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft' ),
			'fields'         => 'ids',
		)
	);
	$isos = array();
	foreach ( $ids as $id ) {
		$iso = get_post_meta( $id, 'atlas_iso_code', true );
		if ( $iso ) {
			$isos[ strtoupper( $iso ) ] = true;
		}
	}
	return count( $isos );
}
