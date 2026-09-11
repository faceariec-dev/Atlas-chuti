<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional Polylang integration (item 4/24 of this phase's brief). Fully inactive
 * unless Polylang is actually installed and active — every method guards on
 * function_exists() and does nothing (or returns null) otherwise, so the Czech site
 * works identically with or without this file. No hard dependency is introduced
 * anywhere else in the codebase: only class-i18n.php's current_locale() consults this
 * bridge, and only when Atlas_Chuti_Polylang_Bridge::is_active() is true.
 *
 * When Polylang IS active, this is the one place that translates between our BCP 47
 * locale tags (cs-CZ, en) and Polylang's own short language slugs (cs, en), so the
 * rest of the plugin never has to know Polylang exists. Domain-per-language mapping
 * (atlaschuti.cz ↔ cs, atlaschuti.com ↔ en) is Polylang's own site configuration,
 * done in wp-admin when English actually launches — never hardcoded here.
 */
class Atlas_Chuti_Polylang_Bridge {

	/**
	 * Maps our BCP 47 locale tags to Polylang's language slugs. Extend this if/when a
	 * language beyond cs-CZ/en is added; unknown locales fall back to their lowercase
	 * base subtag (e.g. "de-DE" → "de").
	 */
	const LOCALE_TO_SLUG = array(
		'cs-CZ' => 'cs',
		'en'    => 'en',
	);

	public static function is_active() {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_current_language' );
	}

	/**
	 * The visitor's current Polylang language, translated to our BCP 47 tag. Returns
	 * null when Polylang isn't active (class-i18n.php falls back to the default in
	 * that case) or hasn't determined a language yet.
	 */
	public static function current_locale() {
		if ( ! self::is_active() ) {
			return null;
		}
		$slug = pll_current_language( 'slug' );
		return $slug ? self::slug_to_locale( $slug ) : null;
	}

	/**
	 * Assigns a WordPress language to an imported/edited post. No-op without Polylang.
	 */
	public static function assign_language( $post_id, $locale ) {
		if ( ! self::is_active() || ! function_exists( 'pll_set_post_language' ) ) {
			return;
		}
		pll_set_post_language( $post_id, self::locale_to_slug( $locale ) );
	}

	/**
	 * Links every locale variant of "the same" post (same translation_group / ISO /
	 * ingredient_key) together as Polylang translations of one another, so Polylang's
	 * own language switcher and admin UI understand the relationship this plugin
	 * already tracks via atlas_locale + the stable key. $locale_post_map is
	 * array( 'cs-CZ' => post_id, 'en' => post_id, … ).
	 */
	public static function link_translations( array $locale_post_map ) {
		if ( ! self::is_active() || ! function_exists( 'pll_save_post_translations' ) || count( $locale_post_map ) < 2 ) {
			return;
		}
		$by_slug = array();
		foreach ( $locale_post_map as $locale => $post_id ) {
			if ( $post_id ) {
				$by_slug[ self::locale_to_slug( $locale ) ] = (int) $post_id;
			}
		}
		if ( count( $by_slug ) > 1 ) {
			pll_save_post_translations( $by_slug );
		}
	}

	public static function locale_to_slug( $locale ) {
		if ( isset( self::LOCALE_TO_SLUG[ $locale ] ) ) {
			return self::LOCALE_TO_SLUG[ $locale ];
		}
		return strtolower( substr( (string) $locale, 0, 2 ) );
	}

	public static function slug_to_locale( $slug ) {
		$flipped = array_flip( self::LOCALE_TO_SLUG );
		return $flipped[ $slug ] ?? $slug;
	}
}
