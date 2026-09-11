/**
 * Media Library picker for the atlas_continent term image field (item 20 of this
 * phase's brief). Vanilla JS + wp.media, no jQuery UI dependency beyond what
 * wp_enqueue_media() already loads.
 */
( function () {
	'use strict';

	var L10N = window.AtlasContinentImageL10n || {};

	function initField( field ) {
		var input = field.querySelector( '.atlas-continent-image-input' );
		var preview = field.querySelector( '.atlas-continent-image-preview' );
		var chooseBtn = field.querySelector( '.atlas-continent-image-choose' );
		var removeBtn = field.querySelector( '.atlas-continent-image-remove' );
		var frame = null;

		chooseBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( frame ) {
				frame.open();
				return;
			}
			frame = window.wp.media( {
				title: L10N.title,
				button: { text: L10N.button },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				input.value = attachment.id;
				var imgUrl = ( attachment.sizes && attachment.sizes.medium ) ? attachment.sizes.medium.url : attachment.url;
				preview.innerHTML = '<img src="' + imgUrl + '" style="max-width:150px;height:auto;display:block;">';
				chooseBtn.textContent = L10N.change;
				removeBtn.style.display = '';
			} );
			frame.open();
		} );

		removeBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			input.value = '';
			preview.innerHTML = '';
			chooseBtn.textContent = L10N.choose;
			removeBtn.style.display = 'none';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.atlas-continent-image-field' ).forEach( initField );
	} );
} )();
