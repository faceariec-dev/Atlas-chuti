/**
 * Recipe detail action bar (KROK 2, item 7): Tisk (window.print) and Sdílet (Web
 * Share API with a copy-link fallback) — no social SDK, no tracking, no network
 * request of its own. Purely additive: buttons this file doesn't find simply do
 * nothing, so it's safe on any page that doesn't render them.
 */
( function () {
	'use strict';

	var L10N = window.AtlasChutiShareL10n || {};

	function initPrint() {
		document.querySelectorAll( '[data-print-trigger]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				window.print();
			} );
		} );
	}

	function fallbackPrompt( url ) {
		window.prompt( L10N.shareCopyPrompt || 'Zkopírujte odkaz:', url );
	}

	function flashLabel( label, text, revertTo ) {
		if ( ! label ) {
			return;
		}
		label.textContent = text;
		window.setTimeout( function () {
			label.textContent = revertTo;
		}, 2500 );
	}

	function initShare() {
		var buttons = document.querySelectorAll( '[data-share-trigger]' );
		if ( ! buttons.length ) {
			return;
		}
		var canNativeShare = typeof navigator.share === 'function';

		buttons.forEach( function ( btn ) {
			var url = btn.getAttribute( 'data-share-url' ) || window.location.href;
			var title = btn.getAttribute( 'data-share-title' ) || document.title;
			var label = btn.querySelector( '.label' );
			var defaultText = label ? label.textContent : '';

			btn.addEventListener( 'click', function () {
				if ( canNativeShare ) {
					navigator.share( { title: title, url: url } ).catch( function () {
						/* user dismissed the native share sheet — not an error */
					} );
					return;
				}
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( url ).then( function () {
						flashLabel( label, L10N.shareLinkCopied || defaultText, defaultText );
					} ).catch( function () {
						fallbackPrompt( url );
					} );
					return;
				}
				fallbackPrompt( url );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initPrint();
		initShare();
	} );
} )();
