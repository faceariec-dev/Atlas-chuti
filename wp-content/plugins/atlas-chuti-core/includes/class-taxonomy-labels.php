<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable-key → locale label map for the "closed vocabulary" taxonomies (item 14/15/18
 * of this phase's brief): continent, difficulty, glossary category. Their WordPress
 * term `slug` IS the data identity (e.g. "europe", "easy", "technique") — the same
 * term is shared by every locale's posts, exactly like atlas_country_tax/
 * atlas_ingredient_tax share one canonical term per ISO/ingredient_key. A WP term only
 * has one `name`, so it can't hold both "Evropa" and "Europe" at once; this class is
 * what a future English page uses to display "Europe" for the very same term that
 * shows "Evropa" today, without duplicating terms per locale.
 *
 * meal_type and diet stay open vocabularies (new values are expected to show up
 * through real content, not a fixed list) — this class documents the suggested
 * starter keys for them too, but importer validation never rejects an unknown one.
 */
class Atlas_Chuti_Taxonomy_Labels {

	const LABELS = array(
		'atlas_continent'         => array(
			'europe'        => array( 'cs-CZ' => 'Evropa', 'en' => 'Europe' ),
			'asia'          => array( 'cs-CZ' => 'Asie', 'en' => 'Asia' ),
			'africa'        => array( 'cs-CZ' => 'Afrika', 'en' => 'Africa' ),
			'north-america' => array( 'cs-CZ' => 'Severní Amerika', 'en' => 'North America' ),
			'south-america' => array( 'cs-CZ' => 'Jižní Amerika', 'en' => 'South America' ),
			'oceania'       => array( 'cs-CZ' => 'Oceánie', 'en' => 'Oceania' ),
		),
		'atlas_difficulty'        => array(
			'easy'   => array( 'cs-CZ' => 'Snadné', 'en' => 'Easy' ),
			'medium' => array( 'cs-CZ' => 'Střední', 'en' => 'Medium' ),
			'hard'   => array( 'cs-CZ' => 'Náročné', 'en' => 'Hard' ),
		),
		'atlas_diet'              => array(
			'vegetarian' => array( 'cs-CZ' => 'Vegetariánské', 'en' => 'Vegetarian' ),
			'vegan'      => array( 'cs-CZ' => 'Veganské', 'en' => 'Vegan' ),
		),
		'atlas_meal_type'         => array(
			'breakfast'   => array( 'cs-CZ' => 'Snídaně', 'en' => 'Breakfast' ),
			'appetizer'   => array( 'cs-CZ' => 'Předkrm', 'en' => 'Appetizer' ),
			'soup'        => array( 'cs-CZ' => 'Polévka', 'en' => 'Soup' ),
			'main-course' => array( 'cs-CZ' => 'Hlavní jídlo', 'en' => 'Main course' ),
			'side-dish'   => array( 'cs-CZ' => 'Příloha', 'en' => 'Side dish' ),
			'salad'       => array( 'cs-CZ' => 'Salát', 'en' => 'Salad' ),
			'dessert'     => array( 'cs-CZ' => 'Dezert', 'en' => 'Dessert' ),
			'bakery'      => array( 'cs-CZ' => 'Pečivo', 'en' => 'Bakery' ),
			'sauce'       => array( 'cs-CZ' => 'Omáčka', 'en' => 'Sauce' ),
			'beverage'    => array( 'cs-CZ' => 'Nápoj', 'en' => 'Beverage' ),
		),
		'atlas_glossary_category' => array(
			'technique'  => array( 'cs-CZ' => 'Kuchařské techniky', 'en' => 'Cooking techniques' ),
			'ingredient' => array( 'cs-CZ' => 'Suroviny', 'en' => 'Ingredients' ),
			'gastronomy' => array( 'cs-CZ' => 'Gastronomické pojmy', 'en' => 'Gastronomy terms' ),
			'equipment'  => array( 'cs-CZ' => 'Nádobí a vybavení', 'en' => 'Equipment' ),
		),
	);

	/**
	 * The known stable keys for one of these taxonomies, in a stable display order.
	 */
	public static function keys( $taxonomy ) {
		return array_keys( self::LABELS[ $taxonomy ] ?? array() );
	}

	/**
	 * The display label for one stable key in one locale (defaults to the current
	 * request locale). Falls back to the same BCP 47 base language (e.g. "en-US" →
	 * "en"), then to Czech, then to the raw key itself for a value this map doesn't
	 * know about (e.g. an open meal_type/diet key with no curated translation yet).
	 */
	public static function label( $taxonomy, $key, $locale = null ) {
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();
		$known  = self::LABELS[ $taxonomy ][ $key ] ?? array();

		if ( isset( $known[ $locale ] ) ) {
			return $known[ $locale ];
		}

		$base = strtolower( substr( (string) $locale, 0, 2 ) );
		foreach ( $known as $known_locale => $label ) {
			if ( 0 === strpos( strtolower( $known_locale ), $base ) ) {
				return $label;
			}
		}

		return $known[ Atlas_Chuti_I18N::DEFAULT_LOCALE ] ?? $key;
	}
}
