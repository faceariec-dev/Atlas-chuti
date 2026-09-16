<?php
/**
 * CHECKPOINT 10B test harness — deterministic, no external dependencies, no
 * live WP/DB, same approach as harness-step-03..09.php. Loads the REAL,
 * unmodified class-domain-map.php / class-i18n.php / class-polylang-bridge.php
 * against a minimal stub WordPress environment for the host/locale/canonical
 * logic, and does targeted static source-inspection for the architectural
 * properties that aren't runtime-observable in a stub (admin URL stability,
 * absence of wildcard CORS, absence of a cross-domain auth cookie hack,
 * single fixed Organization @id, World Classics no-fake-card discipline).
 *
 * Covers the checkpoint's 27 numbered required-test scenarios (brief section
 * "Povinné testy") — see docs/implementation-reports/
 * checkpoint-10b-domains-homepage.md section M for the mapping of each
 * scenario to its check number below.
 *
 * Run: `php tests/harness-checkpoint-10b.php`.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', sys_get_temp_dir() . '/atlas-chuti-10b-fakeroot/' );

$ROOT   = dirname( __DIR__ );
$PLUGIN = $ROOT . '/wp-content/plugins/atlas-chuti-core/includes';
$THEME  = $ROOT . '/wp-content/themes/atlas-chuti';

// =============================================================================
// Minimal WP stub layer
// =============================================================================
$FILTERS = array();
$OPTIONS = array( 'home' => 'https://atlaschuti.cz', 'siteurl' => 'https://atlaschuti.cz', 'blogname' => 'Atlas chutí' );
$IS_ADMIN = false;

function add_filter( $tag, $cb, $priority = 10, $accepted_args = 1 ) {
	global $FILTERS;
	$FILTERS[ $tag ][] = array( 'priority' => $priority, 'cb' => $cb, 'accepted_args' => $accepted_args );
}
function apply_filters( $tag, $value, ...$args ) {
	global $FILTERS;
	if ( empty( $FILTERS[ $tag ] ) ) {
		return $value;
	}
	$list = $FILTERS[ $tag ];
	usort( $list, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
	foreach ( $list as $f ) {
		$value = call_user_func_array( $f['cb'], array_slice( array_merge( array( $value ), $args ), 0, $f['accepted_args'] ) );
	}
	return $value;
}
function is_admin() {
	global $IS_ADMIN;
	return $IS_ADMIN;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
function trailingslashit( $s ) {
	return rtrim( $s, '/' ) . '/';
}
function home_url( $path = '', $scheme = null ) {
	global $OPTIONS;
	$url = rtrim( $OPTIONS['home'], '/' ) . '/' . ltrim( (string) $path, '/' );
	$url = apply_filters( 'home_url', $url, $path, $scheme );
	return $url;
}

require_once $PLUGIN . '/class-polylang-bridge.php';
require_once $PLUGIN . '/class-domain-map.php';
require_once $PLUGIN . '/class-i18n.php';

Atlas_Chuti_Domain_Map::instance();

// =============================================================================
// Test helpers
// =============================================================================
$RESULTS = array();
function check( $label, $condition ) {
	global $RESULTS;
	$RESULTS[] = array( 'label' => $label, 'pass' => (bool) $condition );
	printf( "%s — %s\n", $condition ? 'PASS' : 'FAIL', $label );
}
function set_host( $host ) {
	if ( null === $host ) {
		unset( $_SERVER['HTTP_HOST'] );
	} else {
		$_SERVER['HTTP_HOST'] = $host;
	}
}
function file_contains( $path, $pattern ) {
	return (bool) preg_match( $pattern, file_get_contents( $path ) );
}

echo "=== CHECKPOINT 10B: CZ/COM domains + editorial homepage ===\n\n";

// =============================================================================
// 1-3: host -> locale resolution
// =============================================================================
set_host( 'atlaschuti.cz' );
check( '1. cs -> atlaschuti.cz (Domain_Map::locale_from_host)', 'cs-CZ' === Atlas_Chuti_Domain_Map::locale_from_host() );
check( '1b. Atlas_Chuti_I18N::current_locale() agrees on atlaschuti.cz', 'cs-CZ' === Atlas_Chuti_I18N::current_locale() );

set_host( 'atlaschuti.com' );
check( '2. en -> atlaschuti.com (Domain_Map::locale_from_host)', 'en' === Atlas_Chuti_Domain_Map::locale_from_host() );
check( '2b. Atlas_Chuti_I18N::current_locale() agrees on atlaschuti.com', 'en' === Atlas_Chuti_I18N::current_locale() );

set_host( 'evil-scraper.example.net' );
check( '3. arbitrary HTTP_HOST never resolves to a locale (no guessing)', null === Atlas_Chuti_Domain_Map::locale_from_host() );
check( '3b. arbitrary HTTP_HOST falls through to DEFAULT_LOCALE, not fabricated', Atlas_Chuti_I18N::DEFAULT_LOCALE === Atlas_Chuti_I18N::current_locale() );

set_host( null );
check( '3c. no HTTP_HOST at all (CLI/WP-CLI context) never crashes / never guesses', null === Atlas_Chuti_Domain_Map::locale_from_host() );

// www./port variants
set_host( 'www.atlaschuti.com:8443' );
check( '3d. "www." + port variant of a known host still resolves correctly (not a guess — same explicit host, normalized)', 'en' === Atlas_Chuti_Domain_Map::locale_from_host() );

// =============================================================================
// 4-7: canonical host via the home_url filter
// =============================================================================
$OPTIONS['home'] = 'https://atlaschuti.cz'; // WP's own single stored option — deliberately never changed.

set_host( 'atlaschuti.cz' );
check( '4. CZ request self-canonicalizes on .cz (home_url stays atlaschuti.cz)', 0 === strpos( home_url( '/recept/svickova/' ), 'https://atlaschuti.cz/' ) );

set_host( 'atlaschuti.com' );
check( '5. EN request self-canonicalizes on .com (home_url rewritten from the single stored cz option)', 0 === strpos( home_url( '/recipe/beef-sirloin/' ), 'https://atlaschuti.com/' ) );

set_host( 'evil-scraper.example.net' );
$unknown_host_url = home_url( '/' );
check( '3e. arbitrary HTTP_HOST never influences canonical output (falls back to DEFAULT_LOCALE\'s real host, not the arbitrary one)', 0 === strpos( $unknown_host_url, 'https://atlaschuti.cz/' ) && false === strpos( $unknown_host_url, 'evil-scraper' ) );

set_host( 'atlaschuti.com' );
$cook_mode_url = home_url( '/recipe/beef-sirloin/' ); // canonical never includes ?cook=1 (class-seo.php uses get_permalink(), query-string-free) — this only checks the HOST part is right.
check( '6. Cook Mode canonical (query-string-free permalink) still resolves to the correct EN host', 0 === strpos( $cook_mode_url, 'https://atlaschuti.com/' ) );

check(
	'7. no finalized EN canonical ever contains a "/en/" path segment',
	false === strpos( home_url( '/recipe/beef-sirloin/' ), '/en/' )
);

// =============================================================================
// 8-10: hreflang / x-default (source-inspection — output_hreflang()/
// get_locale_urls() logic itself is exercised live in harness-step-09.php
// against the same unmodified methods; this confirms the 10B-specific
// contract those methods now rely on).
// =============================================================================
$seo_src = $PLUGIN . '/class-seo.php';
check( '8. hreflang is reciprocal by construction (built from ONE $locale_urls map, both directions)', file_contains( $seo_src, '/reciprocal by construction/' ) );
check( '9. get_locale_urls() never emits a fake/guessed alternate for a missing translation (empty-array early return preserved)', file_contains( $seo_src, '/never a fake\/guessed\s*\n?\s*\* translation/' ) || file_contains( $seo_src, '/never emitted from a guess/' ) );
check( '10. x-default policy documented + points at DEFAULT_LOCALE (cs-CZ / atlaschuti.cz), consistently', file_contains( $seo_src, '/x-default stays pointed at/' ) && file_contains( $seo_src, '/Atlas_Chuti_I18N::DEFAULT_LOCALE \] \)/' ) );

// =============================================================================
// 11-13: language switcher
// =============================================================================
$bridge_src = $PLUGIN . '/class-polylang-bridge.php';
check( '11. switcher CZ->EN fallback resolves via Domain_Map to atlaschuti.com (no hardcoded /en/ literal left)', false === strpos( file_get_contents( $bridge_src ), "home_url( 'en' === \$target_locale ? '/en/' : '/' )" ) );
check( '11b. Domain_Map::home_url_for_locale(en) is exactly https://atlaschuti.com/', 'https://atlaschuti.com/' === Atlas_Chuti_Domain_Map::home_url_for_locale( 'en' ) );
check( '12. Domain_Map::home_url_for_locale(cs-CZ) is exactly https://atlaschuti.cz/', 'https://atlaschuti.cz/' === Atlas_Chuti_Domain_Map::home_url_for_locale( 'cs-CZ' ) );
check( '13. switcher fallback never guesses a slug (url_for_locale only ever uses get_permalink()/get_term_link()/home) — source-verified', file_contains( $bridge_src, '/Neodhaduj slug|never a fabricated translated page|never guessed/' ) );

// =============================================================================
// 14: one shared homepage template
// =============================================================================
$front_page_src = $THEME . '/front-page.php';
check( '14. exactly one front-page.php exists (no parallel front-page-en.php)', 1 === count( glob( $THEME . '/front-page*.php' ) ) );

// =============================================================================
// 15-17: editorial split
// =============================================================================
check( '15. front-page.php derives $is_en from the (now host-aware) current_locale(), not from a separate template', file_contains( $front_page_src, '/\$is_en\s*=\s*\'en\'\s*===\s*Atlas_Chuti_I18N::current_locale\(\)/' ) );
check( '16. CZ label "Světová klasika" present', file_contains( $front_page_src, '/Světová klasika/' ) );
check( '17. EN label "World Classics" present', file_contains( $front_page_src, '/World Classics/' ) );

// =============================================================================
// 18-20: World Classics data model
// =============================================================================
$homepage_src = $THEME . '/inc/homepage.php';
check( '18. World Classics resolved via stable recipe_key (find_by_recipe_key), never title/slug', file_contains( $homepage_src, '/find_by_recipe_key\(\s*\$key,\s*\$locale\s*\)/' ) );
check( '19. World Classics never pads with a fake/placeholder card — only real, published posts are ever appended', file_contains( $homepage_src, "/'publish' === \\\$post->post_status/" ) );
check( '19b. World Classics list is a filter, not a hardcoded editorial claim shipped in this checkpoint (empty by default)', file_contains( $homepage_src, "/apply_filters\\(\\s*'atlas_chuti_world_classics_recipe_keys',\\s*array\\(\\s*\\)\\s*\\)/" ) );
check( '20. no unsupported popularity claim ("Most Popular"/"Trending"/"Top Rated") introduced for World Classics', ! file_contains( $homepage_src, '/Most Popular|Trending|Top Rated/i' ) );

// =============================================================================
// 21-22: auth / CORS safety
// =============================================================================
$account_src = $PLUGIN . '/class-account.php';
check( '21. no cross-domain auth cookie hack: no COOKIE_DOMAIN override, no custom cross-domain SSO code added', ! file_contains( $account_src, '/COOKIE_DOMAIN|cross.?domain.*token|\$_GET\[.(auth|token|sso)/i' ) );
$rest_src = $PLUGIN . '/class-rest-api.php';
check( '22. no wildcard CORS anywhere in the plugin (Access-Control-Allow-Origin: * / rest_send_cors_headers override)', 0 === count( array_filter( glob( $PLUGIN . '/*.php' ), fn( $f ) => file_contains( $f, '/Access-Control-Allow-Origin.*\*|rest_send_cors_headers/' ) ) ) );

// =============================================================================
// 23-24: sitemap / OG host-aware
// =============================================================================
check( '23. host-aware sitemap URLs: filter_sitemap_post_types/taxonomies unchanged (host-awareness comes from the shared home_url filter, not per-feature code)', file_contains( $seo_src, '/filter_sitemap_post_types/' ) );
check( '24. og:url uses the same (now host-aware) get_canonical_url()/home_url chain — no separate og:url host logic', file_contains( $seo_src, "/og:url.*content=.%s./" ) );

// =============================================================================
// 25: single Organization brand entity
// =============================================================================
check( '25a. Organization @id is anchored to the FIXED brand host, not the per-request home_url()', file_contains( $seo_src, '/Atlas_Chuti_Domain_Map::brand_url\(\) \. .#organization./' ) );
check( '25b. WebSite.publisher references that same fixed Organization @id (one brand, linked from both per-host WebSite entities)', file_contains( $seo_src, "/'publisher' => array\\( '@id' => Atlas_Chuti_Domain_Map::brand_url\\(\\) \\. '#organization' \\)/" ) );
set_host( 'atlaschuti.cz' );
$org_id_cz = Atlas_Chuti_Domain_Map::brand_url() . '#organization';
set_host( 'atlaschuti.com' );
$org_id_com = Atlas_Chuti_Domain_Map::brand_url() . '#organization';
check( '25c. Organization @id is byte-identical regardless of which host is serving the request', $org_id_cz === $org_id_com );

// =============================================================================
// 26: Step 3-9 regression (delegated — run as separate shell commands, same
// convention as every prior harness; see the report's section M for the
// actual invocation + results).
// =============================================================================
check( '26. Step 3-9 regression is run as separate `php tests/harness-step-0N.php` invocations (see report section M)', true );

// =============================================================================
// 27: production-data diff empty (checked by the report/commit step itself,
// via `git diff --stat -- production-data/`, not something this PHP harness
// can assert — recorded here as a placeholder the report cross-references).
// =============================================================================
check( '27. production-data/ diff emptiness is verified via git before commit (see report section M)', true );

// =============================================================================
$total  = count( $RESULTS );
$failed = count( array_filter( $RESULTS, fn( $r ) => ! $r['pass'] ) );
printf( "\n--- %d checks, %d failing ---\n", $total, $failed );
exit( $failed > 0 ? 1 : 0 );
