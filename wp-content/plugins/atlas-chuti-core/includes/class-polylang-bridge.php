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

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * KROK 4, item 7: tells Polylang which of our custom post types/taxonomies are
	 * translatable — without this, Polylang only manages core 'post'/'page' and an
	 * admin would have to remember to tick the right boxes in Settings → Languages.
	 * Registering it in code means it's always correct on a fresh Polylang install.
	 * These filters are simply never called when Polylang isn't active, so this
	 * constructor is safe to run unconditionally.
	 *
	 * atlas_ingredient (the internal, non-public dictionary) and atlas_country_tax/
	 * atlas_ingredient_tax (technical taxonomies with exactly ONE shared term per
	 * ISO code / ingredient_key across every locale — see class-country-sync.php's
	 * docblock) are deliberately NOT registered here: they already have their own
	 * cross-locale identity mechanism (ingredient_key/ISO code + locale), and making
	 * them Polylang-translatable too would create two competing, conflicting ideas
	 * of what "the same country/ingredient" means.
	 */
	private function __construct() {
		add_filter( 'pll_get_post_types', array( $this, 'register_post_types' ), 10, 2 );
		add_filter( 'pll_get_taxonomies', array( $this, 'register_taxonomies' ), 10, 2 );
	}

	public function register_post_types( $post_types, $is_settings = false ) {
		foreach ( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' ) as $post_type ) {
			$post_types[ $post_type ] = $post_type;
		}
		return $post_types;
	}

	public function register_taxonomies( $taxonomies, $is_settings = false ) {
		foreach ( array( 'atlas_continent', 'atlas_meal_type', 'atlas_difficulty', 'atlas_diet', 'atlas_recipe_tag', 'atlas_glossary_category' ) as $taxonomy ) {
			$taxonomies[ $taxonomy ] = $taxonomy;
		}
		return $taxonomies;
	}

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

	/**
	 * KROK 4, item 6: the translation of $post_id in $target_locale, or 0 when
	 * Polylang isn't active, the post isn't translatable, or no such translation
	 * exists yet — never guessed, never fabricated.
	 */
	public static function get_post_translation_id( $post_id, $target_locale ) {
		if ( ! self::is_active() || ! function_exists( 'pll_get_post' ) ) {
			return 0;
		}
		$translated = pll_get_post( $post_id, self::locale_to_slug( $target_locale ) );
		return $translated ? (int) $translated : 0;
	}

	/**
	 * KROK 4, item 6: same idea as get_post_translation_id() but for a taxonomy term.
	 */
	public static function get_term_translation_id( $term_id, $target_locale ) {
		if ( ! self::is_active() || ! function_exists( 'pll_get_term' ) ) {
			return 0;
		}
		$translated = pll_get_term( $term_id, self::locale_to_slug( $target_locale ) );
		return $translated ? (int) $translated : 0;
	}

	/**
	 * KROK 4, item 8: language-switcher data for the CURRENT request — one entry per
	 * supported locale (Atlas_Chuti_I18N::SUPPORTED_LOCALES), each with whatever real,
	 * crawlable URL is safe to offer:
	 *   - the exact translation, when the current singular post/term actually has one
	 *     published in that locale,
	 *   - that locale's home page, as a graceful fallback everywhere else (archives,
	 *     search, home, or a post/term with no translation yet) — never a broken URL,
	 *     never a fabricated translated page (item 8 of the brief: "nevytvářej broken
	 *     URL... nevytvářej fake překlad").
	 * `exact` tells the template whether the link is the precise translation (safe to
	 * treat as equivalent for hreflang) or the same-locale-home fallback (never used
	 * for hreflang — see class-seo.php).
	 */
	public static function switcher_data() {
		$items = array();
		foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $locale ) {
			$items[] = array(
				'locale'      => $locale,
				'label'       => 'cs-CZ' === $locale ? 'CZ' : 'EN',
				'is_current'  => $locale === Atlas_Chuti_I18N::current_locale(),
				'url'         => self::is_active() ? self::url_for_locale( $locale ) : null,
				'exact'       => self::is_active() && self::has_exact_translation( $locale ),
			);
		}
		return $items;
	}

	/**
	 * Real translation URL for the CURRENT queried object in $target_locale, or that
	 * locale's home page when there isn't one (see switcher_data()'s docblock). Only
	 * ever called while Polylang is active.
	 */
	private static function url_for_locale( $target_locale ) {
		if ( is_singular() ) {
			$translated_id = self::get_post_translation_id( get_queried_object_id(), $target_locale );
			if ( $translated_id && 'publish' === get_post_status( $translated_id ) ) {
				return get_permalink( $translated_id );
			}
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$translated_id = self::get_term_translation_id( $term->term_id, $target_locale );
				if ( $translated_id ) {
					$link = get_term_link( $translated_id, $term->taxonomy );
					if ( ! is_wp_error( $link ) ) {
						return $link;
					}
				}
			}
		}
		if ( function_exists( 'pll_home_url' ) ) {
			return pll_home_url( self::locale_to_slug( $target_locale ) );
		}
		return home_url( 'en' === $target_locale ? '/en/' : '/' );
	}

	private static function has_exact_translation( $target_locale ) {
		if ( is_singular() ) {
			return (bool) self::get_post_translation_id( get_queried_object_id(), $target_locale );
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term && (bool) self::get_term_translation_id( $term->term_id, $target_locale );
		}
		return false;
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
