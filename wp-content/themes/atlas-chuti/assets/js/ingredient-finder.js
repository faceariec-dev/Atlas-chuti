/**
 * KROK 8, item 16-18/47: progressive enhancement for "Co mám doma?"'s
 * ingredient picker. The underlying control stays a real, native
 * `<select multiple>` (already fully keyboard/screen-reader accessible with
 * no JS at all — see template-co-mam-doma.php) — this only adds a filter
 * input above it and a chip list below it, both kept in sync with the SAME
 * select, so nothing here can desync from what actually gets submitted.
 * Chosen deliberately over a from-scratch ARIA combobox re-implementation
 * (item 18/47's own "safely simpler accessible select/search" allowance).
 */
( function () {
	'use strict';

	var wrapper = document.querySelector( '[data-ingredient-combobox]' );
	var select  = document.querySelector( '[data-ingredient-select]' );
	if ( ! wrapper || ! select ) {
		return;
	}

	var L = window.AtlasIngredientFinderL10n || {};

	var filterInput = document.createElement( 'input' );
	filterInput.type = 'text';
	filterInput.setAttribute( 'aria-label', L.filterLabel || 'Filtrovat seznam ingrediencí' );
	filterInput.placeholder = L.filterPlaceholder || 'Hledat ingredienci…';
	wrapper.insertBefore( filterInput, select );

	var chips = document.createElement( 'div' );
	chips.className = 'ingredient-chips';
	wrapper.appendChild( chips );

	function renderChips() {
		chips.innerHTML = '';
		Array.prototype.forEach.call( select.options, function ( option ) {
			if ( ! option.selected ) {
				return;
			}
			var chip = document.createElement( 'span' );
			chip.className = 'ingredient-chip';
			var text = document.createElement( 'span' );
			text.textContent = option.text;
			chip.appendChild( text );
			var removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'ingredient-chip-remove';
			removeBtn.setAttribute( 'aria-label', ( L.removeFormat || 'Odebrat %s' ).replace( '%s', option.text ) );
			removeBtn.textContent = '×';
			removeBtn.addEventListener( 'click', function () {
				option.selected = false;
				renderChips();
				option.dispatchEvent && select.dispatchEvent( new Event( 'change' ) );
			} );
			chip.appendChild( removeBtn );
			chips.appendChild( chip );
		} );
	}

	select.addEventListener( 'change', renderChips );

	filterInput.addEventListener( 'input', function () {
		var q = filterInput.value.trim().toLowerCase();
		Array.prototype.forEach.call( select.options, function ( option ) {
			option.hidden = q.length > 0 && -1 === option.text.toLowerCase().indexOf( q );
		} );
	} );

	renderChips();
} )();
