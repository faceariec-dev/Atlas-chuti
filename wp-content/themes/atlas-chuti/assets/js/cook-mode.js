/**
 * KROK 8, item 1-3/47: Cook Mode + the ingredient/step checklist.
 *
 * The checklist works standalone, in the normal recipe view, with no Cook
 * Mode involved at all — the checkboxes already live in the page's own
 * markup (single-atlas_recipe.php's ingredient-row/step-row). Cook Mode is a
 * progressive-enhancement overlay built entirely from that SAME markup (no
 * duplicated hidden HTML, no second indexable view) that focuses on one step
 * at a time; it reuses the identical checkbox elements by cloning them, so
 * there is exactly one piece of state and toggling either copy updates both.
 *
 * State is pure client-side (localStorage), keyed by recipe_key + a content
 * hash of ingredients/steps (data-recipe-version) — never sent to the
 * server, never mutates recipe data, never recipe schema. It is deliberately
 * NOT "server personal data" for privacy purposes (see class-privacy.php's
 * docblock and the Step 8 report section N).
 */
( function () {
	'use strict';

	var section = document.querySelector( '#ingredience[data-cook-mode]' );
	if ( ! section ) {
		return;
	}

	var recipeKey     = section.getAttribute( 'data-recipe-key' );
	var recipeVersion = section.getAttribute( 'data-recipe-version' );
	var recipeTitle   = section.getAttribute( 'data-recipe-title' ) || '';
	var storageKey    = 'atlasCook:' + recipeKey + ':' + recipeVersion;

	var ingredientRows = Array.prototype.slice.call( section.querySelectorAll( '.ingredient-row[data-index]' ) );
	var stepRows       = Array.prototype.slice.call( section.querySelectorAll( '.step-row[data-index]' ) );

	// -----------------------------------------------------------------
	// State: { ingredients: { "0": true, ... }, steps: { "0": true, ... } }
	// -----------------------------------------------------------------

	function loadState() {
		try {
			var raw = window.localStorage.getItem( storageKey );
			var parsed = raw ? JSON.parse( raw ) : null;
			if ( parsed && parsed.ingredients && parsed.steps ) {
				return parsed;
			}
		} catch ( e ) { /* no-op — falls through to a fresh empty state. */ }
		return { ingredients: {}, steps: {} };
	}

	function saveState() {
		try {
			window.localStorage.setItem( storageKey, JSON.stringify( state ) );
		} catch ( e ) { /* no-op — private mode/quota; state just stays in-memory for this load. */ }
	}

	var state = loadState();

	function setChecked( kind, index, value ) {
		state[ kind ][ index ] = !! value;
		saveState();
		var selector = '[data-' + ( 'ingredients' === kind ? 'ingredient' : 'step' ) + '-check][data-index="' + index + '"]';
		document.querySelectorAll( selector ).forEach( function ( input ) {
			input.checked = !! value;
		} );
		updateProgress();
	}

	function isChecked( kind, index ) {
		return !! state[ kind ][ index ];
	}

	// -----------------------------------------------------------------
	// Standard-view checkboxes (always wired, with or without Cook Mode)
	// -----------------------------------------------------------------

	function wireStandardCheckboxes() {
		section.querySelectorAll( '[data-ingredient-check]' ).forEach( function ( input ) {
			var index = input.getAttribute( 'data-index' );
			input.checked = isChecked( 'ingredients', index );
			input.addEventListener( 'change', function () {
				setChecked( 'ingredients', index, input.checked );
			} );
		} );
		section.querySelectorAll( '[data-step-check]' ).forEach( function ( input ) {
			var index = input.getAttribute( 'data-index' );
			input.checked = isChecked( 'steps', index );
			input.addEventListener( 'change', function () {
				setChecked( 'steps', index, input.checked );
			} );
		} );
	}

	var progressStatus = null;

	function updateProgress() {
		if ( ! progressStatus || ! stepRows.length ) {
			return;
		}
		var done = stepRows.filter( function ( row ) {
			return isChecked( 'steps', row.getAttribute( 'data-index' ) );
		} ).length;
		progressStatus.textContent = ( window.AtlasCookL10n && window.AtlasCookL10n.stepsDoneFormat )
			? window.AtlasCookL10n.stepsDoneFormat.replace( '%1$d', done ).replace( '%2$d', stepRows.length )
			: done + ' / ' + stepRows.length;
	}

	// -----------------------------------------------------------------
	// Cook Mode overlay
	// -----------------------------------------------------------------

	var root = document.getElementById( 'atlas-cook-mode-root' );
	var L    = window.AtlasCookL10n || {};
	var overlayBuilt = false;
	var currentIndex  = 0;
	var lastFocused   = null;

	function buildOverlay() {
		if ( overlayBuilt || ! stepRows.length ) {
			return;
		}
		overlayBuilt = true;

		root.innerHTML =
			'<div class="cook-mode-overlay" role="dialog" aria-modal="true" aria-label="' + escapeAttr( L.cookModeLabel || recipeTitle ) + '">' +
				'<div class="cook-mode-header">' +
					'<h2 class="cook-mode-title">' + escapeHtml( recipeTitle ) + '</h2>' +
					'<div class="cook-mode-header-actions">' +
						'<button type="button" class="cook-mode-wake-toggle" data-cook-wake-toggle aria-pressed="false">' + escapeHtml( L.keepScreenOn || 'Nezhasínat obrazovku' ) + '</button>' +
						'<button type="button" class="cook-mode-close" data-cook-close aria-label="' + escapeAttr( L.close || 'Zavřít' ) + '">&times;</button>' +
					'</div>' +
				'</div>' +
				'<p class="cook-mode-progress" data-cook-progress aria-live="polite"></p>' +
				'<div class="cook-mode-step">' +
					'<span class="cook-mode-step-num" data-cook-step-num aria-hidden="true"></span>' +
					'<p class="cook-mode-step-text" data-cook-step-text></p>' +
					'<div class="cook-mode-step-controls">' +
						'<label class="step-check cook-mode-step-check">' +
							'<input type="checkbox" data-cook-step-done-check>' +
							escapeHtml( L.done || 'Hotovo' ) +
						'</label>' +
						'<button type="button" class="btn btn-small" data-cook-set-timer hidden></button>' +
					'</div>' +
				'</div>' +
				'<div class="cook-mode-ingredients" data-cook-ingredients hidden></div>' +
				'<div class="cook-mode-nav">' +
					'<button type="button" class="cook-mode-nav-btn" data-cook-prev>' + escapeHtml( L.previous || 'Předchozí' ) + '</button>' +
					'<button type="button" class="cook-mode-nav-btn" data-cook-toggle-ingredients aria-expanded="false">' + escapeHtml( L.ingredients || 'Ingredience' ) + '</button>' +
					'<button type="button" class="cook-mode-nav-btn is-primary" data-cook-next>' + escapeHtml( L.next || 'Další' ) + '</button>' +
				'</div>' +
			'</div>';

		progressStatus = root.querySelector( '[data-cook-progress]' );

		root.querySelector( '[data-cook-close]' ).addEventListener( 'click', closeCookMode );
		root.querySelector( '[data-cook-prev]' ).addEventListener( 'click', function () { goToStep( currentIndex - 1 ); } );
		root.querySelector( '[data-cook-next]' ).addEventListener( 'click', function () { goToStep( currentIndex + 1 ); } );
		root.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				closeCookMode();
			} else if ( 'ArrowRight' === e.key ) {
				goToStep( currentIndex + 1 );
			} else if ( 'ArrowLeft' === e.key ) {
				goToStep( currentIndex - 1 );
			}
		} );

		var ingredientsToggle = root.querySelector( '[data-cook-toggle-ingredients]' );
		var ingredientsPanel  = root.querySelector( '[data-cook-ingredients]' );
		var ingredientList    = section.querySelector( '.ingredient-list' );
		if ( ingredientList ) {
			var clone = ingredientList.cloneNode( true );
			clone.removeAttribute( 'data-ingredients' );
			ingredientsPanel.appendChild( clone );
			clone.querySelectorAll( '[data-ingredient-check]' ).forEach( function ( input ) {
				var index = input.getAttribute( 'data-index' );
				input.checked = isChecked( 'ingredients', index );
				input.addEventListener( 'change', function () {
					setChecked( 'ingredients', index, input.checked );
				} );
			} );
		}
		ingredientsToggle.addEventListener( 'click', function () {
			var expanded = 'true' === ingredientsToggle.getAttribute( 'aria-expanded' );
			ingredientsToggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
			ingredientsPanel.hidden = expanded;
		} );

		var wakeToggle = root.querySelector( '[data-cook-wake-toggle]' );
		if ( ! ( 'wakeLock' in navigator ) ) {
			wakeToggle.disabled = true;
			wakeToggle.title = L.wakeLockUnsupported || 'Tento prohlížeč nepodporuje ponechání obrazovky zapnuté.';
		} else {
			wakeToggle.addEventListener( 'click', function () {
				var active = 'true' === wakeToggle.getAttribute( 'aria-pressed' );
				if ( active ) {
					releaseWakeLock();
					wakeToggle.setAttribute( 'aria-pressed', 'false' );
				} else {
					requestWakeLock().then( function () {
						wakeToggle.setAttribute( 'aria-pressed', 'true' );
					} ).catch( function () { /* no-op — stays off, no error surfaced for an opt-in convenience. */ } );
				}
			} );
		}

		if ( window.AtlasTimers ) {
			root.querySelector( '[data-cook-set-timer]' ).addEventListener( 'click', function ( e ) {
				var minutes = e.target.getAttribute( 'data-minutes' );
				var label   = e.target.getAttribute( 'data-label' );
				window.AtlasTimers.start( recipeKey, label, parseInt( minutes, 10 ) );
			} );
		}
	}

	function renderStep( index ) {
		var row = stepRows[ index ];
		if ( ! row ) {
			return;
		}
		currentIndex = index;
		root.querySelector( '[data-cook-step-num]' ).textContent = ( index + 1 ) + ' / ' + stepRows.length;
		root.querySelector( '[data-cook-step-text]' ).textContent = row.querySelector( '.step-text' ).textContent;

		var doneCheck = root.querySelector( '[data-cook-step-done-check]' );
		doneCheck.onchange = null;
		doneCheck.checked = isChecked( 'steps', index );
		doneCheck.onchange = function () { setChecked( 'steps', index, doneCheck.checked ); };

		var timerBtn  = root.querySelector( '[data-cook-set-timer]' );
		var duration  = row.getAttribute( 'data-duration' );
		if ( duration ) {
			timerBtn.hidden = false;
			timerBtn.setAttribute( 'data-minutes', duration );
			timerBtn.setAttribute( 'data-label', ( L.stepLabel || 'Krok' ) + ' ' + ( index + 1 ) );
			timerBtn.textContent = L.setTimer || 'Nastavit časovač';
		} else {
			timerBtn.hidden = true;
		}

		root.querySelector( '[data-cook-prev]' ).disabled = 0 === index;
		root.querySelector( '[data-cook-next]' ).disabled = index === stepRows.length - 1;

		updateProgress();
	}

	function goToStep( index ) {
		if ( index < 0 || index >= stepRows.length ) {
			return;
		}
		renderStep( index );
	}

	function openCookMode() {
		buildOverlay();
		if ( ! overlayBuilt ) {
			return;
		}
		lastFocused = document.activeElement;
		root.hidden = false;
		document.body.classList.add( 'is-cook-mode' );
		renderStep( 0 );
		var closeBtn = root.querySelector( '[data-cook-close]' );
		if ( closeBtn ) {
			closeBtn.focus();
		}
		if ( window.history && window.history.pushState ) {
			window.history.pushState( { atlasCookMode: true }, '', addCookParam( window.location.href ) );
		}
	}

	function closeCookMode() {
		root.hidden = true;
		document.body.classList.remove( 'is-cook-mode' );
		releaseWakeLock();
		if ( lastFocused && lastFocused.focus ) {
			lastFocused.focus();
		}
		if ( window.history && window.history.pushState && hasCookParam( window.location.href ) ) {
			window.history.pushState( {}, '', removeCookParam( window.location.href ) );
		}
	}

	window.addEventListener( 'popstate', function () {
		if ( ! root.hidden && ! hasCookParam( window.location.href ) ) {
			root.hidden = true;
			document.body.classList.remove( 'is-cook-mode' );
			releaseWakeLock();
		}
	} );

	function hasCookParam( url ) {
		return /[?&]cook=1(&|$)/.test( url );
	}
	function addCookParam( url ) {
		return hasCookParam( url ) ? url : url + ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'cook=1';
	}
	function removeCookParam( url ) {
		return url.replace( /([?&])cook=1&?/, '$1' ).replace( /[?&]$/, '' );
	}

	// -----------------------------------------------------------------
	// Wake Lock (item 7/47): opt-in only, released on leaving Cook Mode and
	// re-acquired after the tab becomes visible again while still in Cook
	// Mode (the browser itself force-releases it on tab hide).
	// -----------------------------------------------------------------

	var wakeLock = null;

	function requestWakeLock() {
		return navigator.wakeLock.request( 'screen' ).then( function ( lock ) {
			wakeLock = lock;
		} );
	}
	function releaseWakeLock() {
		if ( wakeLock ) {
			wakeLock.release().catch( function () {} );
			wakeLock = null;
		}
	}
	document.addEventListener( 'visibilitychange', function () {
		var toggle = root.querySelector( '[data-cook-wake-toggle]' );
		if ( toggle && 'true' === toggle.getAttribute( 'aria-pressed' ) && 'visible' === document.visibilityState && ! wakeLock ) {
			requestWakeLock().catch( function () {} );
		}
	} );

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str || '';
		return div.innerHTML;
	}
	function escapeAttr( str ) {
		return ( str || '' ).replace( /"/g, '&quot;' );
	}

	// -----------------------------------------------------------------
	// Bootstrap
	// -----------------------------------------------------------------

	document.addEventListener( 'DOMContentLoaded', function () {
		wireStandardCheckboxes();
		updateProgress();

		var trigger = document.querySelector( '[data-cook-mode-trigger]' );
		if ( trigger ) {
			trigger.addEventListener( 'click', openCookMode );
		}
		if ( hasCookParam( window.location.href ) && trigger ) {
			openCookMode();
		}
	} );
} )();
