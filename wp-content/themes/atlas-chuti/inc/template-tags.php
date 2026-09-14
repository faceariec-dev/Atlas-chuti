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
 *
 * $eager (KROK 2, item 5): the recipe hero photo is very often the LCP element,
 * so its caller can opt out of the default lazy-loading — everything else keeps
 * behaving exactly as before (default false, so every existing call site is
 * unaffected).
 */
function atlas_chuti_media( $post_id, $size = 'atlas-card', $placeholder_label = '', $fallback_context = '', $fallback_continent = '', $eager = false ) {
	if ( has_post_thumbnail( $post_id ) ) {
		$attrs = $eager
			? array( 'loading' => 'eager', 'fetchpriority' => 'high' )
			: array( 'loading' => 'lazy' );
		return get_the_post_thumbnail( $post_id, $size, $attrs );
	}
	if ( $fallback_context ) {
		$extra_attrs = $eager ? array( 'loading' => 'eager', 'fetchpriority' => 'high' ) : array();
		$fallback    = atlas_chuti_fallback_image_html( $fallback_context, $fallback_continent, get_the_title( $post_id ), $extra_attrs );
		if ( $fallback ) {
			return $fallback;
		}
	}
	$label = $placeholder_label ?: get_the_title( $post_id );
	return '<div class="placeholder-media"><span>' . esc_html( $label ) . '</span></div>';
}

/**
 * Renders whatever is hooked to $hook_name inside a `<div class="$wrapper_class">`,
 * or nothing at all when the hook has no callbacks (KROK 2, items 12/13.3/13.5 — ad
 * slot, tag system, community features: prepared extension points, never an empty
 * visible box, since nothing is hooked yet in this step).
 */
function atlas_chuti_hook_slot( $hook_name, $wrapper_class, ...$args ) {
	ob_start();
	do_action( $hook_name, ...$args );
	$html = trim( ob_get_clean() );
	if ( '' === $html ) {
		return;
	}
	echo '<div class="' . esc_attr( $wrapper_class ) . '">' . $html . '</div>';
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
 * CZ | EN language switcher (KROK 4, item 8). Renders NOTHING when Polylang isn't
 * active — the same "safely inert until the real thing exists" convention already
 * used for the nav's "brzy" placeholders (KROK 1): with only one real locale
 * available, a switcher control would have nowhere real to send anyone, so it's
 * better absent than fake. Once Polylang is active with both languages configured,
 * this becomes a real, keyboard/screen-reader-usable set of links automatically —
 * no template change needed.
 */
function atlas_chuti_language_switcher() {
	if ( ! Atlas_Chuti_Polylang_Bridge::is_active() ) {
		return;
	}
	$items = Atlas_Chuti_Polylang_Bridge::switcher_data();
	if ( count( $items ) < 2 ) {
		return;
	}
	echo '<nav class="lang-switcher" aria-label="' . esc_attr__( 'Přepnout jazyk', 'atlas-chuti' ) . '">';
	foreach ( $items as $item ) {
		$label = esc_html( $item['label'] );
		if ( $item['is_current'] ) {
			printf( '<span class="lang-switcher-current" aria-current="true">%s</span>', $label );
			continue;
		}
		if ( ! $item['url'] ) {
			// Polylang active, but this locale has no home URL to offer at all
			// (misconfigured install) — never render a dead link (item 8 of the brief).
			continue;
		}
		// A real, functioning link either way — item 8 explicitly allows the
		// "no translation yet -> safe fallback" case to still be a normal, keyboard-
		// and screen-reader-usable link (never a disabled/fake control); the title
		// attribute is the only difference, so it's honest about where it leads.
		printf(
			'<a class="lang-switcher-link" href="%1$s"%2$s>%3$s</a>',
			esc_url( $item['url'] ),
			$item['exact'] ? '' : ' title="' . esc_attr__( 'Překlad této stránky zatím není k dispozici — odkaz vede na úvodní stránku v tomto jazyce.', 'atlas-chuti' ) . '"',
			$label
		);
	}
	echo '</nav>';
}

/**
 * Renders the ONE primary navigation (KROK 1, item 5 of the brief, opravný prompt):
 * Recepty (quick-access mega menu), Země, then Magazín/Tipy a triky/Diskuze — future
 * sections not built yet, shown as inert "brzy" placeholders rather than broken
 * links — and a "Více" group for Kuchařský slovníček + Kulinářský pas.
 *
 * This fixed portal structure ALWAYS renders, on every request, on both desktop and
 * mobile (the same markup this function outputs is what both breakpoints style via
 * CSS — there is no separate mobile code path). It is never conditional on, and
 * never replaced by, a custom WordPress menu: an earlier version of this function
 * swapped the ENTIRE nav for a bare wp_nav_menu() call the moment an admin assigned
 * a menu to the "primary" location, silently dropping the Recepty mega menu, the
 * "brzy" placeholders and the Více group. That is fixed here — a custom "primary"
 * menu, if assigned, is instead appended as additional top-level links AFTER this
 * fixed structure, so it can extend the nav but can never deactivate or bypass it.
 * One render path, not two parallel nav systems.
 */
function atlas_chuti_primary_nav() {
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

	// Additive only (see docblock): a custom WP "primary" menu extends the fixed
	// portal nav above, it never replaces it. Same walker/markup as the rest of
	// the nav, so it's styled identically and works in the same mega-menu-free,
	// flat <a> shape on both desktop and the off-canvas mobile panel.
	if ( has_nav_menu( 'primary' ) ) {
		wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'container'      => false,
				'items_wrap'     => '%3$s',
				'walker'         => new Atlas_Chuti_Nav_Walker(),
			)
		);
	}
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
