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
 *
 * atlas_recipe_tag (KROK 3, items 5-7) is different again: it's a CLOSED, curated
 * catalog — the array below IS the whole tag vocabulary, not just a starter set.
 * Growing it later means adding a key here (which Atlas_Chuti_Taxonomies::
 * maybe_seed_default_terms() then creates as a real term the next time it runs);
 * the importer (Atlas_Chuti_JSON_Importer::resolve_known_tag_term()) only ever
 * looks a tag up, it never creates one on the fly. Concepts that already have a
 * first-class field/taxonomy of their own are deliberately NOT duplicated here —
 * see the taxonomy map in docs/implementation-reports/
 * step-03-recipe-data-model-importer.md (section C): no country/cuisine tag
 * (country relation already exists), no difficulty tag (atlas_difficulty already
 * exists), no meal-type-shaped tag (atlas_meal_type already exists), no
 * "under 30 min"/"quick" time tag (prep_minutes/total_minutes are already
 * structured data a template can threshold directly, per item 5 of the brief).
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

		// Controlled public recipe tag catalog (KROK 3, item 7) — closed, see the class
		// docblock above. Grouped by concept only as a reading aid; the taxonomy itself
		// is flat/non-hierarchical.
		'atlas_recipe_tag'       => array(
			// Time / practicality — excludes "under 30 min"/"quick"-shaped tags on
			// purpose: prep_minutes/total_minutes are already structured fields a
			// template can threshold directly (item 5 of the brief). Hyphenated keys
			// (not underscored, despite the brief's own illustrative examples), to
			// match this codebase's existing term-key convention (main-course,
			// side-dish, north-america, …) — WordPress's real wp_insert_term()/
			// sanitize_title() would collapse an underscored key to hyphens anyway
			// (term slugs are always hyphen-normalized), so authoring them as hyphens
			// from the start avoids a needless double-notation for the same identity.
			'one-pot'          => array( 'cs-CZ' => 'Jedna nádoba', 'en' => 'One pot' ),
			'make-ahead'       => array( 'cs-CZ' => 'Lze připravit dopředu', 'en' => 'Make ahead' ),
			'freezer-friendly' => array( 'cs-CZ' => 'Vhodné na zamrazení', 'en' => 'Freezer friendly' ),
			// Character
			'traditional'      => array( 'cs-CZ' => 'Tradiční', 'en' => 'Traditional' ),
			'budget'           => array( 'cs-CZ' => 'Úsporné', 'en' => 'Budget-friendly' ),
			'family'           => array( 'cs-CZ' => 'Rodinné', 'en' => 'Family' ),
			'comfort-food'     => array( 'cs-CZ' => 'Jídlo pro pohodu', 'en' => 'Comfort food' ),
			'street-food'      => array( 'cs-CZ' => 'Pouliční jídlo', 'en' => 'Street food' ),
			// Occasion
			'christmas'        => array( 'cs-CZ' => 'Vánoce', 'en' => 'Christmas' ),
			'easter'           => array( 'cs-CZ' => 'Velikonoce', 'en' => 'Easter' ),
			'grilling'         => array( 'cs-CZ' => 'Grilování', 'en' => 'Grilling' ),
			'celebration'      => array( 'cs-CZ' => 'Oslava', 'en' => 'Celebration' ),
			'picnic'           => array( 'cs-CZ' => 'Piknik', 'en' => 'Picnic' ),
			// Season
			'spring'           => array( 'cs-CZ' => 'Jaro', 'en' => 'Spring' ),
			'summer'           => array( 'cs-CZ' => 'Léto', 'en' => 'Summer' ),
			'autumn'           => array( 'cs-CZ' => 'Podzim', 'en' => 'Autumn' ),
			'winter'           => array( 'cs-CZ' => 'Zima', 'en' => 'Winter' ),
			// Technique / form
			'no-bake'          => array( 'cs-CZ' => 'Bez pečení', 'en' => 'No bake' ),
			'baked'            => array( 'cs-CZ' => 'Pečené', 'en' => 'Baked' ),
			'grilled'          => array( 'cs-CZ' => 'Grilované', 'en' => 'Grilled' ),
			'slow-cooked'      => array( 'cs-CZ' => 'Dlouhé vaření', 'en' => 'Slow cooked' ),
			'fermented'        => array( 'cs-CZ' => 'Kvašené', 'en' => 'Fermented' ),
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
