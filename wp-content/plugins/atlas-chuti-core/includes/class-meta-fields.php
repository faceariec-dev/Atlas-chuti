<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for every custom field in the data model: meta key, type,
 * whether it's required for JSON import, and how to sanitize it. Used by the meta
 * boxes (admin/js/repeater.js renders the array ones), the JSON importer, and
 * register_post_meta() so the same contract is used everywhere. This is exactly what
 * item 27/29 of the brief asks for: today's import format IS tomorrow's AI contract.
 */
class Atlas_Chuti_Meta_Fields {

	/**
	 * Field types: text, textarea, richtext, int, url, date, string_list (array of strings),
	 * repeater (array of objects — shape described inline), post_ref (single post ID),
	 * post_ref_list (array of post IDs).
	 */
	public static function recipe_fields() {
		return array(
			'original_title'   => array( 'type' => 'text', 'label' => __( 'Originální název', 'atlas-chuti' ), 'required' => false ),
			'excerpt'          => array( 'type' => 'textarea', 'label' => __( 'Krátký perex', 'atlas-chuti' ), 'required' => true ),
			'photo_credit'     => array( 'type' => 'text', 'label' => __( 'Zdroj / copyright fotografie', 'atlas-chuti' ), 'required' => false ),
			'servings_default' => array( 'type' => 'int', 'label' => __( 'Výchozí počet porcí', 'atlas-chuti' ), 'required' => true, 'default' => 4 ),
			'prep_minutes'     => array( 'type' => 'int', 'label' => __( 'Čas přípravy (min)', 'atlas-chuti' ), 'required' => true ),
			'cook_minutes'     => array( 'type' => 'int', 'label' => __( 'Čas vaření (min)', 'atlas-chuti' ), 'required' => false, 'default' => 0 ),
			'total_minutes'    => array( 'type' => 'int', 'label' => __( 'Celkový čas (min)', 'atlas-chuti' ), 'required' => false ),
			'about'            => array( 'type' => 'richtext', 'label' => __( 'O receptu', 'atlas-chuti' ), 'required' => false ),
			'ingredients'      => array(
				'type'     => 'repeater',
				'label' => __( 'Ingredience', 'atlas-chuti' ),
				'required' => true,
				// display_name/quantity/unit/note/group are locale-specific authored text;
				// ingredient_key is the stable, language-independent identity (item 4/5 of
				// this phase's brief); scalable optionally overrides auto-detection from
				// quantity (Atlas_Chuti_Servings::parse_quantity()) for edge cases — it must
				// stay a real tri-state boolean (true/false/unset), not a stringified "1"/"",
				// or an explicit `scalable: false` from JSON silently stops working (item 13
				// of this phase's brief). See sanitize()'s 'bool' handling below.
				'shape'    => array( 'ingredient_key', 'display_name', 'quantity', 'unit', 'note', 'group', 'scalable' ),
				'types'    => array( 'scalable' => 'bool' ),
			),
			'steps'            => array(
				'type'     => 'repeater',
				'label' => __( 'Postup', 'atlas-chuti' ),
				'required' => true,
				'shape'    => array( 'order', 'text' ),
			),
			'tips'             => array( 'type' => 'string_list', 'label' => __( 'Tipy', 'atlas-chuti' ), 'required' => false ),
			'watch_out'        => array( 'type' => 'textarea', 'label' => __( 'Na co si dát pozor', 'atlas-chuti' ), 'required' => false ),
			'variants'         => array(
				'type'     => 'repeater',
				'label' => __( 'Varianty', 'atlas-chuti' ),
				'required' => false,
				'shape'    => array( 'name', 'note' ),
			),
			'origin_history'   => array( 'type' => 'richtext', 'label' => __( 'Původ / historie', 'atlas-chuti' ), 'required' => false ),
			'related_glossary' => array( 'type' => 'post_ref_list', 'label' => __( 'Související pojmy', 'atlas-chuti' ), 'required' => false, 'ref_type' => 'atlas_glossary' ),
			'related_recipes'  => array( 'type' => 'post_ref_list', 'label' => __( 'Související recepty', 'atlas-chuti' ), 'required' => false, 'ref_type' => 'atlas_recipe' ),
			'seo_title'        => array( 'type' => 'text', 'label' => __( 'SEO title', 'atlas-chuti' ), 'required' => false ),
			'meta_description' => array( 'type' => 'textarea', 'label' => __( 'Meta description', 'atlas-chuti' ), 'required' => false ),
		);
	}

	public static function country_fields() {
		return array(
			'name_cs'                => array( 'type' => 'text', 'label' => __( 'Český název', 'atlas-chuti' ), 'required' => true ),
			'name_en'                => array( 'type' => 'text', 'label' => __( 'Anglický název', 'atlas-chuti' ), 'required' => false ),
			'iso_code'               => array( 'type' => 'text', 'label' => __( 'ISO kód', 'atlas-chuti' ), 'required' => true ),
			'flag_emoji'             => array( 'type' => 'text', 'label' => __( 'Vlajka (emoji)', 'atlas-chuti' ), 'required' => true ),
			'capital'                => array( 'type' => 'text', 'label' => __( 'Hlavní město', 'atlas-chuti' ), 'required' => false ),
			'languages'              => array( 'type' => 'string_list', 'label' => __( 'Jazyky', 'atlas-chuti' ), 'required' => false ),
			'currency'               => array( 'type' => 'text', 'label' => __( 'Měna', 'atlas-chuti' ), 'required' => false ),
			'area_km2'               => array( 'type' => 'int', 'label' => __( 'Rozloha (km²)', 'atlas-chuti' ), 'required' => false ),
			'population'             => array( 'type' => 'int', 'label' => __( 'Počet obyvatel', 'atlas-chuti' ), 'required' => false ),
			'population_year'        => array( 'type' => 'int', 'label' => __( 'Rok platnosti údaje o populaci', 'atlas-chuti' ), 'required' => false ),
			'intro'                  => array( 'type' => 'richtext', 'label' => __( 'Krátký úvod', 'atlas-chuti' ), 'required' => true ),
			'taste_intro'            => array( 'type' => 'richtext', 'label' => __( 'Jak chutná…', 'atlas-chuti' ), 'required' => false ),
			'typical_ingredients'    => array( 'type' => 'string_list', 'label' => __( 'Typické suroviny', 'atlas-chuti' ), 'required' => false ),
			'traditional_dishes'     => array(
				'type'     => 'repeater',
				'label' => __( 'Co se v zemi jí', 'atlas-chuti' ),
				'required' => false,
				'shape'    => array( 'name', 'note', 'recipe_id' ),
			),
			'must_try'               => array(
				'type'     => 'repeater',
				'label' => __( '5 jídel, která ochutnat', 'atlas-chuti' ),
				'required' => false,
				'shape'    => array( 'name', 'note' ),
			),
			'fun_facts'              => array( 'type' => 'string_list', 'label' => __( 'Gastronomické zajímavosti', 'atlas-chuti' ), 'required' => false ),
			'related_glossary'       => array( 'type' => 'post_ref_list', 'label' => __( 'Související pojmy', 'atlas-chuti' ), 'required' => false, 'ref_type' => 'atlas_glossary' ),
			'related_countries'      => array( 'type' => 'post_ref_list', 'label' => __( 'Podobné kuchyně', 'atlas-chuti' ), 'required' => false, 'ref_type' => 'atlas_country' ),
			'seo_title'              => array( 'type' => 'text', 'label' => __( 'SEO title', 'atlas-chuti' ), 'required' => false ),
			'meta_description'      => array( 'type' => 'textarea', 'label' => __( 'Meta description', 'atlas-chuti' ), 'required' => false ),
			'facts_source'           => array( 'type' => 'text', 'label' => __( 'Zdroj faktografických údajů', 'atlas-chuti' ), 'required' => false ),
			'facts_updated'          => array( 'type' => 'date', 'label' => __( 'Datum poslední aktualizace', 'atlas-chuti' ), 'required' => false ),
		);
	}

	public static function glossary_fields() {
		return array(
			'short_definition'  => array( 'type' => 'textarea', 'label' => __( 'Stručná definice', 'atlas-chuti' ), 'required' => true ),
			'detailed'          => array( 'type' => 'richtext', 'label' => __( 'Detailní vysvětlení', 'atlas-chuti' ), 'required' => false ),
			'origin_country_id' => array( 'type' => 'post_ref', 'label' => __( 'Země / původ', 'atlas-chuti' ), 'required' => false, 'ref_type' => 'atlas_country' ),
			'taste'             => array( 'type' => 'textarea', 'label' => __( 'Jak chutná', 'atlas-chuti' ), 'required' => false ),
			'usage'             => array( 'type' => 'textarea', 'label' => __( 'Jak se používá', 'atlas-chuti' ), 'required' => false ),
			'substitute'        => array( 'type' => 'textarea', 'label' => __( 'Čím nahradit', 'atlas-chuti' ), 'required' => false ),
			'related_recipes'   => array( 'type' => 'post_ref_list', 'label' => __( 'Související recepty', 'atlas-chuti' ), 'required' => false, 'ref_type' => 'atlas_recipe' ),
			'related_countries' => array( 'type' => 'post_ref_list', 'label' => __( 'Související země', 'atlas-chuti' ), 'required' => false, 'ref_type' => 'atlas_country' ),
			'seo_title'         => array( 'type' => 'text', 'label' => __( 'SEO title', 'atlas-chuti' ), 'required' => false ),
			'meta_description'  => array( 'type' => 'textarea', 'label' => __( 'Meta description', 'atlas-chuti' ), 'required' => false ),
		);
	}

	public static function ingredient_fields() {
		return array(
			'ingredient_key' => array( 'type' => 'text', 'label' => __( 'Jazykově neutrální klíč (např. "tomato")', 'atlas-chuti' ), 'required' => false ),
			'aliases'        => array( 'type' => 'string_list', 'label' => __( 'Alternativní názvy (rajče, rajčata, rajčat…)', 'atlas-chuti' ), 'required' => false ),
			'default_unit'   => array( 'type' => 'text', 'label' => __( 'Výchozí jednotka', 'atlas-chuti' ), 'required' => false ),
		);
	}

	/**
	 * Meta keys shared by every locale-bearing entity (recipe/country/glossary) for
	 * language readiness (item 17 of the brief). Not rendered by the generic meta box
	 * loop — Czech is the only active locale today so there's nothing useful to edit —
	 * but the importer reads/writes them and class-i18n.php backfills sane defaults on
	 * every save, so the contract always holds.
	 */
	public static function i18n_field_keys() {
		return array( 'locale', 'translation_group', 'translation_status' );
	}

	public static function fields_for( $post_type ) {
		switch ( $post_type ) {
			case 'atlas_recipe':
				return self::recipe_fields();
			case 'atlas_country':
				return self::country_fields();
			case 'atlas_glossary':
				return self::glossary_fields();
			case 'atlas_ingredient':
				return self::ingredient_fields();
			default:
				return array();
		}
	}

	/**
	 * Sanitizes one value according to its declared type. Repeaters/string lists arrive
	 * as arrays (already decoded from JSON, either from the importer or the repeater UI).
	 * $shape may be a flat list of field names (all sanitized as text, the historical
	 * default) or carry a parallel $types map (passed as the 4th arg, see fields_for()'s
	 * 'types' key) naming a non-default type — currently only 'bool' — for fields that
	 * need it, e.g. ingredients[].scalable.
	 */
	public static function sanitize( $type, $value, $shape = array(), $types = array() ) {
		switch ( $type ) {
			case 'int':
				return is_numeric( $value ) ? (int) $value : 0;
			case 'text':
				return sanitize_text_field( (string) $value );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'date':
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? $value : '';
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'richtext':
				return wp_kses_post( (string) $value );
			case 'string_list':
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_values( array_filter( array_map( 'sanitize_text_field', $value ), 'strlen' ) );
			case 'post_ref':
				return absint( $value );
			case 'post_ref_list':
				if ( ! is_array( $value ) ) {
					return array();
				}
				return array_values( array_filter( array_map( 'absint', $value ) ) );
			case 'repeater':
				if ( ! is_array( $value ) ) {
					return array();
				}
				$clean = array();
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean_row = array();
					foreach ( $shape as $field ) {
						$field_type          = $types[ $field ] ?? 'text';
						$clean_row[ $field ] = self::sanitize_repeater_field( $field_type, $row[ $field ] ?? null );
					}
					$clean[] = $clean_row;
				}
				return $clean;
			default:
				return $value;
		}
	}

	/**
	 * One repeater sub-field. 'bool' is a real tri-state: true/false when the value
	 * unambiguously says so (a real bool, or "true"/"false"/"1"/"0"/"yes"/"no" text —
	 * the admin UI and hand-written JSON both submit text), null when absent/blank/
	 * unrecognized so callers (Atlas_Chuti_Servings) can tell "not specified" from
	 * "explicitly false" and fall back to auto-detection only in the former case.
	 */
	private static function sanitize_repeater_field( $field_type, $raw ) {
		if ( 'bool' === $field_type ) {
			if ( is_bool( $raw ) ) {
				return $raw;
			}
			$raw = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
			if ( in_array( $raw, array( 'true', '1', 'yes' ), true ) ) {
				return true;
			}
			if ( in_array( $raw, array( 'false', '0', 'no' ), true ) ) {
				return false;
			}
			return null;
		}
		return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
	}

	public static function meta_key( $field_key ) {
		return 'atlas_' . $field_key;
	}
}
