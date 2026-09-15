<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small template-facing helpers shared by every theme that might sit on top of this
 * data model. Keeping them here (not in the theme) is what makes the data model
 * theme-independent, per item 3 of the brief.
 */

/**
 * "90" -> "1 h 30 min", "45" -> "45 min", 0/empty -> ''.
 */
function atlas_chuti_format_time( $minutes ) {
	$minutes = (int) $minutes;
	if ( $minutes <= 0 ) {
		return '';
	}
	if ( $minutes < 60 ) {
		return $minutes . ' min';
	}
	$h = intdiv( $minutes, 60 );
	$m = $minutes % 60;
	return $m > 0 ? $h . ' h ' . $m . ' min' : $h . ' h';
}

/**
 * Resolves the atlas_country CPT post behind a recipe's primary country term.
 */
function atlas_chuti_get_recipe_primary_country( $recipe_id ) {
	$term_id = (int) get_post_meta( $recipe_id, '_atlas_recipe_primary_country_term_id', true );
	if ( ! $term_id ) {
		return null;
	}
	$post_id = Atlas_Chuti_Country_Sync::get_country_post_for_term( $term_id );
	return $post_id ? get_post( $post_id ) : null;
}

/**
 * A single unified fallback image URL for recipes/countries without a photo yet
 * (item 24 of the brief). Themes may override this with their own placeholder via
 * the `atlas_chuti_placeholder_image` filter.
 */
function atlas_chuti_placeholder_image( $context = 'recipe' ) {
	return apply_filters( 'atlas_chuti_placeholder_image', '', $context );
}

/**
 * Central map of "system" page paths — country archive, culinary passport, legal
 * pages… (item 20 of this phase's brief). This map ALWAYS holds the Czech path —
 * that part is unchanged. What KROK 4 adds: when the current request is NOT the
 * default locale, this now tries to resolve the REAL translated WordPress Page
 * (created via "Atlas chutí → Nastavení stránek", class-page-setup.php) via
 * Polylang, and only falls back to the Czech URL when no such translation exists
 * yet or Polylang isn't active — never a broken link, never a guessed English slug.
 * `atlas_chuti_system_paths` stays filterable exactly as before for the Czech side.
 */
function atlas_chuti_system_url( $key ) {
	$paths = apply_filters(
		'atlas_chuti_system_paths',
		array(
			'countries'          => '/zeme/',
			'passport'           => '/kulinarsky-pas/',
			'account'            => '/muj-atlas/',
			'about'              => '/o-projektu/',
			'editorial_process'  => '/jak-vznika-obsah/',
			'editorial_policy'   => '/redakcni-zasady/',
			'contact'            => '/kontakt/',
			'advertising'        => '/inzerce/',
			'privacy'            => '/ochrana-osobnich-udaju/',
			'cookies'            => '/cookies/',
			'terms'              => '/podminky-pouzivani/',
		)
	);
	$path    = $paths[ $key ] ?? '/';
	$cs_url  = home_url( $path );

	if ( ! class_exists( 'Atlas_Chuti_Polylang_Bridge' )
		|| ! Atlas_Chuti_Polylang_Bridge::is_active()
		|| Atlas_Chuti_I18N::DEFAULT_LOCALE === Atlas_Chuti_I18N::current_locale() ) {
		return $cs_url;
	}

	$cs_page = get_page_by_path( trim( $path, '/' ) );
	if ( $cs_page ) {
		$translated_id = Atlas_Chuti_Polylang_Bridge::get_post_translation_id( $cs_page->ID, Atlas_Chuti_I18N::current_locale() );
		if ( $translated_id && 'publish' === get_post_status( $translated_id ) ) {
			return get_permalink( $translated_id );
		}
	}
	return $cs_url;
}

/**
 * Breadcrumb trail as [['label' => ..., 'url' => ...], ...]. Used both by the
 * theme's breadcrumb template part and by the BreadcrumbList schema output.
 */
function atlas_chuti_get_breadcrumbs() {
	$crumbs = array( array( 'label' => __( 'Domů', 'atlas-chuti' ), 'url' => home_url( '/' ) ) );

	if ( is_singular( 'atlas_recipe' ) ) {
		$crumbs[] = array( 'label' => __( 'Recepty', 'atlas-chuti' ), 'url' => get_post_type_archive_link( 'atlas_recipe' ) );
		$country  = atlas_chuti_get_recipe_primary_country( get_the_ID() );
		if ( $country ) {
			$crumbs[] = array( 'label' => get_the_title( $country ), 'url' => get_permalink( $country ) );
		}
		$crumbs[] = array( 'label' => get_the_title(), 'url' => get_permalink() );
	} elseif ( is_singular( 'atlas_country' ) ) {
		$crumbs[] = array( 'label' => __( 'Země', 'atlas-chuti' ), 'url' => atlas_chuti_system_url( 'countries' ) );
		$crumbs[] = array( 'label' => get_the_title(), 'url' => get_permalink() );
	} elseif ( is_singular( 'atlas_glossary' ) ) {
		$crumbs[] = array( 'label' => __( 'Kuchařský slovníček', 'atlas-chuti' ), 'url' => get_post_type_archive_link( 'atlas_glossary' ) );
		$crumbs[] = array( 'label' => get_the_title(), 'url' => get_permalink() );
	} elseif ( is_post_type_archive( 'atlas_recipe' ) ) {
		$crumbs[] = array( 'label' => __( 'Recepty', 'atlas-chuti' ), 'url' => get_post_type_archive_link( 'atlas_recipe' ) );
	} elseif ( is_post_type_archive( 'atlas_glossary' ) ) {
		$crumbs[] = array( 'label' => __( 'Kuchařský slovníček', 'atlas-chuti' ), 'url' => get_post_type_archive_link( 'atlas_glossary' ) );
	} elseif ( is_tax( 'atlas_continent' ) ) {
		$crumbs[] = array( 'label' => __( 'Země', 'atlas-chuti' ), 'url' => atlas_chuti_system_url( 'countries' ) );
		$crumbs[] = array( 'label' => single_term_title( '', false ), 'url' => get_term_link( get_queried_object() ) );
	} elseif ( is_page() ) {
		$crumbs[] = array( 'label' => get_the_title(), 'url' => get_permalink() );
	} elseif ( is_search() ) {
		$crumbs[] = array( 'label' => __( 'Výsledky hledání', 'atlas-chuti' ), 'url' => get_search_link() );
	}

	return $crumbs;
}

/**
 * Fetches recipes belonging to a Země CPT post via its synced taxonomy term.
 */
function atlas_chuti_get_recipes_for_country( $country_post_id, $limit = 4 ) {
	$term_id = Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $country_post_id );
	if ( ! $term_id ) {
		return array();
	}
	return get_posts(
		array(
			'post_type'      => 'atlas_recipe',
			'posts_per_page' => $limit,
			'tax_query'      => array( array( 'taxonomy' => 'atlas_country_tax', 'field' => 'term_id', 'terms' => $term_id ) ),
		)
	);
}

/**
 * Cheap count (no post objects hydrated) for card badges like "9 receptů".
 */
function atlas_chuti_count_recipes_for_country( $country_post_id ) {
	$term_id = Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $country_post_id );
	if ( ! $term_id ) {
		return 0;
	}
	$ids = get_posts(
		array(
			'post_type'      => 'atlas_recipe',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array( array( 'taxonomy' => 'atlas_country_tax', 'field' => 'term_id', 'terms' => $term_id ) ),
		)
	);
	return count( $ids );
}
