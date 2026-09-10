/**
 * Generic repeater field used by every "list of objects" meta box field
 * (ingredients, steps, variants, must-try dishes, traditional dishes…).
 * Keeps a hidden input in sync with a JSON array so the PHP side only ever
 * has to read one meta value per field.
 */
( function () {
	'use strict';

	function parseShape( el ) {
		return el.getAttribute( 'data-shape' ).split( ',' );
	}

	function parseLabels( el ) {
		var raw = el.getAttribute( 'data-labels' );
		return raw ? raw.split( '|' ) : [];
	}

	function readValue( hidden ) {
		try {
			var v = JSON.parse( hidden.value || '[]' );
			return Array.isArray( v ) ? v : [];
		} catch ( e ) {
			return [];
		}
	}

	function buildRow( repeater, shape, labels, rowData ) {
		var row = document.createElement( 'div' );
		row.className = 'atlas-repeater-row';

		shape.forEach( function ( field, i ) {
			var wrap = document.createElement( 'label' );
			wrap.className = 'atlas-repeater-field';
			var labelText = labels[ i ] || field;
			var input = document.createElement( field === 'text' || field === 'note' ? 'textarea' : 'input' );
			input.className = 'atlas-repeater-input';
			input.setAttribute( 'data-field', field );
			if ( input.tagName === 'INPUT' ) {
				input.type = 'text';
			} else {
				input.rows = 2;
			}
			input.value = rowData && rowData[ field ] !== undefined ? rowData[ field ] : '';
			input.placeholder = labelText;
			wrap.appendChild( document.createTextNode( labelText ) );
			wrap.appendChild( input );
			row.appendChild( wrap );
		} );

		var removeBtn = document.createElement( 'button' );
		removeBtn.type = 'button';
		removeBtn.className = 'button-link atlas-repeater-remove';
		removeBtn.textContent = '✕ odebrat';
		removeBtn.addEventListener( 'click', function () {
			row.remove();
			sync( repeater );
		} );
		row.appendChild( removeBtn );

		return row;
	}

	function sync( repeater ) {
		var hidden = repeater.querySelector( '.atlas-repeater-value' );
		var rowsWrap = repeater.querySelector( '.atlas-repeater-rows' );
		var shape = parseShape( repeater );
		var data = [];

		rowsWrap.querySelectorAll( '.atlas-repeater-row' ).forEach( function ( row ) {
			var obj = {};
			var hasValue = false;
			shape.forEach( function ( field ) {
				var input = row.querySelector( '[data-field="' + field + '"]' );
				var val = input ? input.value : '';
				obj[ field ] = val;
				if ( val.trim() !== '' ) {
					hasValue = true;
				}
			} );
			if ( hasValue ) {
				data.push( obj );
			}
		} );

		hidden.value = JSON.stringify( data );
	}

	function init( repeater ) {
		var hidden = repeater.querySelector( '.atlas-repeater-value' );
		var rowsWrap = repeater.querySelector( '.atlas-repeater-rows' );
		var addBtn = repeater.querySelector( '.atlas-repeater-add' );
		var shape = parseShape( repeater );
		var labels = parseLabels( repeater );
		var initial = readValue( hidden );

		initial.forEach( function ( rowData ) {
			rowsWrap.appendChild( buildRow( repeater, shape, labels, rowData ) );
		} );

		repeater.addEventListener( 'input', function () {
			sync( repeater );
		} );

		addBtn.addEventListener( 'click', function () {
			rowsWrap.appendChild( buildRow( repeater, shape, labels, null ) );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.atlas-repeater' ).forEach( init );
	} );
} )();
