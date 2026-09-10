<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recipe archive filters (item 16 of the brief): země, světadíl, typ jídla, čas,
 * obtížnost, + dieta. Reads plain GET query vars so results stay linkable and the
 * archive works with JS disabled — filters.js only makes the same links apply
 * instantly instead of requiring a form submit.
 */
function atlas_chuti_query_vars( $vars ) {
	$vars[] = 'zeme';
	$vars[] = 'svetadil';
	$vars[] = 'typ';
	$vars[] = 'obtiznost';
	$vars[] = 'cas';
	$vars[] = 'dieta';
	return $vars;
}
add_filter( 'query_vars', 'atlas_chuti_query_vars' );

function atlas_chuti_filter_recipe_archive( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'atlas_recipe' ) ) {
		return;
	}

	$tax_query = array();

	if ( $zeme = get_query_var( 'zeme' ) ) {
		$tax_query[] = array( 'taxonomy' => 'atlas_country_tax', 'field' => 'slug', 'terms' => explode( ',', $zeme ) );
	}
	if ( $svetadil = get_query_var( 'svetadil' ) ) {
		$tax_query[] = array( 'taxonomy' => 'atlas_continent', 'field' => 'slug', 'terms' => explode( ',', $svetadil ) );
	}
	if ( $typ = get_query_var( 'typ' ) ) {
		$tax_query[] = array( 'taxonomy' => 'atlas_meal_type', 'field' => 'slug', 'terms' => explode( ',', $typ ) );
	}
	if ( $obtiznost = get_query_var( 'obtiznost' ) ) {
		$tax_query[] = array( 'taxonomy' => 'atlas_difficulty', 'field' => 'slug', 'terms' => explode( ',', $obtiznost ) );
	}
	if ( $dieta = get_query_var( 'dieta' ) ) {
		$tax_query[] = array( 'taxonomy' => 'atlas_diet', 'field' => 'slug', 'terms' => explode( ',', $dieta ) );
	}

	if ( $tax_query ) {
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}
		$query->set( 'tax_query', $tax_query );
	}

	if ( $cas = get_query_var( 'cas' ) ) {
		$max = array( 'do-30' => 30, 'do-60' => 60, 'do-90' => 90 );
		if ( isset( $max[ $cas ] ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => 'atlas_total_minutes',
						'value'   => $max[ $cas ],
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				)
			);
		}
	}

	$query->set( 'posts_per_page', 12 );
}
add_action( 'pre_get_posts', 'atlas_chuti_filter_recipe_archive' );

/**
 * Data the filter panel needs to render its checkboxes: every country/continent/
 * meal-type/difficulty/diet term that has at least one published recipe.
 */
function atlas_chuti_get_recipe_filter_options() {
	return array(
		'zeme'      => get_terms( array( 'taxonomy' => 'atlas_country_tax', 'hide_empty' => true ) ),
		'svetadil'  => get_terms( array( 'taxonomy' => 'atlas_continent', 'hide_empty' => true ) ),
		'typ'       => get_terms( array( 'taxonomy' => 'atlas_meal_type', 'hide_empty' => true ) ),
		'obtiznost' => get_terms( array( 'taxonomy' => 'atlas_difficulty', 'hide_empty' => true ) ),
		'dieta'     => get_terms( array( 'taxonomy' => 'atlas_diet', 'hide_empty' => true ) ),
	);
}

/**
 * Renders a "Vše / term / term…" radio group for one filter taxonomy.
 */
function atlas_chuti_radio_group( $name, $terms, $active_slug ) {
	if ( ! $terms || is_wp_error( $terms ) ) {
		return;
	}
	printf( '<label><input type="radio" name="%1$s" value="" %2$s> %3$s</label>', esc_attr( $name ), checked( '', $active_slug, false ), esc_html__( 'Vše', 'atlas-chuti' ) );
	foreach ( $terms as $term ) {
		printf(
			'<label><input type="radio" name="%1$s" value="%2$s" %3$s> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $term->slug ),
			checked( $term->slug, $active_slug, false ),
			esc_html( $term->name )
		);
	}
}

function atlas_chuti_active_filters() {
	$active = array();
	foreach ( array( 'zeme', 'svetadil', 'typ', 'obtiznost', 'dieta', 'cas' ) as $key ) {
		$value = get_query_var( $key );
		if ( $value ) {
			$active[ $key ] = $value;
		}
	}
	return $active;
}
