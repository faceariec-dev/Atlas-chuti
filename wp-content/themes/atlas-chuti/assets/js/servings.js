/**
 * Recipe portion switcher (item 9 of the brief). Ingredient data + which amounts are
 * numerically scalable comes from the plugin (Atlas_Chuti_Servings::get_scalable_ingredients),
 * embedded as JSON on the ingredient list — this file only does display math, no reload.
 */
( function () {
	'use strict';

	function formatAmount( amount ) {
		if ( Math.abs( amount - Math.round( amount ) ) < 0.01 ) {
			return String( Math.round( amount ) );
		}
		var whole = Math.floor( amount );
		var remainder = Math.round( ( amount - whole ) * 100 ) / 100;
		var fractions = { 0.25: '1/4', 0.5: '1/2', 0.75: '3/4', 0.33: '1/3', 0.67: '2/3' };
		for ( var key in fractions ) {
			if ( Math.abs( remainder - parseFloat( key ) ) < 0.02 ) {
				return whole > 0 ? whole + ' ' + fractions[ key ] : fractions[ key ];
			}
		}
		return String( Math.round( amount * 100 ) / 100 );
	}

	function apply( list, ratio ) {
		var data;
		try {
			data = JSON.parse( list.getAttribute( 'data-ingredients' ) );
		} catch ( e ) {
			return;
		}
		list.querySelectorAll( '[data-index]' ).forEach( function ( row ) {
			var i = parseInt( row.getAttribute( 'data-index' ), 10 );
			var item = data[ i ];
			if ( ! item || ! item.scalable ) {
				return;
			}
			var amountEl = row.querySelector( '.amount' );
			if ( ! amountEl ) {
				return;
			}
			var scaled = item.base_amount * ratio;
			amountEl.textContent = formatAmount( scaled ) + ( item.unit ? ' ' + item.unit : '' );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var switcher = document.querySelector( '[data-servings-switcher]' );
		var list = document.querySelector( '[data-ingredient-list]' );
		if ( ! switcher || ! list ) {
			return;
		}
		var defaultServings = parseFloat( switcher.getAttribute( 'data-default' ) ) || 1;

		switcher.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-servings]' );
			if ( ! btn ) {
				return;
			}
			switcher.querySelectorAll( '.pill' ).forEach( function ( p ) {
				p.classList.remove( 'is-active' );
			} );
			btn.classList.add( 'is-active' );
			var target = parseFloat( btn.getAttribute( 'data-servings' ) );
			apply( list, target / defaultServings );
		} );
	} );
} )();
