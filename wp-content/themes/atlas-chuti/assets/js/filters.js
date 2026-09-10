/**
 * Progressive enhancement only: the filter panel is a plain GET <form> that works
 * without JS (item 16 of the brief — never a JS-only archive). This just makes
 * checking a box apply instantly instead of requiring the "Použít filtry" click.
 */
( function () {
	'use strict';
	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '[data-filter-form]' );
		if ( ! form ) {
			return;
		}
		form.querySelectorAll( 'input[type="checkbox"], input[type="radio"], select' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				form.submit();
			} );
		} );
	} );
} )();
