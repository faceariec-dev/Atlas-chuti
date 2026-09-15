<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5: the first custom-table schema this project has ever needed (audit
 * confirmed zero prior dbDelta()/CREATE TABLE anywhere — see the Step 5 report,
 * section C). Three tables, each earning its place because the data doesn't fit
 * WordPress's built-in shapes without giving up filtering/uniqueness/performance
 * (item 9 of the brief):
 *
 *   - atlas_user_state   — favorite/cooked (recipe, by recipe_key) and tasted
 *                          (country, by ISO) in ONE normalized table, distinguished
 *                          by (subject_type, state) — not three separate tables,
 *                          not a serialized blob in user_meta (which the brief
 *                          explicitly forbids: "NEUKLÁDEJ velké serializované
 *                          seznamy... do jednoho user_meta").
 *   - atlas_ratings       — one row per (recipe_key, user) OR (recipe_key, anon
 *                          token) — never both on the same row (enforced in
 *                          Atlas_Chuti_Ratings, not here; MySQL treats every NULL
 *                          as distinct in a UNIQUE key, so `recipe_user`/`recipe_anon`
 *                          only actually constrain the column that is non-NULL).
 *   - atlas_recipe_photos — user-submitted photos, pending→approved/rejected.
 *
 * Every subject/identity column stores the STABLE, locale-independent identity
 * (recipe_key, ISO code) — never a post ID, never a slug — per item 11 of the
 * brief: a CZ and an EN post of "the same" recipe must resolve to the exact same
 * rows here.
 */
class Atlas_Chuti_DB {

	// KROK 8: bumped to 1.1.0 — 4 new tables (collections/collection_items/
	// shopping_list_items/meal_plan_items), same idempotent dbDelta() install()
	// below just gains 4 more CREATE TABLE statements; existing tables are
	// untouched (item 38: "normalizovaná data", never one serialized blob).
	const DB_VERSION     = '1.1.0';
	const OPTION_VERSION = 'atlas_chuti_db_version';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Cheap on every request (a single get_option() compare, dbDelta() only
		// actually runs the rare time the stored version differs) — safe to leave
		// wired to `plugins_loaded` rather than only activation, so a Step 5 deploy
		// over an already-activated plugin (no re-activation event fires) still gets
		// its tables created (item 10: "musí být bezpečná při opakované aktivaci").
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );
	}

	public static function table_user_state() {
		global $wpdb;
		return $wpdb->prefix . 'atlas_user_state';
	}

	public static function table_ratings() {
		global $wpdb;
		return $wpdb->prefix . 'atlas_ratings';
	}

	public static function table_photos() {
		global $wpdb;
		return $wpdb->prefix . 'atlas_recipe_photos';
	}

	public static function table_collections() {
		global $wpdb;
		return $wpdb->prefix . 'atlas_collections';
	}

	public static function table_collection_items() {
		global $wpdb;
		return $wpdb->prefix . 'atlas_collection_items';
	}

	public static function table_shopping_list_items() {
		global $wpdb;
		return $wpdb->prefix . 'atlas_shopping_list_items';
	}

	public static function table_meal_plan_items() {
		global $wpdb;
		return $wpdb->prefix . 'atlas_meal_plan_items';
	}

	public function maybe_upgrade() {
		if ( get_option( self::OPTION_VERSION ) === self::DB_VERSION ) {
			return;
		}
		$this->install();
	}

	/**
	 * Idempotent by design — dbDelta() only ever adds/modifies what differs from the
	 * table's current shape, never drops/recreates (item 10 of the brief: "Žádné
	 * destruktivní drop/recreate").
	 */
	public function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$user_state       = self::table_user_state();
		$ratings          = self::table_ratings();
		$photos           = self::table_photos();
		$collections      = self::table_collections();
		$collection_items = self::table_collection_items();
		$shopping_list    = self::table_shopping_list_items();
		$meal_plan        = self::table_meal_plan_items();

		// dbDelta() is whitespace/syntax-picky (two spaces after PRIMARY KEY, each
		// index on its own line, no inline comments) — see the Codex documentation
		// for the exact expected format; deviating silently breaks detection of "did
		// this column already exist" on upgrade.
		$sql = "CREATE TABLE {$user_state} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  subject_type VARCHAR(20) NOT NULL,
  subject_key VARCHAR(191) NOT NULL,
  state VARCHAR(20) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_subject_state (user_id,subject_type,subject_key,state),
  KEY user_state (user_id,state),
  KEY subject (subject_type,subject_key)
) {$charset_collate};
CREATE TABLE {$ratings} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_key VARCHAR(191) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  anon_token_hash CHAR(64) NULL,
  rating TINYINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY recipe_user (recipe_key,user_id),
  UNIQUE KEY recipe_anon (recipe_key,anon_token_hash),
  KEY recipe_key (recipe_key)
) {$charset_collate};
CREATE TABLE {$photos} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  recipe_key VARCHAR(191) NOT NULL,
  attachment_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  caption TEXT NULL,
  created_at DATETIME NOT NULL,
  moderated_at DATETIME NULL,
  moderated_by BIGINT UNSIGNED NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY recipe_key_status (recipe_key,status)
) {$charset_collate};
CREATE TABLE {$collections} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(191) NOT NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) {$charset_collate};
CREATE TABLE {$collection_items} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id BIGINT UNSIGNED NOT NULL,
  recipe_key VARCHAR(191) NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY collection_recipe (collection_id,recipe_key),
  KEY collection_id (collection_id)
) {$charset_collate};
CREATE TABLE {$shopping_list} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  ingredient_key VARCHAR(191) NOT NULL,
  display_name VARCHAR(191) NOT NULL,
  quantity_value DECIMAL(10,3) NULL,
  quantity_text VARCHAR(50) NOT NULL DEFAULT '',
  unit_key VARCHAR(20) NULL,
  source_recipe_key VARCHAR(191) NULL,
  checked TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY user_ingredient_unit (user_id,ingredient_key,unit_key)
) {$charset_collate};
CREATE TABLE {$meal_plan} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  plan_date DATE NOT NULL,
  meal_slot VARCHAR(20) NOT NULL,
  recipe_key VARCHAR(191) NOT NULL,
  servings_override SMALLINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_date_slot_recipe (user_id,plan_date,meal_slot,recipe_key),
  KEY user_date (user_id,plan_date)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::OPTION_VERSION, self::DB_VERSION );
	}
}
