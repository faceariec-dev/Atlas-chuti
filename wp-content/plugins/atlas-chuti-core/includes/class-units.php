<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized units (item 6 of this phase's brief). Ingredient rows keep their
 * free-text, author-facing `unit` (e.g. "lžíce") exactly as before — nothing about
 * today's display changes. What's new is `unit_key`: a canonical, language-neutral
 * key auto-derived from that text, validated against a known set. It's what makes
 * "budoucí lokalizace/převod jednotek" possible later without touching stored
 * content — today we only *read* unit_key for validation warnings and to offer a
 * localized label(); nothing consumes it for conversion yet, and none is required
 * (US units, unit conversion — out of scope now, just not blocked).
 */
class Atlas_Chuti_Units {

	/**
	 * canonical key => [locale => label]. Order matters only for admin hints.
	 */
	public static function canonical_units() {
		return array(
			'g'        => array( 'cs-CZ' => 'g', 'en' => 'g' ),
			'kg'       => array( 'cs-CZ' => 'kg', 'en' => 'kg' ),
			'ml'       => array( 'cs-CZ' => 'ml', 'en' => 'ml' ),
			'l'        => array( 'cs-CZ' => 'l', 'en' => 'l' ),
			'pcs'      => array( 'cs-CZ' => 'ks', 'en' => 'pcs' ),
			'tbsp'     => array( 'cs-CZ' => 'lžíce', 'en' => 'tbsp' ),
			'tsp'      => array( 'cs-CZ' => 'lžička', 'en' => 'tsp' ),
			'pinch'    => array( 'cs-CZ' => 'špetka', 'en' => 'pinch' ),
			'to_taste' => array( 'cs-CZ' => 'podle chuti', 'en' => 'to taste' ),
		);
	}

	/**
	 * Aliases (lowercased) recognized as a given canonical key, on top of the
	 * canonical label itself. Lets authors keep typing plain Czech text.
	 */
	private static function aliases() {
		return array(
			'g'        => array( 'g', 'gram', 'gramy', 'gramů' ),
			'kg'       => array( 'kg', 'kilogram', 'kilogramy' ),
			'ml'       => array( 'ml', 'mililitr', 'mililitry' ),
			'l'        => array( 'l', 'litr', 'litry' ),
			'pcs'      => array( 'ks', 'kus', 'kusy', 'kusů', 'pcs', 'pc' ),
			'tbsp'     => array( 'lžíce', 'lžíci', 'lžic', 'polévková lžíce', 'tbsp' ),
			'tsp'      => array( 'lžička', 'lžičky', 'lžiček', 'čajová lžička', 'tsp' ),
			'pinch'    => array( 'špetka', 'špetku', 'špetky' ),
			'to_taste' => array( 'podle chuti', 'dle chuti' ),
		);
	}

	/**
	 * Normalizes free text ("lžíce", "Lžíce", "ks"…) to a canonical key, or null
	 * when the text doesn't match anything known (still a valid unit — just not
	 * one this registry can localize/convert later). Empty string always maps to
	 * null (many ingredients legitimately have no unit, e.g. "2 vejce").
	 */
	public static function normalize( $raw ) {
		$raw = trim( mb_strtolower( (string) $raw ) );
		if ( '' === $raw ) {
			return null;
		}
		if ( array_key_exists( $raw, self::canonical_units() ) ) {
			return $raw;
		}
		foreach ( self::aliases() as $key => $aliases ) {
			if ( in_array( $raw, $aliases, true ) ) {
				return $key;
			}
		}
		return null;
	}

	public static function label( $canonical_key, $locale = 'cs-CZ' ) {
		$units = self::canonical_units();
		if ( ! isset( $units[ $canonical_key ] ) ) {
			return $canonical_key;
		}
		return $units[ $canonical_key ][ $locale ] ?? $units[ $canonical_key ]['cs-CZ'];
	}
}
