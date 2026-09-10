<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Technical SEO baseline: meta description, canonical, OpenGraph, WebSite/Recipe/
 * BreadcrumbList structured data — built only from real structured fields, never
 * invented. Stays out of the way if a full SEO plugin (Yoast, RankMath, SEOPress...)
 * is active, per item 25 of the brief ("kompatibilní s běžným SEO pluginem").
 */
class Atlas_Chuti_SEO {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( $this->seo_plugin_active() ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'output_meta' ), 1 );
		add_action( 'wp_head', array( $this, 'output_schema' ), 5 );
	}

	private function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_FILE' ) || class_exists( 'SEOPress' );
	}

	private function get_meta_description() {
		if ( is_singular( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' ) ) ) {
			$post_id = get_the_ID();
			$custom  = get_post_meta( $post_id, 'atlas_meta_description', true );
			if ( $custom ) {
				return $custom;
			}
			$excerpt = get_post_meta( $post_id, 'atlas_excerpt', true );
			if ( $excerpt ) {
				return wp_strip_all_tags( $excerpt );
			}
			$intro = get_post_meta( $post_id, 'atlas_intro', true );
			if ( $intro ) {
				return wp_trim_words( wp_strip_all_tags( $intro ), 30 );
			}
			$short = get_post_meta( $post_id, 'atlas_short_definition', true );
			if ( $short ) {
				return wp_strip_all_tags( $short );
			}
		}
		return get_bloginfo( 'description' );
	}

	private function get_seo_title() {
		if ( is_singular( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' ) ) ) {
			$custom = get_post_meta( get_the_ID(), 'atlas_seo_title', true );
			if ( $custom ) {
				return $custom;
			}
		}
		return wp_get_document_title();
	}

	public function output_meta() {
		$description = wp_strip_all_tags( $this->get_meta_description() );
		$title       = $this->get_seo_title();
		$canonical   = is_singular() ? get_permalink() : ( is_home() || is_front_page() ? home_url( '/' ) : '' );

		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
		if ( $canonical ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
		}

		printf( '<meta property="og:type" content="%s">' . "\n", is_singular( 'atlas_recipe' ) ? 'article' : 'website' );
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		if ( $canonical ) {
			printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $canonical ) );
		}
		if ( is_singular() && has_post_thumbnail() ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( get_the_post_thumbnail_url( get_the_ID(), 'large' ) ) );
		}
	}

	public function output_schema() {
		$graphs = array();

		$graphs[] = array(
			'@type' => 'WebSite',
			'@id'   => home_url( '/#website' ),
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => home_url( '/?s={search_term_string}' ),
				'query-input' => 'required name=search_term_string',
			),
		);

		if ( is_singular( 'atlas_recipe' ) ) {
			$graphs[] = $this->recipe_schema( get_the_ID() );
		}

		$breadcrumb = $this->breadcrumb_schema();
		if ( $breadcrumb ) {
			$graphs[] = $breadcrumb;
		}

		$graphs = array_filter( $graphs );
		if ( empty( $graphs ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array_values( $graphs ),
			)
		) . '</script>' . "\n";
	}

	private function recipe_schema( $post_id ) {
		$ingredients = get_post_meta( $post_id, 'atlas_ingredients', true );
		$steps       = get_post_meta( $post_id, 'atlas_steps', true );
		$prep        = (int) get_post_meta( $post_id, 'atlas_prep_minutes', true );
		$cook        = (int) get_post_meta( $post_id, 'atlas_cook_minutes', true );
		$total       = (int) get_post_meta( $post_id, 'atlas_total_minutes', true );

		$schema = array(
			'@type'       => 'Recipe',
			'name'        => get_the_title( $post_id ),
			'description' => wp_strip_all_tags( get_post_meta( $post_id, 'atlas_excerpt', true ) ),
			'url'         => get_permalink( $post_id ),
		);

		if ( has_post_thumbnail( $post_id ) ) {
			$schema['image'] = array( get_the_post_thumbnail_url( $post_id, 'large' ) );
		}
		if ( $prep ) {
			$schema['prepTime'] = 'PT' . $prep . 'M';
		}
		if ( $cook ) {
			$schema['cookTime'] = 'PT' . $cook . 'M';
		}
		if ( $total ) {
			$schema['totalTime'] = 'PT' . $total . 'M';
		}

		$servings = get_post_meta( $post_id, 'atlas_servings_default', true );
		if ( $servings ) {
			$schema['recipeYield'] = (string) $servings;
		}

		if ( is_array( $ingredients ) && $ingredients ) {
			$schema['recipeIngredient'] = array_map(
				function ( $i ) {
					return trim( ( isset( $i['quantity'] ) ? $i['quantity'] . ' ' . $i['unit'] . ' ' : '' ) . ( isset( $i['name'] ) ? $i['name'] : '' ) );
				},
				$ingredients
			);
		}

		if ( is_array( $steps ) && $steps ) {
			$schema['recipeInstructions'] = array_map(
				function ( $s ) {
					return array(
						'@type' => 'HowToStep',
						'text'  => isset( $s['text'] ) ? wp_strip_all_tags( $s['text'] ) : '',
					);
				},
				$steps
			);
		}

		$countries = wp_get_post_terms( $post_id, 'atlas_country_tax' );
		if ( ! empty( $countries ) && ! is_wp_error( $countries ) ) {
			$schema['recipeCuisine'] = wp_list_pluck( $countries, 'name' );
		}

		return $schema;
	}

	private function breadcrumb_schema() {
		if ( is_front_page() ) {
			return null;
		}
		$items = atlas_chuti_get_breadcrumbs();
		if ( count( $items ) < 2 ) {
			return null;
		}
		$list = array();
		foreach ( $items as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['label'],
				'item'     => $item['url'],
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		);
	}
}
