<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central fallback-image system (frontend redesign follow-up brief, "fallback
 * images" item): a real featured image ALWAYS wins — every caller below only
 * ever reaches these helpers after it has already checked has_post_thumbnail()/
 * a real attachment and found none. These illustrations are deliberately
 * generic, locally-drawn SVGs shipped with the theme (assets/images/fallbacks/),
 * never a downloaded photo — so there's no copyright risk and nothing is ever
 * presented as a real photo of a specific dish or country. Once an admin sets a
 * real image, these stop showing automatically — no code change needed.
 */

/**
 * Which fallback file (if any) applies to $context. Countries get a
 * continent-specific illustration when one exists, else the generic
 * country-default; everything else maps to exactly one file.
 */
function atlas_chuti_fallback_image_slug( $context, $continent_slug = '' ) {
	static $known_continents = array( 'europe', 'asia', 'africa', 'north-america', 'south-america', 'oceania' );

	if ( 'country' === $context ) {
		$continent_slug = sanitize_key( $continent_slug );
		if ( $continent_slug && in_array( $continent_slug, $known_continents, true ) ) {
			$continent_slug_fallback = 'continent-' . $continent_slug;
			if ( file_exists( atlas_chuti_fallback_image_path( $continent_slug_fallback ) ) ) {
				return $continent_slug_fallback;
			}
		}
		return 'country-default';
	}

	if ( 'continent' === $context ) {
		$continent_slug = sanitize_key( $continent_slug );
		return ( $continent_slug && in_array( $continent_slug, $known_continents, true ) ) ? 'continent-' . $continent_slug : '';
	}

	if ( 'recipe' === $context ) {
		return 'recipe-default';
	}

	if ( 'homepage-hero' === $context ) {
		return 'homepage-hero';
	}

	return '';
}

function atlas_chuti_fallback_image_path( $slug ) {
	return $slug ? ATLAS_THEME_DIR . '/assets/images/fallbacks/' . $slug . '.svg' : '';
}

function atlas_chuti_fallback_image_url( $slug ) {
	return $slug ? ATLAS_THEME_URL . '/assets/images/fallbacks/' . $slug . '.svg' : '';
}

/**
 * Renders a fallback <img>, or '' when no matching file ships with the theme —
 * callers fall through to the existing .placeholder-media block in that case,
 * so a missing SVG can never mean a broken image or an empty box.
 *
 * $extra_attrs lets a caller override the lazy-loading default (e.g. the
 * above-the-fold homepage hero, which must stay eager for LCP) or add its own
 * class alongside the default one.
 */
function atlas_chuti_fallback_image_html( $context, $continent_slug = '', $alt = '', $extra_attrs = array() ) {
	$slug = atlas_chuti_fallback_image_slug( $context, $continent_slug );
	$path = atlas_chuti_fallback_image_path( $slug );
	if ( ! $slug || ! file_exists( $path ) ) {
		return '';
	}

	$attrs = array_merge(
		array(
			'class'   => 'fallback-photo',
			'loading' => 'lazy',
			'decoding' => 'async',
			'alt'     => $alt,
		),
		$extra_attrs
	);

	$attr_html = '';
	foreach ( $attrs as $key => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}
		$attr_html .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
	}

	return '<img src="' . esc_url( atlas_chuti_fallback_image_url( $slug ) ) . '"' . $attr_html . '>';
}
