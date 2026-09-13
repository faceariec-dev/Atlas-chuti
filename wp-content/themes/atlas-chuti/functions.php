<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATLAS_THEME_VERSION', '1.0.0' );
define( 'ATLAS_THEME_DIR', get_template_directory() );
define( 'ATLAS_THEME_URL', get_template_directory_uri() );

/**
 * Theme setup: the data model lives in atlas-chuti-core (item 3 of the brief), this
 * theme only owns presentation.
 */
function atlas_chuti_setup() {
	load_theme_textdomain( 'atlas-chuti', ATLAS_THEME_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'automatic-feed-links' );

	register_nav_menus(
		array(
			'primary' => __( 'Hlavní menu', 'atlas-chuti' ),
			'footer-discover' => __( 'Footer – Objevujte', 'atlas-chuti' ),
			'footer-tools'    => __( 'Footer – Nástroje', 'atlas-chuti' ),
			'footer-about'    => __( 'Footer – O webu', 'atlas-chuti' ),
			'footer-legal'    => __( 'Footer – Informace', 'atlas-chuti' ),
		)
	);

	// Card (4:3), tall card (5:4), hero (16:9), square (1:1). Cropped so a mixed
	// photo library still lines up in grids. atlas-square exists mainly so Recipe
	// structured data (class-seo.php) can offer a real 1:1 image variant alongside
	// the 16:9/4:3 ones Google's Recipe rich results look for — not used in any
	// grid layout today.
	add_image_size( 'atlas-card', 640, 480, true );
	add_image_size( 'atlas-card-tall', 640, 512, true );
	add_image_size( 'atlas-hero', 1600, 900, true );
	add_image_size( 'atlas-square', 1200, 1200, true );
}
add_action( 'after_setup_theme', 'atlas_chuti_setup' );

function atlas_chuti_enqueue_assets() {
	atlas_chuti_enqueue_fonts();

	wp_enqueue_style( 'atlas-chuti-main', ATLAS_THEME_URL . '/assets/css/main.css', array(), ATLAS_THEME_VERSION );

	wp_enqueue_script( 'atlas-chuti-nav', ATLAS_THEME_URL . '/assets/js/nav.js', array(), ATLAS_THEME_VERSION, true );

	if ( is_singular( 'atlas_recipe' ) ) {
		wp_enqueue_script( 'atlas-chuti-servings', ATLAS_THEME_URL . '/assets/js/servings.js', array(), ATLAS_THEME_VERSION, true );

		// A separate localized object (not AtlasChutiL10n) so this never collides with
		// the passport.js localization also enqueued on this template (item 7 of KROK 2).
		wp_enqueue_script( 'atlas-chuti-recipe-actions', ATLAS_THEME_URL . '/assets/js/recipe-actions.js', array(), ATLAS_THEME_VERSION, true );
		wp_localize_script(
			'atlas-chuti-recipe-actions',
			'AtlasChutiShareL10n',
			array(
				'shareLinkCopied' => __( 'Odkaz zkopírován', 'atlas-chuti' ),
				'shareCopyPrompt' => __( 'Zkopírujte odkaz:', 'atlas-chuti' ),
			)
		);
	}

	if ( is_singular( 'atlas_recipe' ) || is_singular( 'atlas_country' ) || is_page_template( 'template-passport.php' ) || is_front_page() ) {
		wp_enqueue_script( 'atlas-chuti-passport', ATLAS_THEME_URL . '/assets/js/passport.js', array(), ATLAS_THEME_VERSION, true );

		// UI strings passport.js renders client-side (item 17 of the brief: JS text
		// must be localization-ready too, never hardcoded Czech in the .js file itself).
		wp_localize_script(
			'atlas-chuti-passport',
			'AtlasChutiL10n',
			array(
				'recipeMarkCooked'  => __( 'Uvařil/a jsem', 'atlas-chuti' ),
				'recipeCooked'      => __( 'Uvařeno', 'atlas-chuti' ),
				'countryMarkTasted' => __( 'Označit jako ochutnané', 'atlas-chuti' ),
				'countryTasted'     => __( 'Ochutnáno', 'atlas-chuti' ),
				'confirmClear'      => __( 'Opravdu chcete vymazat celý Kulinářský pas? Tuto akci nelze vrátit zpět.', 'atlas-chuti' ),
				'noCookedRecipes'   => __( 'Zatím jste žádný recept neoznačili jako uvařený. Otevřete recept a klikněte na „Uvařil/a jsem“.', 'atlas-chuti' ),
				/* translators: %d: number of countries the visitor has tasted so far */
				'countsFormat'      => __( '%1$d / %2$d zemí', 'atlas-chuti' ),
			)
		);

		if ( is_page_template( 'template-passport.php' ) || is_front_page() ) {
			wp_localize_script( 'atlas-chuti-passport', 'AtlasChutiContinents', atlas_chuti_continent_totals() );
		}
	}

	if ( is_post_type_archive( 'atlas_recipe' ) ) {
		wp_enqueue_script( 'atlas-chuti-filters', ATLAS_THEME_URL . '/assets/js/filters.js', array(), ATLAS_THEME_VERSION, true );
	}
}
add_action( 'wp_enqueue_scripts', 'atlas_chuti_enqueue_assets' );

/**
 * Self-hosts Newsreader/Manrope when the woff2 files are present under
 * assets/fonts (see assets/fonts/README.md), otherwise falls back to the Google
 * Fonts CDN so the site still looks right out of the box. Self-hosting avoids the
 * extra external request per item 5 of the brief.
 */
function atlas_chuti_enqueue_fonts() {
	$local_css = ATLAS_THEME_DIR . '/assets/fonts/fonts.css';
	if ( file_exists( $local_css ) ) {
		wp_enqueue_style( 'atlas-chuti-fonts', ATLAS_THEME_URL . '/assets/fonts/fonts.css', array(), ATLAS_THEME_VERSION );
		return;
	}
	wp_enqueue_style(
		'atlas-chuti-fonts',
		'https://fonts.googleapis.com/css2?family=Newsreader:opsz,wght@6..72,400;6..72,500;6..72,600;6..72,700&family=Manrope:wght@400;500;600;700&display=swap',
		array(),
		null
	);
}

/**
 * Preconnects to Google Fonts only while we're still depending on the CDN.
 */
function atlas_chuti_resource_hints( $hints, $relation_type ) {
	if ( 'preconnect' === $relation_type && ! file_exists( ATLAS_THEME_DIR . '/assets/fonts/fonts.css' ) ) {
		$hints[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	return $hints;
}
add_filter( 'wp_resource_hints', 'atlas_chuti_resource_hints', 10, 2 );

// Lazy-load images and strip the render-blocking emoji script (we don't use it).
add_filter( 'wp_lazy_loading_enabled', '__return_true' );
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );

require ATLAS_THEME_DIR . '/inc/fallback-images.php';
require ATLAS_THEME_DIR . '/inc/template-tags.php';
require ATLAS_THEME_DIR . '/inc/passport-support.php';
require ATLAS_THEME_DIR . '/inc/archive-filters.php';
require ATLAS_THEME_DIR . '/inc/homepage.php';
require ATLAS_THEME_DIR . '/inc/customizer.php';
require ATLAS_THEME_DIR . '/inc/continent-image.php';

/**
 * "Kam dnes za chutí?" (item 18) — picks one random published country and redirects
 * to it. Plain query var instead of a rewrite rule so no flush is needed on theme switch.
 */
function atlas_chuti_random_country_redirect() {
	if ( ! isset( $_GET['atlas_random_country'] ) ) {
		return;
	}
	$ids = get_posts( array( 'post_type' => 'atlas_country', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	if ( ! $ids ) {
		wp_safe_redirect( atlas_chuti_system_url( 'countries' ) );
		exit;
	}
	wp_safe_redirect( get_permalink( $ids[ array_rand( $ids ) ] ) );
	exit;
}
add_action( 'template_redirect', 'atlas_chuti_random_country_redirect' );
