<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language-readiness layer, now hardened into the real multilingual data model this
 * phase's brief asks for: JEDEN WordPress, JEDNA databáze, JEDEN theme, JEDEN core
 * plugin. atlaschuti.cz (cs-CZ) is active today; atlaschuti.com (en) is a future
 * locale served from the SAME install, the same post types, the same database — not
 * a second WordPress instance. Once a second locale exists, one atlas_country/
 * atlas_recipe/atlas_glossary/atlas_ingredient "thing" (e.g. "Italy") is represented
 * by TWO separate posts in this one database — IT+cs-CZ and IT+en — so a stable key
 * alone (ISO code / translation_group / ingredient_key) is no longer a unique post
 * identity. Every lookup below therefore takes identity + locale together.
 *
 *   - atlas_locale             e.g. "cs-CZ" (defaults here; never assume Czech forever)
 *   - atlas_translation_group  a stable, language-independent identity shared by every
 *                              locale's version of "the same" recipe/country/glossary entry
 *   - atlas_translation_status none|draft|reviewed|published — for a future AI-produced
 *                              localized version, not a literal translation
 *
 * Relations between entities (recipe→country, related_recipes, related_glossary,
 * ingredient references) must therefore never be resolved by WordPress post ID or by
 * a locale-specific slug alone:
 *   - countries resolve by ISO 3166-1 code (`atlas_iso_code`) + locale.
 *   - recipes/glossary resolve by `atlas_translation_group` + locale (slug is only a
 *     convenience fallback).
 *   - ingredients resolve by their language-neutral `atlas_ingredient_key` + locale
 *     (e.g. "tomato"), never by the Czech slug ("rajce"/"rajcata"/"rajcat" must all be
 *     *aliases* of one key, in one locale).
 *
 * No hard WPML/Polylang dependency, no `/en/` routes, no hreflang — those switch on
 * only once a second locale is actually active (see class-seo.php). Polylang, if and
 * when installed, plugs in through class-polylang-bridge.php without any of this
 * changing shape.
 */
class Atlas_Chuti_I18N {

	const DEFAULT_LOCALE = 'cs-CZ';

	// KROK 4: the only two locales this project actually supports. Internal
	// representation stays this project's existing BCP-47-ish style (cs-CZ / en) —
	// see normalize_locale()'s docblock for why this is NOT renamed to the KROK 4
	// brief's own illustrative cs_CZ/en_US spelling.
	const SUPPORTED_LOCALES = array( 'cs-CZ', 'en' );

	// 'post' added in KROK 4 (item 32 of the brief — standard WordPress posts must be
	// multilingual-ready for the future Magazín) — Polylang manages 'post'/'page'
	// natively without any registration filter, this only affects OUR OWN
	// atlas_locale backfill/query-scoping below, which 'post' didn't participate in
	// before. 'atlas_topic' added in KROK 6 (Diskuze) — a CZ and an EN topic are
	// always two independent posts (never "the same topic" the way a recipe_key
	// pairs CZ/EN), but they still need atlas_locale + the same query-scoping so a
	// CZ discussion archive never shows an EN topic and vice versa (item 18).
	const LOCALIZED_POST_TYPES = array( 'atlas_recipe', 'atlas_country', 'atlas_glossary', 'atlas_ingredient', 'post', 'atlas_topic' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		foreach ( self::LOCALIZED_POST_TYPES as $post_type ) {
			add_action( 'save_post_' . $post_type, array( $this, 'ensure_i18n_meta' ), 30, 2 );
		}
		// Priority 20 (after class-search.php's restrict_search_post_types(), which runs
		// at the default 10 and is what gives a search query its atlas_* post_type in
		// the first place) so a search query gets scoped too, not just direct archive/
		// single-purpose queries.
		add_action( 'pre_get_posts', array( $this, 'scope_query_to_locale' ), 20 );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'atlas-chuti', false, dirname( plugin_basename( ATLAS_CHUTI_DIR . 'atlas-chuti-core.php' ) ) . '/languages' );
	}

	/**
	 * Single source of truth for "what locale is this request in" (item 5 of this
	 * phase's brief). CHECKPOINT 10B: the incoming HOST is now the first authority —
	 * atlaschuti.cz is always cs-CZ, atlaschuti.com is always en, regardless of
	 * whatever Polylang would otherwise resolve from URL path/query — because the
	 * target architecture is host-per-language, not path-per-language (see
	 * class-domain-map.php). This lookup only ever fires for the two EXPLICITLY
	 * mapped production hosts; every other host (local dev, staging, WP-CLI/test
	 * context with no HTTP_HOST) returns null and falls straight through to the
	 * pre-10B behavior below, so this is a zero-regression addition. When Polylang
	 * (or any other multilingual plugin wired through a bridge) is active and the
	 * host didn't resolve anything, Polylang takes over here — see
	 * class-polylang-bridge.php. Nothing else in the codebase should invent its own
	 * way of asking "what language is this".
	 */
	public static function current_locale() {
		if ( class_exists( 'Atlas_Chuti_Domain_Map' ) ) {
			$host_locale = Atlas_Chuti_Domain_Map::locale_from_host();
			if ( $host_locale ) {
				return $host_locale;
			}
		}
		if ( class_exists( 'Atlas_Chuti_Polylang_Bridge' ) && Atlas_Chuti_Polylang_Bridge::is_active() ) {
			$locale = Atlas_Chuti_Polylang_Bridge::current_locale();
			if ( $locale ) {
				return $locale;
			}
		}
		/**
		 * Filters the locale used to scope front-end queries and admin lookups when
		 * neither the host map nor a multilingual plugin resolved one. Lets a
		 * dev/staging setup (or tests) override the default without editing this file.
		 */
		return apply_filters( 'atlas_chuti_current_locale', self::DEFAULT_LOCALE );
	}

	/**
	 * KROK 4, item 16 of the brief: normalizes an INPUT locale spelling to our
	 * canonical internal tag. Accepts our own existing cs-CZ/en form AND the
	 * WordPress/Polylang-style underscore form (cs_CZ, en_US) the brief's own JSON
	 * examples use — either spelling in a JSON import resolves to the exact same
	 * stored value, so "cs_CZ" and "cs-CZ" content can never accidentally end up
	 * scoped as two different locales. Returns null for anything else (an
	 * "unsupported locale", per the brief — the caller turns that into a hard
	 * validation error, see class-json-importer.php's is_valid_locale()).
	 *
	 * Why the codebase's OWN internal representation isn't renamed to cs_CZ/en_US
	 * verbatim: that string is a hardcoded lookup key in MANY places already shipped
	 * and tested (Atlas_Chuti_Taxonomy_Labels' whole label table, Atlas_Chuti_Units'
	 * label table, every existing sample-data/production-data JSON file, the whole
	 * KROK 1-3 test suite) — renaming it would be a wide, purely-cosmetic, real-risk
	 * change for no functional gain, since Polylang itself is only ever talked to via
	 * its own 2-letter SLUGS ('cs'/'en', see class-polylang-bridge.php), never via
	 * this internal tag — so nothing about real Polylang interop actually requires
	 * the rename. See the report's "Zvolená multilingual architektura" section.
	 */
	public static function normalize_locale( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		$map = array(
			'cs-cz' => 'cs-CZ',
			'cs_cz' => 'cs-CZ',
			'cs'    => 'cs-CZ',
			'en-us' => 'en',
			'en_us' => 'en',
			'en-gb' => 'en',
			'en_gb' => 'en',
			'en'    => 'en',
		);
		return $map[ $raw ] ?? null;
	}

	/**
	 * Backfills atlas_locale/atlas_translation_group/atlas_translation_status on every
	 * save (admin edit or importer) so the contract holds even for content nobody
	 * explicitly set these fields on. Never overwrites a value that's already there.
	 * The importer additionally sets atlas_locale/atlas_ingredient_key/atlas_iso_code
	 * via `meta_input` at wp_insert_post() time (see class-json-importer.php) so they
	 * are already visible to OTHER save_post hooks (class-country-sync.php,
	 * class-ingredient-sync.php) that run before this one — this hook is the backfill
	 * for content that skipped that path (admin UI, older data).
	 */
	public function ensure_i18n_meta( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( '' === get_post_meta( $post_id, 'atlas_locale', true ) ) {
			update_post_meta( $post_id, 'atlas_locale', self::DEFAULT_LOCALE );
		}
		if ( '' === get_post_meta( $post_id, 'atlas_translation_group', true ) && $post->post_name ) {
			update_post_meta( $post_id, 'atlas_translation_group', $post->post_name );
		}
		if ( 'atlas_ingredient' !== $post->post_type && '' === get_post_meta( $post_id, 'atlas_translation_status', true ) ) {
			update_post_meta( $post_id, 'atlas_translation_status', 'published' );
		}
		if ( 'atlas_ingredient' === $post->post_type && '' === get_post_meta( $post_id, 'atlas_ingredient_key', true ) && $post->post_name ) {
			update_post_meta( $post_id, 'atlas_ingredient_key', $post->post_name );
		}
		// KROK 4: recipe_key is a SEPARATE stable identity from translation_group (see
		// class-json-importer.php's resolve_recipe_key()/stable_key_for() docblocks) —
		// the importer always sets atlas_recipe_key explicitly and never reaches this
		// fallback (a missing/invalid recipe_key is a hard import error); this backfill
		// exists only for a recipe created/edited directly in wp-admin, which has no
		// recipe_key UI field yet, so it would otherwise stay permanently blank and
		// break dedup/Kulinářský pas/future CZ-EN pairing for that post.
		if ( 'atlas_recipe' === $post->post_type && '' === get_post_meta( $post_id, 'atlas_recipe_key', true ) && $post->post_name ) {
			update_post_meta( $post_id, 'atlas_recipe_key', $post->post_name );
		}
	}

	public static function get_locale( $post_id ) {
		$locale = get_post_meta( $post_id, 'atlas_locale', true );
		return $locale ? $locale : self::DEFAULT_LOCALE;
	}

	public static function get_translation_group( $post_id ) {
		$group = get_post_meta( $post_id, 'atlas_translation_group', true );
		return $group ? $group : get_post_field( 'post_name', $post_id );
	}

	public static function get_translation_status( $post_id ) {
		$status = get_post_meta( $post_id, 'atlas_translation_status', true );
		return $status ? $status : 'published';
	}

	/**
	 * Resolves a country by its stable identity — ISO 3166-1 alpha-2/3 code — WITHIN
	 * one locale. Once a second locale exists, "IT" alone is ambiguous (it names two
	 * posts: cs-CZ Itálie and en Italy); $locale disambiguates which post is meant.
	 * Defaults to the current request's locale so callers never resolve blind.
	 */
	public static function find_country_by_iso( $iso_code, $locale = null ) {
		if ( ! $iso_code ) {
			return null;
		}
		$locale = $locale ?: self::current_locale();
		$posts  = get_posts(
			array(
				'post_type'      => 'atlas_country',
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_query'     => array(
					array( 'key' => 'atlas_iso_code', 'value' => strtoupper( $iso_code ), 'compare' => '=' ),
					array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' ),
				),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * KROK 4: resolves a recipe by its recipe_key — the stable, LOCALE-SHARED concept
	 * identity (e.g. "spaghetti_carbonara" names the SAME dish in both the cs-CZ and
	 * en posts; recipe_key + locale together identify one specific post), scoped to
	 * one locale exactly like find_by_translation_group() below, just reading
	 * atlas_recipe_key instead — recipe_key and translation_group are genuinely
	 * separate meta keys now (see class-json-importer.php's import_recipe()).
	 */
	public static function find_by_recipe_key( $key, $locale = null ) {
		if ( ! $key ) {
			return null;
		}
		$locale = $locale ?: self::current_locale();
		$posts  = get_posts(
			array(
				'post_type'      => 'atlas_recipe',
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_query'     => array(
					array( 'key' => 'atlas_recipe_key', 'value' => $key, 'compare' => '=' ),
					array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' ),
				),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Resolves a recipe/glossary entry by its language-independent translation_group,
	 * scoped to one locale (defaults to the current request's locale — never resolve
	 * without one, per item 3 of this phase's brief).
	 */
	public static function find_by_translation_group( $post_type, $group, $locale = null ) {
		if ( ! $group ) {
			return null;
		}
		$locale = $locale ?: self::current_locale();
		$posts  = get_posts(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_query'     => array(
					array( 'key' => 'atlas_translation_group', 'value' => $group, 'compare' => '=' ),
					array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' ),
				),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Resolves a normalized ingredient by its language-neutral key ("tomato"), scoped
	 * to one locale, never by the localized slug ("rajce").
	 */
	public static function find_ingredient_by_key( $key, $locale = null ) {
		if ( ! $key ) {
			return null;
		}
		$locale = $locale ?: self::current_locale();
		$posts  = get_posts(
			array(
				'post_type'      => 'atlas_ingredient',
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_query'     => array(
					array( 'key' => 'atlas_ingredient_key', 'value' => $key, 'compare' => '=' ),
					array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' ),
				),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Central locale scoping (item 6 of this phase's brief): every front-end query for
	 * a locale-bearing post type is automatically restricted to the current locale, so
	 * once a second locale is active, cs-CZ pages never show en content and vice versa
	 * — without editing every individual query site in the theme. A query that already
	 * carries its own atlas_locale meta_query clause (the JSON importer's explicit,
	 * locale-threaded lookups) is left untouched so this never fights an intentional
	 * cross-locale or admin lookup.
	 */
	public function scope_query_to_locale( $query ) {
		if ( is_admin() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( ! $post_type || 'any' === $post_type ) {
			return;
		}
		$post_types = (array) $post_type;
		$localized  = array_intersect( $post_types, self::LOCALIZED_POST_TYPES );
		if ( count( $localized ) !== count( $post_types ) ) {
			// Mixed with a non-localized post type (or querying something else entirely) —
			// leave alone rather than guess.
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );
		if ( $this->meta_query_has_locale_clause( $meta_query ) ) {
			return;
		}

		$locale = self::current_locale();
		if ( self::DEFAULT_LOCALE === $locale ) {
			// KROK 4, item 16: legacy content with NO atlas_locale meta at all (it
			// predates this backfill, or 'post'/'page' content nobody has re-saved
			// since 'post' joined LOCALIZED_POST_TYPES) is treated as belonging to the
			// default locale — it must never silently vanish from Czech queries just
			// because it's missing a meta key that didn't exist yet when it was
			// created. This OR only applies when querying for the DEFAULT locale; a
			// query for 'en' still requires an explicit atlas_locale=en match, so
			// legacy/locale-less content is never mistaken for English content.
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' ),
				array( 'key' => 'atlas_locale', 'compare' => 'NOT EXISTS' ),
			);
		} else {
			$meta_query[] = array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' );
		}
		$query->set( 'meta_query', $meta_query );
	}

	private function meta_query_has_locale_clause( $meta_query ) {
		foreach ( $meta_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}
			if ( isset( $clause['key'] ) && 'atlas_locale' === $clause['key'] ) {
				return true;
			}
			if ( $this->meta_query_has_locale_clause( $clause ) ) {
				return true;
			}
		}
		return false;
	}
}
