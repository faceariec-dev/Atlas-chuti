( function () {
	'use strict';
	var toggle = document.querySelector( '.nav-toggle' );
	var nav = document.querySelector( '.main-nav' );
	if ( toggle && nav ) {
		toggle.addEventListener( 'click', function () {
			var isOpen = nav.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			if ( ! isOpen ) {
				closeAllMega();
			}
		} );
	}

	// Mega menu toggles (Recepty / Více): click or keyboard Enter/Space always
	// works via the native <button>; hover-to-open is a CSS-only convenience for
	// pointer devices, added separately, never required (item 12 of the brief).
	var megaToggles = Array.prototype.slice.call( document.querySelectorAll( '.nav-mega-toggle' ) );

	function closeAllMega( except ) {
		megaToggles.forEach( function ( btn ) {
			if ( btn === except ) {
				return;
			}
			var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
			btn.setAttribute( 'aria-expanded', 'false' );
			if ( panel ) {
				panel.hidden = true;
			}
		} );
	}

	megaToggles.forEach( function ( btn ) {
		var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
		if ( ! panel ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			var isOpen = btn.getAttribute( 'aria-expanded' ) === 'true';
			closeAllMega( isOpen ? null : btn );
			btn.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
			panel.hidden = isOpen;
		} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' ) {
			closeAllMega();
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '.nav-item.has-mega' ) ) {
			closeAllMega();
		}
	} );
} )();
