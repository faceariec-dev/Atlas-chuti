<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses each ingredient's `quantity` into a scalable numeric amount where that makes
 * mathematical sense, and flags the rest (e.g. "podle chuti", "špetka") as non-scalable
 * so the frontend portion switcher never mangles them. Lives in the plugin (not the
 * theme) because it operates purely on the structured data model.
 */
class Atlas_Chuti_Servings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Returns the recipe's ingredients enriched with `scalable` (bool) and `base_amount`
	 * (float|null), ready to be JSON-encoded into a data attribute for servings.js.
	 */
	public static function get_scalable_ingredients( $post_id ) {
		$ingredients = get_post_meta( $post_id, 'atlas_ingredients', true );
		if ( ! is_array( $ingredients ) ) {
			return array();
		}

		$out = array();
		foreach ( $ingredients as $ingredient ) {
			$quantity = isset( $ingredient['quantity'] ) ? trim( (string) $ingredient['quantity'] ) : '';
			$parsed   = self::parse_quantity( $quantity );

			$out[] = array(
				'ingredient_id' => isset( $ingredient['ingredient_id'] ) ? $ingredient['ingredient_id'] : '',
				'name'          => isset( $ingredient['name'] ) ? $ingredient['name'] : '',
				'quantity'      => $quantity,
				'unit'          => isset( $ingredient['unit'] ) ? $ingredient['unit'] : '',
				'note'          => isset( $ingredient['note'] ) ? $ingredient['note'] : '',
				'group'         => isset( $ingredient['group'] ) ? $ingredient['group'] : '',
				'scalable'      => null !== $parsed,
				'base_amount'   => $parsed,
			);
		}
		return $out;
	}

	/**
	 * Recognizes plain integers/decimals ("400", "1.5") and simple fractions
	 * ("1/2", "1 1/2"). Anything else ("podle chuti", "špetka", "dle chuti") returns
	 * null and is left untouched when portions change.
	 */
	public static function parse_quantity( $quantity ) {
		$quantity = trim( $quantity );
		if ( '' === $quantity ) {
			return null;
		}

		if ( preg_match( '/^\d+([.,]\d+)?$/', $quantity ) ) {
			return (float) str_replace( ',', '.', $quantity );
		}

		if ( preg_match( '/^(\d+)\s+(\d+)\/(\d+)$/', $quantity, $m ) ) {
			return (float) $m[1] + ( (float) $m[2] / (float) $m[3] );
		}

		if ( preg_match( '/^(\d+)\/(\d+)$/', $quantity, $m ) ) {
			return (float) $m[1] / (float) $m[2];
		}

		return null;
	}

	/**
	 * Formats a scaled float back into a short human string (e.g. 0.5 → "1/2").
	 */
	public static function format_amount( $amount ) {
		if ( floor( $amount ) === $amount ) {
			return (string) (int) $amount;
		}

		$fractions = array(
			0.25 => '1/4',
			0.5  => '1/2',
			0.75 => '3/4',
			0.33 => '1/3',
			0.67 => '2/3',
		);
		$whole     = floor( $amount );
		$remainder = round( $amount - $whole, 2 );

		foreach ( $fractions as $value => $label ) {
			if ( abs( $remainder - $value ) < 0.02 ) {
				return $whole > 0 ? $whole . ' ' . $label : $label;
			}
		}

		return (string) round( $amount, 2 );
	}
}
