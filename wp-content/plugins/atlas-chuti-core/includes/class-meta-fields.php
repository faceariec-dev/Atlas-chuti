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
			'original_title'   => array( 'type' => 'text', 'label' => 'Originální název', 'required' => false ),
			'excerpt'          => array( 'type' => 'textarea', 'label' => 'Krátký perex', 'required' => true ),
			'photo_credit'     => array( 'type' => 'text', 'label' => 'Zdroj / copyright fotografie', 'required' => false ),
			'servings_default' => array( 'type' => 'int', 'label' => 'Výchozí počet porcí', 'required' => true, 'default' => 4 ),
			'prep_minutes'     => array( 'type' => 'int', 'label' => 'Čas přípravy (min)', 'required' => true ),
			'cook_minutes'     => array( 'type' => 'int', 'label' => 'Čas vaření (min)', 'required' => false, 'default' => 0 ),
			'total_minutes'    => array( 'type' => 'int', 'label' => 'Celkový čas (min)', 'required' => false ),
			'about'            => array( 'type' => 'richtext', 'label' => 'O receptu', 'required' => false ),
			'ingredients'      => array(
				'type'     => 'repeater',
				'label'    => 'Ingredience',
				'required' => true,
				'shape'    => array( 'ingredient_id', 'name', 'quantity', 'unit', 'note', 'group' ),
			),
			'steps'            => array(
				'type'     => 'repeater',
				'label'    => 'Postup',
				'required' => true,
				'shape'    => array( 'order', 'text' ),
			),
			'tips'             => array( 'type' => 'string_list', 'label' => 'Tipy', 'required' => false ),
			'watch_out'        => array( 'type' => 'textarea', 'label' => 'Na co si dát pozor', 'required' => false ),
			'variants'         => array(
				'type'     => 'repeater',
				'label'    => 'Varianty',
				'required' => false,
				'shape'    => array( 'name', 'note' ),
			),
			'origin_history'   => array( 'type' => 'richtext', 'label' => 'Původ / historie', 'required' => false ),
			'related_glossary' => array( 'type' => 'post_ref_list', 'label' => 'Související pojmy', 'required' => false, 'ref_type' => 'atlas_glossary' ),
			'related_recipes'  => array( 'type' => 'post_ref_list', 'label' => 'Související recepty', 'required' => false, 'ref_type' => 'atlas_recipe' ),
			'seo_title'        => array( 'type' => 'text', 'label' => 'SEO title', 'required' => false ),
			'meta_description' => array( 'type' => 'textarea', 'label' => 'Meta description', 'required' => false ),
		);
	}

	public static function country_fields() {
		return array(
			'name_cs'                => array( 'type' => 'text', 'label' => 'Český název', 'required' => true ),
			'name_en'                => array( 'type' => 'text', 'label' => 'Anglický název', 'required' => false ),
			'iso_code'               => array( 'type' => 'text', 'label' => 'ISO kód', 'required' => true ),
			'flag_emoji'             => array( 'type' => 'text', 'label' => 'Vlajka (emoji)', 'required' => true ),
			'capital'                => array( 'type' => 'text', 'label' => 'Hlavní město', 'required' => false ),
			'languages'              => array( 'type' => 'string_list', 'label' => 'Jazyky', 'required' => false ),
			'currency'               => array( 'type' => 'text', 'label' => 'Měna', 'required' => false ),
			'area_km2'               => array( 'type' => 'int', 'label' => 'Rozloha (km²)', 'required' => false ),
			'population'             => array( 'type' => 'int', 'label' => 'Počet obyvatel', 'required' => false ),
			'population_year'        => array( 'type' => 'int', 'label' => 'Rok platnosti údaje o populaci', 'required' => false ),
			'intro'                  => array( 'type' => 'richtext', 'label' => 'Krátký úvod', 'required' => true ),
			'taste_intro'            => array( 'type' => 'richtext', 'label' => 'Jak chutná…', 'required' => false ),
			'typical_ingredients'    => array( 'type' => 'string_list', 'label' => 'Typické suroviny', 'required' => false ),
			'traditional_dishes'     => array(
				'type'     => 'repeater',
				'label'    => 'Co se v zemi jí',
				'required' => false,
				'shape'    => array( 'name', 'note', 'recipe_id' ),
			),
			'must_try'               => array(
				'type'     => 'repeater',
				'label'    => '5 jídel, která ochutnat',
				'required' => false,
				'shape'    => array( 'name', 'note' ),
			),
			'fun_facts'              => array( 'type' => 'string_list', 'label' => 'Gastronomické zajímavosti', 'required' => false ),
			'related_glossary'       => array( 'type' => 'post_ref_list', 'label' => 'Související pojmy', 'required' => false, 'ref_type' => 'atlas_glossary' ),
			'related_countries'      => array( 'type' => 'post_ref_list', 'label' => 'Podobné kuchyně', 'required' => false, 'ref_type' => 'atlas_country' ),
			'seo_title'              => array( 'type' => 'text', 'label' => 'SEO title', 'required' => false ),
			'meta_description'      => array( 'type' => 'textarea', 'label' => 'Meta description', 'required' => false ),
			'facts_source'           => array( 'type' => 'text', 'label' => 'Zdroj faktografických údajů', 'required' => false ),
			'facts_updated'          => array( 'type' => 'date', 'label' => 'Datum poslední aktualizace', 'required' => false ),
		);
	}

	public static function glossary_fields() {
		return array(
			'short_definition'  => array( 'type' => 'textarea', 'label' => 'Stručná definice', 'required' => true ),
			'detailed'          => array( 'type' => 'richtext', 'label' => 'Detailní vysvětlení', 'required' => false ),
			'origin_country_id' => array( 'type' => 'post_ref', 'label' => 'Země / původ', 'required' => false, 'ref_type' => 'atlas_country' ),
			'taste'             => array( 'type' => 'textarea', 'label' => 'Jak chutná', 'required' => false ),
			'usage'             => array( 'type' => 'textarea', 'label' => 'Jak se používá', 'required' => false ),
			'substitute'        => array( 'type' => 'textarea', 'label' => 'Čím nahradit', 'required' => false ),
			'related_recipes'   => array( 'type' => 'post_ref_list', 'label' => 'Související recepty', 'required' => false, 'ref_type' => 'atlas_recipe' ),
			'related_countries' => array( 'type' => 'post_ref_list', 'label' => 'Související země', 'required' => false, 'ref_type' => 'atlas_country' ),
			'seo_title'         => array( 'type' => 'text', 'label' => 'SEO title', 'required' => false ),
			'meta_description'  => array( 'type' => 'textarea', 'label' => 'Meta description', 'required' => false ),
		);
	}

	public static function ingredient_fields() {
		return array(
			'aliases'      => array( 'type' => 'string_list', 'label' => 'Alternativní názvy (rajče, rajčata, rajčat…)', 'required' => false ),
			'default_unit' => array( 'type' => 'text', 'label' => 'Výchozí jednotka', 'required' => false ),
		);
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
	 */
	public static function sanitize( $type, $value, $shape = array() ) {
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
						$clean_row[ $field ] = isset( $row[ $field ] ) ? ( is_scalar( $row[ $field ] ) ? sanitize_text_field( (string) $row[ $field ] ) : '' ) : '';
					}
					$clean[] = $clean_row;
				}
				return $clean;
			default:
				return $value;
		}
	}

	public static function meta_key( $field_key ) {
		return 'atlas_' . $field_key;
	}
}
