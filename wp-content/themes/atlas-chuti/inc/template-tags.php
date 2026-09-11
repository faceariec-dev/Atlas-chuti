<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a featured-image <img> tag, or an elegant placeholder block when the
 * post has none yet (item 24 of the brief — never a broken image).
 */
function atlas_chuti_media( $post_id, $size = 'atlas-card', $placeholder_label = '' ) {
	if ( has_post_thumbnail( $post_id ) ) {
		return get_the_post_thumbnail( $post_id, $size, array( 'loading' => 'lazy' ) );
	}
	$label = $placeholder_label ?: get_the_title( $post_id );
	return '<div class="placeholder-media"><span>' . esc_html( $label ) . '</span></div>';
}

/**
 * Czech has three plural forms (1 / 2–4 / 5+, or 0), unlike WP's built-in _n().
 * "1 recept", "2 recepty", "5 receptů".
 */
function atlas_chuti_czech_plural( $count, $one, $few, $many ) {
	$count = (int) $count;
	if ( 1 === $count ) {
		$word = $one;
	} elseif ( $count >= 2 && $count <= 4 ) {
		$word = $few;
	} else {
		$word = $many;
	}
	return $count . ' ' . $word;
}

/**
 * Adds/replaces query args on the glossary archive URL, dropping a key entirely
 * when its value is `false` (used to build the "Vše" reset links).
 */
function atlas_chuti_glossary_filter_url( $params ) {
	$base = get_post_type_archive_link( 'atlas_glossary' );
	foreach ( $params as $key => $value ) {
		if ( false === $value ) {
			$base = remove_query_arg( $key, $base );
			unset( $params[ $key ] );
		}
	}
	return esc_url( add_query_arg( $params, $base ) );
}

function atlas_chuti_flag( $country_post_id ) {
	return get_post_meta( $country_post_id, 'atlas_flag_emoji', true );
}

/**
 * Difficulty/meal-type/time badge line used on recipe cards.
 */
function atlas_chuti_recipe_meta_line( $post_id ) {
	$time  = atlas_chuti_format_time( get_post_meta( $post_id, 'atlas_total_minutes', true ) );
	$diff  = get_the_terms( $post_id, 'atlas_difficulty' );
	$parts = array_filter( array( $time, $diff && ! is_wp_error( $diff ) ? $diff[0]->name : '' ) );
	return implode( ' · ', $parts );
}

/**
 * Renders the breadcrumb trail from Atlas_Chuti core's atlas_chuti_get_breadcrumbs().
 */
function atlas_chuti_breadcrumbs() {
	$items = atlas_chuti_get_breadcrumbs();
	if ( count( $items ) < 2 ) {
		return;
	}
	echo '<nav class="breadcrumbs" aria-label="' . esc_attr__( 'Drobečková navigace', 'atlas-chuti' ) . '">';
	foreach ( $items as $i => $item ) {
		if ( $i > 0 ) {
			echo '<span aria-hidden="true">/</span>';
		}
		if ( $i === count( $items ) - 1 ) {
			echo '<span aria-current="page">' . esc_html( $item['label'] ) . '</span>';
		} else {
			echo '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
		}
	}
	echo '</nav>';
}

/**
 * Pulls the main WordPress menu into the header, falling back to the four core
 * sections (item 19 of the brief) if no menu has been assigned yet.
 */
function atlas_chuti_primary_nav() {
	if ( has_nav_menu( 'primary' ) ) {
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'container'      => false,
				'items_wrap'     => '%3$s',
				'walker'         => new Atlas_Chuti_Nav_Walker(),
			)
		);
		return;
	}
	$fallback = array(
		__( 'Země', 'atlas-chuti' )                => atlas_chuti_system_url( 'countries' ),
		__( 'Recepty', 'atlas-chuti' )              => get_post_type_archive_link( 'atlas_recipe' ),
		__( 'Kuchařský slovníček', 'atlas-chuti' )  => get_post_type_archive_link( 'atlas_glossary' ),
		__( 'Kulinářský pas', 'atlas-chuti' )       => atlas_chuti_system_url( 'passport' ),
	);
	foreach ( $fallback as $label => $url ) {
		printf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}
}

class Atlas_Chuti_Nav_Walker extends Walker_Nav_Menu {
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes = $item->current ? ' class="is-current"' : '';
		$output .= '<a href="' . esc_url( $item->url ) . '"' . $classes . '>' . esc_html( $item->title ) . '</a>';
	}
}

function atlas_chuti_footer_nav( $location, $fallback_items ) {
	if ( has_nav_menu( $location ) ) {
		wp_nav_menu( array( 'theme_location' => $location, 'container' => false, 'items_wrap' => '%3$s', 'walker' => new Atlas_Chuti_Nav_Walker() ) );
		return;
	}
	foreach ( $fallback_items as $label => $url ) {
		if ( null === $url ) {
			/* translators: %s: feature name not built yet, e.g. "Kulinářské cesty" */
			printf( '<span class="soon">%s</span>', esc_html( sprintf( __( '%s (brzy)', 'atlas-chuti' ), $label ) ) );
		} else {
			printf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
		}
	}
}
