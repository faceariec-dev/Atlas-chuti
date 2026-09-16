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

	// KROK 6, item 24: 5 footer groups (Objevujte/Komunita/O Atlasu/Pro partnery/
	// Právní) — footer-tools is renamed footer-community (its old items move under
	// "Komunita" per the brief's own grouping) and footer-partners is new.
	register_nav_menus(
		array(
			'primary'          => __( 'Hlavní menu', 'atlas-chuti' ),
			'footer-discover'  => __( 'Footer – Objevujte', 'atlas-chuti' ),
			'footer-community' => __( 'Footer – Komunita', 'atlas-chuti' ),
			'footer-about'     => __( 'Footer – O Atlasu', 'atlas-chuti' ),
			'footer-partners'  => __( 'Footer – Pro partnery', 'atlas-chuti' ),
			'footer-legal'     => __( 'Footer – Právní', 'atlas-chuti' ),
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
	// KROK 5, item 24: user-submitted recipe photos are NEVER served at their
	// original as-uploaded size (EXIF/metadata risk — see class-photos.php's
	// docblock) — only through this generated intermediate size, unscaled crop
	// so a portrait phone photo isn't force-cropped into a square.
	add_image_size( 'atlas-ugc', 1200, 1200, false );
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

		// KROK 8, item 1-6/47: timers.js has no dependency, cook-mode.js calls
		// window.AtlasTimers so it loads after it — both are pure client-side
		// (no REST, no AtlasChutiUser needed) and degrade to nothing at all when
		// the page has no #ingredience[data-cook-mode] section with steps.
		wp_enqueue_script( 'atlas-chuti-timers', ATLAS_THEME_URL . '/assets/js/timers.js', array(), ATLAS_THEME_VERSION, true );
		wp_enqueue_script( 'atlas-chuti-cook-mode', ATLAS_THEME_URL . '/assets/js/cook-mode.js', array( 'atlas-chuti-timers' ), ATLAS_THEME_VERSION, true );
		wp_localize_script(
			'atlas-chuti-cook-mode',
			'AtlasCookL10n',
			array(
				'cookModeLabel'       => __( 'Režim vaření', 'atlas-chuti' ),
				'close'                => __( 'Zavřít', 'atlas-chuti' ),
				'previous'             => __( 'Předchozí', 'atlas-chuti' ),
				'next'                 => __( 'Další', 'atlas-chuti' ),
				'done'                 => __( 'Hotovo', 'atlas-chuti' ),
				'ingredients'          => __( 'Ingredience', 'atlas-chuti' ),
				'setTimer'             => __( 'Nastavit časovač', 'atlas-chuti' ),
				'stepLabel'            => __( 'Krok', 'atlas-chuti' ),
				'keepScreenOn'         => __( 'Nezhasínat obrazovku', 'atlas-chuti' ),
				'wakeLockUnsupported'  => __( 'Tento prohlížeč nepodporuje ponechání obrazovky zapnuté.', 'atlas-chuti' ),
				/* translators: %1$d: steps completed, %2$d: total steps */
				'stepsDoneFormat'      => __( 'Hotovo %1$d z %2$d kroků', 'atlas-chuti' ),
				'timer'                => __( 'Časovač', 'atlas-chuti' ),
				'timerDoneTitle'       => __( 'Časovač dokončen', 'atlas-chuti' ),
				'timerDone'            => __( 'Hotovo!', 'atlas-chuti' ),
				'enableNotifications'  => __( 'Povolit upozornění', 'atlas-chuti' ),
				'pause'                => __( 'Pauza', 'atlas-chuti' ),
				'resume'               => __( 'Pokračovat', 'atlas-chuti' ),
				'removeTimer'          => __( 'Odebrat časovač', 'atlas-chuti' ),
			)
		);
	}

	if ( is_singular( 'atlas_recipe' ) || is_singular( 'post' ) ) {
		wp_enqueue_script( 'atlas-chuti-video', ATLAS_THEME_URL . '/assets/js/video.js', array(), ATLAS_THEME_VERSION, true );
	}

	if ( is_singular( 'atlas_recipe' ) || is_singular( 'atlas_country' ) || is_page_template( 'template-passport.php' ) || is_page_template( 'template-my-atlas.php' ) || is_front_page() ) {
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

		if ( is_page_template( 'template-passport.php' ) || is_front_page() || is_page_template( 'template-my-atlas.php' ) ) {
			wp_localize_script( 'atlas-chuti-passport', 'AtlasChutiContinents', atlas_chuti_continent_totals() );
		}
	}

	if ( is_post_type_archive( 'atlas_recipe' ) ) {
		wp_enqueue_script( 'atlas-chuti-filters', ATLAS_THEME_URL . '/assets/js/filters.js', array(), ATLAS_THEME_VERSION, true );
	}

	// KROK 8, item 16-18/47: "Co mám doma?" — the page works fully via plain GET
	// with no JS at all (see template-co-mam-doma.php); this only progressively
	// enhances the native <select multiple> into a filter+chips UI.
	if ( is_page_template( 'template-co-mam-doma.php' ) ) {
		wp_enqueue_script( 'atlas-chuti-ingredient-finder', ATLAS_THEME_URL . '/assets/js/ingredient-finder.js', array(), ATLAS_THEME_VERSION, true );
		wp_localize_script(
			'atlas-chuti-ingredient-finder',
			'AtlasIngredientFinderL10n',
			array(
				'filterLabel'       => __( 'Filtrovat seznam ingrediencí', 'atlas-chuti' ),
				'filterPlaceholder' => __( 'Hledat ingredienci…', 'atlas-chuti' ),
				/* translators: %s: ingredient name */
				'removeFormat'      => __( 'Odebrat %s', 'atlas-chuti' ),
			)
		);
	}

	// KROK 5: the account-aware layer (favorite/cooked toggle, rating widget,
	// photo upload, Passport localStorage→account merge) — a separate script/
	// localization object from passport.js's own AtlasPassport (item: passport.js
	// stays the pure anonymous/local mechanism, see assets/js/passport.js's
	// docblock) and from AtlasChutiShareL10n, so none of the three ever collide.
	if ( is_singular( 'atlas_recipe' ) || is_page_template( 'template-my-atlas.php' ) ) {
		// Depends on passport.js's AtlasPassport (read-only, for the localStorage→
		// account merge banner and the logged-in branch of the cooked-recipe button)
		// — both are now enqueued together wherever either page type needs them.
		wp_enqueue_script( 'atlas-chuti-my-atlas', ATLAS_THEME_URL . '/assets/js/my-atlas.js', array( 'atlas-chuti-passport' ), ATLAS_THEME_VERSION, true );
		wp_localize_script(
			'atlas-chuti-my-atlas',
			'AtlasChutiUser',
			array(
				'loggedIn'   => is_user_logged_in(),
				'restUrl'    => esc_url_raw( rest_url( 'atlas-chuti/v1' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'accountUrl' => atlas_chuti_system_url( 'account' ),
			)
		);
		wp_localize_script(
			'atlas-chuti-my-atlas',
			'AtlasChutiInteractionsL10n',
			array(
				'favorite'          => __( 'Oblíbené', 'atlas-chuti' ),
				'favorited'         => __( 'V oblíbených', 'atlas-chuti' ),
				'loginRequired'     => __( 'Pro tuto akci se prosím přihlaste.', 'atlas-chuti' ),
				'genericError'      => __( 'Něco se nepovedlo, zkuste to prosím znovu.', 'atlas-chuti' ),
				'networkError'      => __( 'Zkontrolujte prosím připojení k internetu.', 'atlas-chuti' ),
				'rateLimited'       => __( 'Příliš mnoho pokusů, zkuste to prosím za chvíli.', 'atlas-chuti' ),
				'ratingSaved'       => __( 'Děkujeme za hodnocení!', 'atlas-chuti' ),
				'noRatingsYet'      => __( 'Zatím bez hodnocení', 'atlas-chuti' ),
				/* translators: %1$s: average rating, %2$d: number of ratings */
				'ratingSummary'     => __( '%1$s z 5 (%2$d hodnocení)', 'atlas-chuti' ),
				'uploadRejected'    => __( 'Fotografii se nepodařilo nahrát.', 'atlas-chuti' ),
				'uploadTooLarge'    => __( 'Fotografie je příliš velká (max. 5 MB).', 'atlas-chuti' ),
				'uploadBadType'     => __( 'Nepodporovaný typ souboru (JPEG, PNG nebo WebP).', 'atlas-chuti' ),
				'uploadPending'     => __( 'Fotografie čeká na schválení.', 'atlas-chuti' ),
				'passportFoundTitle' => __( 'Našli jsme váš dosavadní Kulinářský pas v tomto prohlížeči.', 'atlas-chuti' ),
				'passportFoundBody' => __( 'Přidat jej do Mého Atlasu?', 'atlas-chuti' ),
				'passportMergeYes'  => __( 'Přidat do Mého Atlasu', 'atlas-chuti' ),
				'passportMergeNo'   => __( 'Ne, díky', 'atlas-chuti' ),
				'passportMerged'    => __( 'Váš Kulinářský pas byl přidán do účtu.', 'atlas-chuti' ),
			)
		);

		// KROK 8, item 20-28/47: Collections/Shopping list/Meal planner — depends on
		// 'atlas-chuti-my-atlas' purely so AtlasChutiUser (localized onto that handle
		// above) is guaranteed to exist before this file reads it.
		wp_enqueue_script( 'atlas-chuti-my-atlas-tools', ATLAS_THEME_URL . '/assets/js/my-atlas-tools.js', array( 'atlas-chuti-my-atlas' ), ATLAS_THEME_VERSION, true );
		wp_localize_script(
			'atlas-chuti-my-atlas-tools',
			'AtlasToolsL10n',
			array(
				'genericError'          => __( 'Něco se nepovedlo, zkuste to prosím znovu.', 'atlas-chuti' ),
				'loading'                => __( 'Načítám…', 'atlas-chuti' ),
				'addToCollection'        => __( 'Přidat do kolekce', 'atlas-chuti' ),
				'noCollectionsYet'       => __( 'Zatím nemáte žádnou kolekci.', 'atlas-chuti' ),
				'newCollectionName'      => __( 'Nová kolekce', 'atlas-chuti' ),
				'create'                 => __( 'Vytvořit', 'atlas-chuti' ),
				'close'                  => __( 'Zavřít', 'atlas-chuti' ),
				'addedToCollection'      => __( 'Přidáno do kolekce.', 'atlas-chuti' ),
				'addedToShoppingList'    => __( 'Přidáno', 'atlas-chuti' ),
				'confirmDeleteCollection' => __( 'Opravdu smazat tuto kolekci?', 'atlas-chuti' ),
				'pickRecipe'             => __( 'Vybrat recept', 'atlas-chuti' ),
				'searchRecipes'          => __( 'Hledat recept podle názvu…', 'atlas-chuti' ),
				/* translators: %d: number of ingredients added */
				'addedIngredientsFormat' => __( 'Přidáno %d ingrediencí', 'atlas-chuti' ),
			)
		);
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
require ATLAS_THEME_DIR . '/inc/editorial-curation.php';
require ATLAS_THEME_DIR . '/inc/customizer.php';
require ATLAS_THEME_DIR . '/inc/continent-image.php';
require ATLAS_THEME_DIR . '/inc/my-atlas.php';
require ATLAS_THEME_DIR . '/inc/recipe-community.php';
require ATLAS_THEME_DIR . '/inc/magazine.php';

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
