<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Language-readiness layer (item 17 of the brief). Only Czech (cs-CZ) is active today,
 * but every locale-bearing entity (recipe, country, glossary, ingredient) always
 * carries three extra bits of identity so a future English `.com` instance — a
 * *separate* WordPress install with its own post IDs — can still recognize "this is
 * the same thing":
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
 *   - countries resolve by ISO 3166-1 code (`atlas_iso_code`) — already the primary key
 *     item 10 of the original brief put on every country.
 *   - recipes/glossary resolve by `atlas_translation_group` (slug is only a fallback,
 *     for convenience, while just one locale exists).
 *   - ingredients resolve by their language-neutral `atlas_ingredient_key` (e.g.
 *     "tomato"), never by the Czech slug ("rajce"/"rajcata"/"rajcat" must all be
 *     *aliases* of one key).
 *
 * No WPML/Polylang dependency, no `/en/` routes, no hreflang — those switch on only
 * once a second locale is actually running (see class-seo.php).
 */
class Atlas_Chuti_I18N {

	const DEFAULT_LOCALE = 'cs-CZ';

	const LOCALIZED_POST_TYPES = array( 'atlas_recipe', 'atlas_country', 'atlas_glossary', 'atlas_ingredient' );

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
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'atlas-chuti', false, dirname( plugin_basename( ATLAS_CHUTI_DIR . 'atlas-chuti-core.php' ) ) . '/languages' );
	}

	/**
	 * Backfills atlas_locale/atlas_translation_group/atlas_translation_status on every
	 * save (admin edit or importer) so the contract holds even for content nobody
	 * explicitly set these fields on. Never overwrites a value that's already there.
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
	 * Resolves a country by its stable identity: ISO 3166-1 alpha-2/3 code. This is
	 * the identifier recipe/glossary imports should reference, not a Czech slug.
	 */
	public static function find_country_by_iso( $iso_code ) {
		if ( ! $iso_code ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'atlas_country',
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_query'     => array( array( 'key' => 'atlas_iso_code', 'value' => strtoupper( $iso_code ), 'compare' => '=' ) ),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Resolves a recipe/glossary entry by its language-independent translation_group
	 * (optionally scoped to one locale — irrelevant today, but this is the lookup a
	 * future multi-locale-in-one-instance setup, or a cross-instance sync job, would use).
	 */
	public static function find_by_translation_group( $post_type, $group, $locale = null ) {
		if ( ! $group ) {
			return null;
		}
		$meta_query = array( array( 'key' => 'atlas_translation_group', 'value' => $group, 'compare' => '=' ) );
		if ( $locale ) {
			$meta_query[] = array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' );
		}
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_query'     => $meta_query,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Resolves a normalized ingredient by its language-neutral key ("tomato"), never by
	 * the localized slug ("rajce").
	 */
	public static function find_ingredient_by_key( $key ) {
		if ( ! $key ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'atlas_ingredient',
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft' ),
				'meta_query'     => array( array( 'key' => 'atlas_ingredient_key', 'value' => $key, 'compare' => '=' ) ),
			)
		);
		return $posts ? $posts[0] : null;
	}
}
