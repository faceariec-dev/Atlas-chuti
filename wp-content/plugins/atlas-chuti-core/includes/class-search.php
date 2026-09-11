<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site search across recipes, countries and glossary entries (item 17 of the brief).
 * Extends the default title/content match to a handful of meta fields per post type
 * so e.g. searching "miso" finds the glossary definition, not just titles containing it.
 */
class Atlas_Chuti_Search {

	const POST_TYPES = array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'pre_get_posts', array( $this, 'restrict_search_post_types' ) );
	}

	public function restrict_search_post_types( $query ) {
		if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
			$query->set( 'post_type', self::POST_TYPES );
		}
		return $query;
	}

	private function searchable_meta_keys() {
		return array(
			'atlas_excerpt',
			'atlas_about',
			'atlas_intro',
			'atlas_taste_intro',
			'atlas_short_definition',
			'atlas_detailed',
			'atlas_original_title',
			'atlas_name_en',
		);
	}

	/**
	 * Runs a grouped search used by the dedicated /vysledky-hledani/ template. Returns
	 * ['atlas_recipe' => WP_Post[], 'atlas_country' => WP_Post[], 'atlas_glossary' => WP_Post[]].
	 */
	public function search( $term, $per_type = 24 ) {
		$term    = trim( (string) $term );
		$results = array(
			'atlas_recipe'   => array(),
			'atlas_country'  => array(),
			'atlas_glossary' => array(),
		);

		if ( '' === $term ) {
			return $results;
		}

		global $wpdb;
		$like       = '%' . $wpdb->esc_like( $term ) . '%';
		$meta_keys  = $this->searchable_meta_keys();
		$meta_ph    = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		foreach ( self::POST_TYPES as $post_type ) {
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ($meta_ph)
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND ( p.post_title LIKE %s OR p.post_content LIKE %s OR ( pm.meta_value LIKE %s ) )
				LIMIT %d",
				array_merge( $meta_keys, array( $post_type, $like, $like, $like, $per_type ) )
			);
			$ids = $wpdb->get_col( $sql );

			if ( 'atlas_recipe' === $post_type ) {
				$ids = array_unique( array_merge( $ids, $this->recipe_ids_by_ingredient( $term, $per_type ) ) );
			}

			if ( $ids ) {
				$results[ $post_type ] = array_map( 'get_post', array_slice( $ids, 0, $per_type ) );
			}
		}

		return $results;
	}

	/**
	 * Widens a keyword search to every recipe containing a matching normalized
	 * ingredient — e.g. "kuře" finds recipes tagged with the "chicken" ingredient,
	 * even though "kuře" never appears in the recipe's own title/content (item 17 of
	 * this phase's brief). See class-ingredient-sync.php for how the tagging works;
	 * this is a single indexed tax_query, so it stays fast at any recipe count.
	 */
	private function recipe_ids_by_ingredient( $term, $limit ) {
		$term_ids = Atlas_Chuti_Ingredient_Sync::find_matching_term_ids( $term );
		if ( ! $term_ids ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => 'atlas_recipe',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => 'atlas_ingredient_tax', 'field' => 'term_id', 'terms' => $term_ids ) ),
			)
		);
	}
}
