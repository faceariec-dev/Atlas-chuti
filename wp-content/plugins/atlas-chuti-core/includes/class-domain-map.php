<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CHECKPOINT 10B: explicit, code-owned host↔locale mapping for the new
 * atlaschuti.cz (cs-CZ) / atlaschuti.com (en) architecture — ONE WordPress,
 * ONE database, ONE theme, ONE core plugin, TWO canonical domains.
 *
 * Why this exists instead of relying on Polylang's own "different domains"
 * URL mode: that mode's exact availability/edition and its live wp-admin
 * configuration cannot be verified from this repository (Polylang is a
 * third-party plugin, never vendored here — every touch point in this
 * codebase already only ever calls it through function_exists() guards, see
 * class-polylang-bridge.php). Whether or not that Polylang feature is
 * licensed/enabled, THIS class is the single explicit source of truth this
 * plugin owns for "which host serves which locale" and "what host should a
 * URL for locale X use" — safe, testable, and correct with or without
 * Polylang's own domain routing layered on top of it.
 *
 * Explicit allowlist only (brief item: "Nevyráběj canonical z libovolného
 * $_SERVER['HTTP_HOST']"). An unrecognized host (localhost, a staging
 * domain, WP-CLI/test context with no HTTP_HOST at all) is simply not
 * resolved here — every caller falls through to the existing pre-10B
 * behavior (Polylang bridge, then the `atlas_chuti_current_locale` filter
 * default). This is deliberate: it is what makes this whole layer a
 * zero-regression addition for every existing Step 3-9 test and for any
 * dev/staging environment that isn't actually atlaschuti.cz/.com.
 */
class Atlas_Chuti_Domain_Map {

	/**
	 * The one explicit mapping this checkpoint introduces. Extending to a
	 * third locale/domain later is a one-line addition here — nothing else
	 * in this class (or its callers) hardcodes the pair.
	 */
	const HOST_FOR_LOCALE = array(
		'cs-CZ' => 'atlaschuti.cz',
		'en'    => 'atlaschuti.com',
	);

	/**
	 * The canonical host the single, brand-level Organization JSON-LD entity
	 * is anchored to (see class-seo.php's output_schema()) — MUST be a fixed
	 * host, never the current request's host, or atlaschuti.cz and
	 * atlaschuti.com would each mint their own "@id", producing two
	 * different Organizations for one brand instead of one shared identity
	 * (item: "Brand je jeden — nevytvářej dvě falešně odlišné
	 * Organizations."). cs-CZ is the project's original, already-launched
	 * identity (Atlas_Chuti_I18N::DEFAULT_LOCALE), so its host is the
	 * natural fixed anchor; nothing about this choice is load-bearing for
	 * SEO (both domains still each get their own WebSite entity, see
	 * class-seo.php) — it is purely "one stable @id for the shared brand".
	 */
	const BRAND_HOST = 'atlaschuti.cz';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Only ever rewrites the PUBLIC front-end URL base. Deliberately does
		// NOT filter `site_url`/`admin_url` — wp-admin, REST route
		// registration internals, and login must stay on whichever single
		// host WordPress's own `siteurl` option already points at, so the
		// admin never "chaoticky přeskakuje mezi hosty" (brief's explicit
		// admin-stability requirement). `home_url()` alone is what
		// get_permalink()/get_pagenum_link()/get_term_link()/get_rest_url()/
		// the core sitemap provider/og:url all ultimately build on, so this
		// one filter is enough to make canonical, hreflang, OG, sitemap URLs
		// AND same-origin REST calls all host-aware together.
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 3 );
	}

	/**
	 * Every known, explicit host this project answers requests on today,
	 * normalized (lowercase, no leading "www."). Filterable so a staging
	 * environment can safely declare its own extra host(s) — e.g. a
	 * pre-launch QA domain that should behave like "en" — without touching
	 * this file (brief item: "Development/staging host musí mít bezpečný
	 * config/filter override").
	 */
	public static function host_locale_map() {
		$map = array();
		foreach ( self::HOST_FOR_LOCALE as $locale => $host ) {
			$map[ self::normalize_host( $host ) ] = $locale;
		}
		/**
		 * Filters the full host→locale allowlist. A staging config can add
		 * entries (e.g. 'staging-en.example.com' => 'en'); it can also, in
		 * principle, remove/override the production hosts for a local dev
		 * clone — but the DEFAULT here is always exactly the two production
		 * hosts, never guessed from the current request.
		 */
		return apply_filters( 'atlas_chuti_domain_map', $map );
	}

	private static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '/^www\./', '', $host );
		// Strip a port for comparison (e.g. "atlaschuti.cz:8080" in a local/
		// staging reverse-proxy setup still resolves to the right locale).
		$host = preg_replace( '/:\d+$/', '', $host );
		return $host;
	}

	/**
	 * The actual incoming request host, or '' outside an HTTP request (CLI,
	 * WP-CLI, PHPUnit/this project's own harnesses) — never fabricated.
	 * Exposed as its own method (rather than reading the superglobal inline
	 * everywhere) so tests can filter it deterministically.
	 */
	public static function current_request_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read-only host lookup, normalized below, never echoed raw.
		/**
		 * Lets a test harness (or a reverse-proxy/staging setup that
		 * terminates TLS before HTTP_HOST is trustworthy) supply the
		 * effective host explicitly instead of trusting the raw superglobal.
		 */
		return apply_filters( 'atlas_chuti_current_request_host', self::normalize_host( $host ) );
	}

	/**
	 * The locale for the CURRENT request's host, or null when that host
	 * isn't in the explicit allowlist (see host_locale_map()) — null is the
	 * "fall through to existing behavior" signal every caller relies on.
	 */
	public static function locale_from_host() {
		$host = self::current_request_host();
		if ( '' === $host ) {
			return null;
		}
		$map = self::host_locale_map();
		return $map[ $host ] ?? null;
	}

	/**
	 * The canonical host for a given locale — e.g. for building the
	 * cross-domain hreflang/switcher URL of a locale that ISN'T the current
	 * request's. Falls back to the current request's own host for an
	 * unmapped locale (never fabricates a third domain).
	 */
	public static function host_for_locale( $locale ) {
		$default = self::HOST_FOR_LOCALE[ $locale ] ?? self::current_request_host();
		/**
		 * Filters the host used for a given locale's URLs. Same override
		 * point as host_locale_map(), kept separate because this is the
		 * "locale -> host" direction, used when GENERATING a URL rather than
		 * resolving one.
		 */
		return apply_filters( 'atlas_chuti_host_for_locale', $default, $locale );
	}

	/**
	 * A locale's home URL on ITS OWN canonical host — used by the language
	 * switcher / Polylang bridge fallback when there's no exact translation
	 * to link to (never a fabricated deep link, matches the existing "safe
	 * fallback = that locale's home page" policy in class-polylang-bridge.php).
	 */
	public static function home_url_for_locale( $locale ) {
		$host = self::host_for_locale( $locale );
		if ( ! $host ) {
			return home_url( '/' );
		}
		return 'https://' . $host . '/';
	}

	/**
	 * The single, host-FIXED URL the brand-level Organization JSON-LD entity
	 * is anchored to — see BRAND_HOST's docblock. Never varies with the
	 * current request's host.
	 */
	public static function brand_url() {
		return apply_filters( 'atlas_chuti_brand_url', 'https://' . self::BRAND_HOST . '/' );
	}

	/**
	 * WordPress core's `home_url` filter. Only swaps the HOST (and forces
	 * https, matching how both production domains are actually served) when
	 * the CURRENT locale resolves to a known, different host than the one
	 * already in $url — i.e. this is a no-op whenever WordPress's own
	 * `home`/`siteurl` option already matches the locale being rendered
	 * (the common case for a single-domain dev/staging install), and it
	 * never runs at all in wp-admin (item: admin never jumps host).
	 */
	public function filter_home_url( $url, $path, $orig_scheme ) {
		if ( is_admin() ) {
			return $url;
		}
		$locale = Atlas_Chuti_I18N::current_locale();
		$host   = self::HOST_FOR_LOCALE[ $locale ] ?? null;
		if ( ! $host ) {
			// Unknown/unsupported locale (shouldn't happen given
			// SUPPORTED_LOCALES, but never guess a host for it) — leave the
			// URL exactly as WordPress built it.
			return $url;
		}

		$parsed = wp_parse_url( $url );
		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return $url;
		}
		if ( self::normalize_host( $parsed['host'] ) === self::normalize_host( $host ) ) {
			return $url; // Already correct — the common case.
		}

		$new_url            = $url;
		$new_url            = preg_replace( '#^[a-z][a-z0-9+.-]*://[^/]+#i', 'https://' . $host, $new_url, 1 );
		return $new_url ? $new_url : $url;
	}
}
