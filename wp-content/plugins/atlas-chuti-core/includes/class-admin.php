<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small admin-list-table conveniences (country flag/continent, recipe country/time)
 * so editors can scan content without opening every post.
 */
class Atlas_Chuti_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'manage_atlas_recipe_posts_columns', array( $this, 'recipe_columns' ) );
		add_action( 'manage_atlas_recipe_posts_custom_column', array( $this, 'recipe_column_content' ), 10, 2 );

		add_filter( 'manage_atlas_country_posts_columns', array( $this, 'country_columns' ) );
		add_action( 'manage_atlas_country_posts_custom_column', array( $this, 'country_column_content' ), 10, 2 );
	}

	public function recipe_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['atlas_country']    = __( 'Země', 'atlas-chuti' );
				$new['atlas_time']       = __( 'Čas', 'atlas-chuti' );
				$new['atlas_difficulty'] = __( 'Obtížnost', 'atlas-chuti' );
			}
		}
		return $new;
	}

	public function recipe_column_content( $column, $post_id ) {
		if ( 'atlas_country' === $column ) {
			$terms = get_the_terms( $post_id, 'atlas_country_tax' );
			echo $terms && ! is_wp_error( $terms ) ? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '—';
		} elseif ( 'atlas_time' === $column ) {
			$total = get_post_meta( $post_id, 'atlas_total_minutes', true );
			echo $total ? esc_html( $total . ' min' ) : '—';
		} elseif ( 'atlas_difficulty' === $column ) {
			$terms = get_the_terms( $post_id, 'atlas_difficulty' );
			echo $terms && ! is_wp_error( $terms ) ? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '—';
		}
	}

	public function country_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['atlas_flag']      = __( 'Vlajka', 'atlas-chuti' );
				$new['atlas_continent'] = __( 'Světadíl', 'atlas-chuti' );
			}
		}
		return $new;
	}

	public function country_column_content( $column, $post_id ) {
		if ( 'atlas_flag' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'atlas_flag_emoji', true ) );
		} elseif ( 'atlas_continent' === $column ) {
			$terms = get_the_terms( $post_id, 'atlas_continent' );
			echo $terms && ! is_wp_error( $terms ) ? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '—';
		}
	}
}
