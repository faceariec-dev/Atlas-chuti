<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a featured-image <img> tag; when the post has none yet, an
 * illustrative fallback image for $fallback_context (see
 * inc/fallback-images.php — 'recipe' or 'country', 'country' optionally with
 * $fallback_continent for the continent-specific chain); when neither exists,
 * the original generic placeholder block (item 24 of the brief — never a
 * broken image). A real featured image always wins over any fallback.
 */
function atlas_chuti_media( $post_id, $size = 'atlas-card', $placeholder_label = '', $fallback_context = '', $fallback_continent = '' ) {
	if ( has_post_thumbnail( $post_id ) ) {
		return get_the_post_thumbnail( $post_id, $size, array( 'loading' => 'lazy' ) );
	}
	if ( $fallback_context ) {
		$fallback = atlas_chuti_fallback_image_html( $fallback_context, $fallback_continent, get_the_title( $post_id ) );
		if ( $fallback ) {
			return $fallback;
		}
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
 * Pulls the main WordPress menu into the header, falling back to the portal-shaped
 * navigation (KROK 1, item 5 of the brief) if no menu has been assigned yet: Recepty
 * (with a quick-access mega menu), Země, then Magazín/Tipy a triky/Diskuze — future
 * sections not built yet in this step, shown as inert "brzy" placeholders rather
 * than broken links — and a "Více" group for Kuchařský slovníček + Kulinářský pas.
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

	echo '<div class="nav-item has-mega">';
	printf(
		'<button type="button" class="nav-link nav-mega-toggle" aria-expanded="false" aria-controls="nav-mega-recepty">%s</button>',
		esc_html__( 'Recepty', 'atlas-chuti' )
	);
	echo '<div class="nav-mega" id="nav-mega-recepty" hidden>';
	foreach ( atlas_chuti_recipe_mega_menu_items() as $item ) {
		printf( '<a href="%s">%s</a>', esc_url( $item['url'] ), esc_html( $item['label'] ) );
	}
	echo '</div></div>';

	printf( '<a href="%s">%s</a>', esc_url( atlas_chuti_system_url( 'countries' ) ), esc_html__( 'Země', 'atlas-chuti' ) );

	foreach ( array( __( 'Magazín', 'atlas-chuti' ), __( 'Tipy a triky', 'atlas-chuti' ), __( 'Diskuze', 'atlas-chuti' ) ) as $soon_label ) {
		printf(
			'<span class="nav-link is-soon" aria-disabled="true">%s <em class="soon-tag">%s</em></span>',
			esc_html( $soon_label ),
			esc_html__( 'brzy', 'atlas-chuti' )
		);
	}

	echo '<div class="nav-item has-mega">';
	printf(
		'<button type="button" class="nav-link nav-mega-toggle" aria-expanded="false" aria-controls="nav-mega-more">%s</button>',
		esc_html__( 'Více', 'atlas-chuti' )
	);
	echo '<div class="nav-mega" id="nav-mega-more" hidden>';
	printf( '<a href="%s">%s</a>', esc_url( get_post_type_archive_link( 'atlas_glossary' ) ), esc_html__( 'Kuchařský slovníček', 'atlas-chuti' ) );
	printf( '<a href="%s">%s</a>', esc_url( atlas_chuti_system_url( 'passport' ) ), esc_html__( 'Kulinářský pas', 'atlas-chuti' ) );
	echo '</div></div>';
}

/**
 * Quick-access entries for the "Recepty" mega menu (KROK 1, header section of the
 * brief): only targets that resolve to a real, existing URL are included — "Nejlépe
 * hodnocené" is deliberately left out because there is no rating data yet, and
 * "Česká kuchyně" only appears once Czechia (ISO "CZ") actually exists as a Země
 * post, so this can never produce a broken link.
 */
function atlas_chuti_recipe_mega_menu_items() {
	$archive = get_post_type_archive_link( 'atlas_recipe' );
	$items   = array(
		array( 'label' => __( 'Nové recepty', 'atlas-chuti' ), 'url' => $archive ),
	);

	$czech = function_exists( 'atlas_chuti_home_czech_country' ) ? atlas_chuti_home_czech_country() : null;
	if ( $czech ) {
		$items[] = array( 'label' => __( 'Česká kuchyně', 'atlas-chuti' ), 'url' => get_permalink( $czech ) );
	}

	$items[] = array( 'label' => __( 'Kuchyně světa', 'atlas-chuti' ), 'url' => atlas_chuti_system_url( 'countries' ) );
	$items[] = array( 'label' => __( 'Polévky', 'atlas-chuti' ), 'url' => add_query_arg( 'typ', 'soup', $archive ) );
	$items[] = array( 'label' => __( 'Hlavní jídla', 'atlas-chuti' ), 'url' => add_query_arg( 'typ', 'main-course', $archive ) );
	$items[] = array( 'label' => __( 'Moučníky', 'atlas-chuti' ), 'url' => add_query_arg( 'typ', 'dessert', $archive ) );
	$items[] = array( 'label' => __( 'Do 30 minut', 'atlas-chuti' ), 'url' => add_query_arg( 'cas', 'do-30', $archive ) );

	return $items;
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
