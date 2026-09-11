<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalized units. The single canonical registry every other piece of the unit
 * model builds on — the JSON importer (validation/warnings), the recipe meta field
 * shape, the servings calculator, and the frontend all resolve units through this
 * class, never by matching Czech text ad hoc.
 *
 * A recipe's `unit` field stores the CANONICAL key (e.g. "cup", "g", "tbsp"), not a
 * Czech-declined word — "hrnek"/"hrnku"/"hrnky"/"hrnků" must never appear as
 * separate technical identifiers, only as recognized input aliases that normalize()
 * folds into the one canonical "cup". label() resolves the canonical key to a
 * locale-appropriate display string at render time (Atlas_Chuti_Servings uses it for
 * the recipe page) — cs-CZ today, en later once a second locale exists — so the
 * canonical key never needs to change for that. Older content authored with plain
 * Czech text in `unit` still works: normalize() recognizes it as an alias and maps
 * it to the same canonical key, so nothing already imported needs to be rewritten.
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
			'cup'      => array( 'cs-CZ' => 'hrnek', 'en' => 'cup' ),
			'clove'    => array( 'cs-CZ' => 'stroužek', 'en' => 'clove' ),
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
			// Every Czech declined form of "hrnek" folds into the ONE canonical key
			// "cup" — none of "hrnek"/"hrnku"/"hrnky"/"hrnků" is ever itself a
			// canonical identifier, only an input alias normalized away on import.
			'cup'      => array( 'hrnek', 'hrnku', 'hrnky', 'hrnků', 'cup', 'cups' ),
			'clove'    => array( 'stroužek', 'stroužky', 'stroužků', 'clove', 'cloves' ),
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
