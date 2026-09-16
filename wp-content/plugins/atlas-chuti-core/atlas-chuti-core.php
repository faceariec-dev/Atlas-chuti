<?php
/**
 * Plugin Name: Atlas chutí – Core
 * Description: Datový model, obsahové typy, JSON importér a základní funkce projektu Atlas chutí. Nezávislé na konkrétním theme.
 * Version: 1.0.0
 * Author: Atlas chutí
 * License: GPL-2.0-or-later
 * Text Domain: atlas-chuti
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATLAS_CHUTI_VERSION', '1.0.0' );
define( 'ATLAS_CHUTI_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATLAS_CHUTI_URL', plugin_dir_url( __FILE__ ) );

require_once ATLAS_CHUTI_DIR . 'includes/class-polylang-bridge.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-i18n.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-taxonomy-labels.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-units.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-post-types.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-taxonomies.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-country-sync.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-ingredient-sync.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-meta-fields.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-register-meta.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-meta-box-base.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-recipe-meta-box.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-country-meta-box.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-glossary-meta-box.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-ingredient-meta-box.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-servings.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-search.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-seo.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-qrcode.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-json-importer.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-admin.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-continent-image.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-page-setup.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-content-audit.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-db.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-account.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-user-state.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-ratings.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-comments.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-photos.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-rest-api.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-privacy.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-magazine.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-magazine-meta-box.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-discussion.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-ad-slots.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-ad-campaign.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-ad-campaign-meta-box.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-advertising.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-advertising-settings.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-collections.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-shopping-list.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-meal-plan.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-recommendations.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-ingredient-finder.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-video.php';
require_once ATLAS_CHUTI_DIR . 'includes/class-video-meta-box.php';
require_once ATLAS_CHUTI_DIR . 'includes/functions.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ATLAS_CHUTI_DIR . 'includes/class-cli.php';
}

/**
 * Bootstraps all plugin modules on `plugins_loaded` so load order never matters.
 */
function atlas_chuti_core_init() {
	Atlas_Chuti_Polylang_Bridge::instance();
	Atlas_Chuti_I18N::instance();
	Atlas_Chuti_Post_Types::instance();
	Atlas_Chuti_Taxonomies::instance();
	Atlas_Chuti_Register_Meta::instance();
	Atlas_Chuti_Country_Sync::instance();
	Atlas_Chuti_Ingredient_Sync::instance();
	Atlas_Chuti_Recipe_Meta_Box::instance();
	Atlas_Chuti_Country_Meta_Box::instance();
	Atlas_Chuti_Glossary_Meta_Box::instance();
	Atlas_Chuti_Ingredient_Meta_Box::instance();
	Atlas_Chuti_Servings::instance();
	Atlas_Chuti_Search::instance();
	Atlas_Chuti_SEO::instance();
	Atlas_Chuti_JSON_Importer::instance();
	Atlas_Chuti_Admin::instance();
	Atlas_Chuti_Continent_Image::instance();
	Atlas_Chuti_Page_Setup::instance();
	Atlas_Chuti_Content_Audit::instance();
	Atlas_Chuti_DB::instance();
	Atlas_Chuti_Account::instance();
	Atlas_Chuti_User_State::instance();
	Atlas_Chuti_Ratings::instance();
	Atlas_Chuti_Comments::instance();
	Atlas_Chuti_Photos::instance();
	Atlas_Chuti_REST_API::instance();
	Atlas_Chuti_Privacy::instance();
	Atlas_Chuti_Magazine::instance();
	Atlas_Chuti_Magazine_Meta_Box::instance();
	Atlas_Chuti_Discussion::instance();
	Atlas_Chuti_Ad_Campaign::instance();
	Atlas_Chuti_Ad_Campaign_Meta_Box::instance();
	Atlas_Chuti_Advertising::instance();
	Atlas_Chuti_Advertising_Settings::instance();
	Atlas_Chuti_Collections::instance();
	Atlas_Chuti_Shopping_List::instance();
	Atlas_Chuti_Meal_Plan::instance();
	Atlas_Chuti_Recommendations::instance();
	Atlas_Chuti_Ingredient_Finder::instance();
	Atlas_Chuti_Video_Meta_Box::instance();
}
add_action( 'plugins_loaded', 'atlas_chuti_core_init' );

/**
 * Flush rewrite rules once on activation/deactivation so CPT/taxonomy permalinks work immediately.
 */
function atlas_chuti_core_activate() {
	Atlas_Chuti_Post_Types::instance()->register();
	Atlas_Chuti_Taxonomies::instance()->register();
	Atlas_Chuti_DB::instance()->install();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'atlas_chuti_core_activate' );

function atlas_chuti_core_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'atlas_chuti_core_deactivate' );
