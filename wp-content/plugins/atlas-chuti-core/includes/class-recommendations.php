<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 8, item 13-15: "Co dnes vařit?" — a real recommendation tool built only
 * from published recipes and the SAME controlled vocabularies the recipe
 * archive already filters by (`inc/archive-filters.php`'s zeme/svetadil/typ/
 * obtiznost/dieta/cas query vars — reused here by name on purpose, not
 * reinvented, so "Co dnes vařit?" and the recipe archive can never drift into
 * two different ideas of what "easy" or "do-30" means). No AI chatbot, no ML
 * model, no user profiling (item 13/14's own explicit scope limits).
 *
 * Hard filters first (tax_query/meta_query — exactly like the archive), then a
 * deterministic day-seeded pick among the real matches — never
 * `ORDER BY RAND()` over the whole table (item 15), and never a fake score.
 */
class Atlas_Chuti_Recommendations {

	const TIME_BUCKETS = array( 'do-30' => 30, 'do-60' => 60, 'do-90' => 90 );

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * $filters may contain any of: zeme, svetadil, typ, obtiznost, dieta, cas —
	 * exactly the archive's own query var names/values, so a caller can pass
	 * $_GET straight through (after sanitizing keys, see the theme template).
	 * Unknown keys are ignored; a $_GET value not matching a real term/bucket
	 * simply matches nothing (never invented).
	 *
	 * Returns array('primary'=>WP_Post|null, 'alternatives'=>WP_Post[], 'reason'=>string[]).
	 */
	public function find( array $filters, $locale = null, $seed_salt = '' ) {
		$locale = $locale ?: Atlas_Chuti_I18N::current_locale();
		$args   = array(
			'post_type'      => 'atlas_recipe',
			'post_status'    => 'publish',
			'posts_per_page' => 30, // a bounded candidate pool, never the whole table (item 42).
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		$tax_query = array();
		$map       = array(
			'zeme'      => 'atlas_country_tax',
			'svetadil'  => 'atlas_continent',
			'typ'       => 'atlas_meal_type',
			'obtiznost' => 'atlas_difficulty',
			'dieta'     => 'atlas_diet',
		);
		foreach ( $map as $key => $taxonomy ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $filters[ $key ] ) ) );
				if ( $slugs ) {
					$tax_query[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $slugs );
				}
			}
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore -- WordPress.DB.SlowDBQuery, mirrors inc/archive-filters.php's own established pattern.
		}

		if ( ! empty( $filters['cas'] ) && isset( self::TIME_BUCKETS[ $filters['cas'] ] ) ) {
			$args['meta_query'] = array( // phpcs:ignore -- WordPress.DB.SlowDBQuery, same field the archive already thresholds on.
				array(
					'key'     => 'atlas_total_minutes',
					'value'   => self::TIME_BUCKETS[ $filters['cas'] ],
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			);
		}

		// Locale scoping happens automatically here via Atlas_Chuti_I18N::
		// scope_query_to_locale()'s pre_get_posts hook (item: applies to ANY
		// WP_Query matching a LOCALIZED_POST_TYPES post_type, not just the main
		// query — already proven by every prior step's own recipe queries).
		$matches = get_posts( $args );

		if ( ! $matches ) {
			return array( 'primary' => null, 'alternatives' => array(), 'reason' => array() );
		}

		$primary_index = $this->day_seeded_index( count( $matches ), $filters, $seed_salt );
		$primary       = $matches[ $primary_index ];
		unset( $matches[ $primary_index ] );
		$alternatives = array_slice( array_values( $matches ), 0, 3 );

		return array(
			'primary'       => $primary,
			'alternatives'  => $alternatives,
			'reason'        => $this->build_reason( $filters, $primary->ID, $locale ),
		);
	}

	/**
	 * item 15: "efektivnější přístup než drahé ORDER BY RAND()" — a stable,
	 * reproducible index derived from today's date + the active filters, so
	 * the SAME day + SAME filters always show the SAME pick (cache-friendly,
	 * explainable, and trivially testable) without touching the database for
	 * randomness at all.
	 */
	private function day_seeded_index( $count, array $filters, $seed_salt = '' ) {
		if ( $count <= 1 ) {
			return 0;
		}
		$seed = current_time( 'Ymd' ) . '|' . wp_json_encode( $filters ) . '|' . $seed_salt;
		$hash = crc32( $seed );
		return $hash % $count;
	}

	/**
	 * item 14: "Proč tento recept" from real, already-applied criteria only —
	 * never a fabricated reason for a filter the visitor didn't actually pick.
	 */
	private function build_reason( array $filters, $recipe_id, $locale ) {
		$reasons = array();
		if ( ! empty( $filters['cas'] ) && isset( self::TIME_BUCKETS[ $filters['cas'] ] ) ) {
			/* translators: %d: minutes */
			$reasons[] = sprintf( __( 'Hotové do %d minut', 'atlas-chuti' ), self::TIME_BUCKETS[ $filters['cas'] ] );
		}
		foreach ( array( 'dieta' => 'atlas_diet', 'obtiznost' => 'atlas_difficulty', 'typ' => 'atlas_meal_type' ) as $key => $taxonomy ) {
			if ( empty( $filters[ $key ] ) ) {
				continue;
			}
			foreach ( explode( ',', (string) $filters[ $key ] ) as $slug ) {
				$slug = sanitize_title( $slug );
				if ( $slug && in_array( $slug, Atlas_Chuti_Taxonomy_Labels::keys( $taxonomy ), true ) ) {
					$reasons[] = Atlas_Chuti_Taxonomy_Labels::label( $taxonomy, $slug, $locale );
				}
			}
		}
		return $reasons;
	}
}
