/**
 * KROK 5: the account-aware interaction layer — favorite/cooked toggle, rating
 * widget, photo upload, and the Passport localStorage→account merge banner. Every
 * interactive element here already works as a plain <form>/<button> without this
 * file (item 27/29 — graceful degradation); this only progressively enhances them
 * into async REST calls. Separate from passport.js's AtlasPassport (which stays
 * the pure anonymous/local mechanism) and from AtlasChutiShareL10n — three
 * independent localized objects that never collide (see functions.php).
 */
( function () {
	'use strict';

	var USER  = window.AtlasChutiUser || {};
	var L10N  = window.AtlasChutiInteractionsL10n || {};

	function apiFetch( path, options ) {
		options = options || {};
		var headers = options.headers || {};
		headers['X-WP-Nonce'] = USER.restNonce;
		if ( options.body && ! ( options.body instanceof FormData ) ) {
			headers['Content-Type'] = 'application/json';
		}
		options.headers = headers;
		options.credentials = 'same-origin';
		return fetch( ( USER.restUrl || '' ) + path, options ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( data.message || 'error' );
					err.code = data.code;
					err.status = res.status;
					throw err;
				}
				return data;
			} );
		} );
	}

	function announce( container, message ) {
		if ( ! container || ! message ) {
			return;
		}
		var status = container.querySelector( '[data-rating-status],[data-upload-status]' );
		if ( status ) {
			status.textContent = message;
		}
	}

	// -----------------------------------------------------------------
	// Favorite button (recipe action bar)
	// -----------------------------------------------------------------

	function renderFavoriteState( btn, isFavorite ) {
		if ( ! btn ) {
			return;
		}
		btn.classList.toggle( 'is-active', !! isFavorite );
		btn.setAttribute( 'aria-pressed', isFavorite ? 'true' : 'false' );
		var label = btn.querySelector( '.label' );
		if ( label ) {
			label.textContent = isFavorite ? L10N.favorited : L10N.favorite;
		}
	}

	function wireFavoriteButton() {
		var form = document.querySelector( '[data-state-form][data-state="favorite"]' );
		if ( ! form ) {
			return;
		}
		var btn = form.querySelector( '[data-favorite-btn]' );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var type = form.getAttribute( 'data-subject-type' );
			var key  = form.getAttribute( 'data-subject-key' );
			btn.disabled = true;
			apiFetch( '/state/toggle', { method: 'POST', body: JSON.stringify( { type: type, key: key, state: 'favorite' } ) } )
				.then( function ( data ) {
					renderFavoriteState( btn, data.state );
				} )
				.catch( function () {
					window.alert( L10N.genericError ); // eslint-disable-line no-alert -- no dedicated status region on this compact button; a rare failure path.
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	}

	// -----------------------------------------------------------------
	// Cooked button (account mode only — anonymous stays passport.js/localStorage)
	// -----------------------------------------------------------------

	function wireCookedButtonAccount() {
		if ( ! USER.loggedIn ) {
			return;
		}
		var btn = document.querySelector( '[data-passport-recipe-toggle]' );
		if ( ! btn ) {
			return;
		}
		var data;
		try {
			data = JSON.parse( btn.getAttribute( 'data-recipe' ) );
		} catch ( e ) {
			return;
		}
		if ( ! data ) {
			return;
		}
		var L = window.AtlasChutiL10n || {};
		var render = function ( cooked ) {
			btn.classList.toggle( 'is-active', cooked );
			btn.setAttribute( 'aria-pressed', cooked ? 'true' : 'false' );
			var label = btn.querySelector( '.label' );
			if ( label ) {
				label.textContent = cooked ? L.recipeCooked : L.recipeMarkCooked;
			}
		};
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			apiFetch( '/state/toggle', { method: 'POST', body: JSON.stringify( { type: 'recipe', key: data.recipe_key || data.slug, state: 'cooked' } ) } )
				.then( function ( res ) {
					render( !! res.state );
				} )
				.catch( function () {
					window.alert( L10N.genericError ); // eslint-disable-line no-alert
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	}

	/**
	 * Item 27: the action bar always renders in a NEUTRAL default state (never a
	 * personalized state baked into potentially-cached HTML) — this fetch, right
	 * after load, is what actually applies the real favorite/cooked state for a
	 * logged-in visitor.
	 */
	function bootstrapRecipeState() {
		if ( ! USER.loggedIn ) {
			return;
		}
		var favForm   = document.querySelector( '[data-state-form][data-state="favorite"]' );
		var cookedBtn = document.querySelector( '[data-passport-recipe-toggle]' );
		var recipeKey = favForm ? favForm.getAttribute( 'data-subject-key' ) : null;
		if ( ! recipeKey && cookedBtn ) {
			try {
				recipeKey = ( JSON.parse( cookedBtn.getAttribute( 'data-recipe' ) ) || {} ).recipe_key;
			} catch ( e ) { /* no-op */ }
		}
		if ( ! recipeKey ) {
			return;
		}
		apiFetch( '/state?type=recipe&key=' + encodeURIComponent( recipeKey ) )
			.then( function ( data ) {
				if ( favForm ) {
					renderFavoriteState( favForm.querySelector( '[data-favorite-btn]' ), data.favorite );
				}
				if ( cookedBtn ) {
					var L = window.AtlasChutiL10n || {};
					cookedBtn.classList.toggle( 'is-active', !! data.cooked );
					cookedBtn.setAttribute( 'aria-pressed', data.cooked ? 'true' : 'false' );
					var label = cookedBtn.querySelector( '.label' );
					if ( label ) {
						label.textContent = data.cooked ? L.recipeCooked : L.recipeMarkCooked;
					}
				}
			} )
			.catch( function () { /* silent — the neutral default state stays visible. */ } );
	}

	// -----------------------------------------------------------------
	// Rating widget — the visible star is a <label> around a real, natively
	// keyboard-accessible <input type="radio"> (item 29: no custom keyboard
	// re-implementation needed, arrow-key radiogroup navigation is native browser
	// behavior for free). JS listens for the radio's own `change` event.
	// -----------------------------------------------------------------

	function wireRatingWidget() {
		var widget = document.querySelector( '[data-rating-widget]' );
		if ( ! widget ) {
			return;
		}
		var recipeKey = widget.getAttribute( 'data-recipe-key' );
		var radios    = widget.querySelectorAll( '[data-rating-radio]' );
		var statusEl  = widget.querySelector( '[data-rating-status]' );
		var summaryEl = widget.querySelector( '[data-rating-summary]' );

		function renderSummary( aggregate ) {
			if ( ! summaryEl || ! aggregate ) {
				return;
			}
			if ( aggregate.count > 0 ) {
				var full  = Math.round( aggregate.average );
				summaryEl.innerHTML =
					'<span class="rating-stars-display" aria-hidden="true">' + '★'.repeat( full ) + '☆'.repeat( 5 - full ) + '</span> ' +
					'<span class="rating-value">' + aggregate.average.toFixed( 1 ) + ' / 5</span> ' +
					'<span class="rating-count">(' + aggregate.count + ')</span>';
			} else {
				summaryEl.innerHTML = '<span class="rating-empty">' + ( L10N.noRatingsYet || '' ) + '</span>';
			}
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( ! radio.checked ) {
					return;
				}
				if ( statusEl ) {
					statusEl.textContent = '';
				}
				apiFetch( '/rating', { method: 'POST', body: JSON.stringify( { recipe_key: recipeKey, rating: parseInt( radio.value, 10 ) } ) } )
					.then( function ( data ) {
						renderSummary( data.aggregate );
						if ( statusEl ) {
							statusEl.textContent = L10N.ratingSaved || '';
						}
					} )
					.catch( function ( err ) {
						if ( statusEl ) {
							statusEl.textContent = 429 === err.status ? L10N.rateLimited : L10N.genericError;
						}
					} );
			} );
		} );

		// Personalize "my rating" after load (item 27) — the aggregate summary
		// itself was already server-rendered/cacheable; this also opportunistically
		// refreshes it in case it changed since the page was cached.
		apiFetch( '/rating?recipe_key=' + encodeURIComponent( recipeKey ) )
			.then( function ( data ) {
				if ( data.mine ) {
					var target = widget.querySelector( '[data-rating-radio][value="' + data.mine + '"]' );
					if ( target ) {
						target.checked = true;
					}
				}
				renderSummary( data.aggregate );
			} )
			.catch( function () { /* silent — server-rendered summary stays as-is. */ } );
	}

	// -----------------------------------------------------------------
	// Photo upload
	// -----------------------------------------------------------------

	function wirePhotoUpload() {
		var form = document.querySelector( '[data-photo-upload]' );
		if ( ! form ) {
			return;
		}
		var status = form.querySelector( '[data-upload-status]' );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var fileInput = form.querySelector( 'input[type="file"]' );
			if ( ! fileInput || ! fileInput.files.length ) {
				return;
			}
			var fd = new FormData();
			fd.append( 'photo', fileInput.files[ 0 ] );
			fd.append( 'recipe_key', form.getAttribute( 'data-recipe-key' ) );
			if ( status ) {
				status.textContent = '';
			}
			apiFetch( '/photos', { method: 'POST', body: fd } )
				.then( function () {
					if ( status ) {
						status.textContent = L10N.uploadPending || '';
					}
					form.reset();
				} )
				.catch( function ( err ) {
					var msg = L10N.uploadRejected;
					if ( 'atlas_photo_too_large' === err.code ) {
						msg = L10N.uploadTooLarge;
					} else if ( 'atlas_photo_bad_type' === err.code ) {
						msg = L10N.uploadBadType;
					}
					if ( status ) {
						status.textContent = msg;
					}
				} );
		} );
	}

	// -----------------------------------------------------------------
	// Passport localStorage → account merge (item 15)
	// -----------------------------------------------------------------

	function wirePassportMergeBanner() {
		var banner = document.querySelector( '[data-passport-merge-banner]' );
		if ( ! banner || ! USER.loggedIn || 'undefined' === typeof window.AtlasPassport ) {
			return;
		}
		if ( '1' === safeLocalStorageGet( 'atlasPassport.mergeDismissed' ) ) {
			return;
		}
		var recipes   = AtlasPassport.getCookedRecipes();
		var countries = AtlasPassport.getTastedCountries();
		if ( ! Object.keys( recipes ).length && ! Object.keys( countries ).length ) {
			return;
		}

		banner.hidden = false;
		banner.querySelector( '[data-merge-title]' ).textContent = L10N.passportFoundTitle || '';
		banner.querySelector( '[data-merge-body]' ).textContent = L10N.passportFoundBody || '';
		var acceptBtn = banner.querySelector( '[data-merge-accept]' );
		var declineBtn = banner.querySelector( '[data-merge-decline]' );
		acceptBtn.textContent = L10N.passportMergeYes || '';
		declineBtn.textContent = L10N.passportMergeNo || '';

		acceptBtn.addEventListener( 'click', function () {
			acceptBtn.disabled = true;
			apiFetch( '/passport/merge', { method: 'POST', body: JSON.stringify( { recipes: recipes, countries: countries } ) } )
				.then( function () {
					// Item 15: localStorage is cleared ONLY after the server confirms the
					// merge succeeded — never before.
					AtlasPassport.clearAll();
					safeLocalStorageSet( 'atlasPassport.mergeDismissed', '1' );
					banner.querySelector( '[data-merge-body]' ).textContent = L10N.passportMerged || '';
					acceptBtn.hidden = true;
					declineBtn.hidden = true;
					window.setTimeout( function () { window.location.reload(); }, 1200 );
				} )
				.catch( function () {
					acceptBtn.disabled = false;
				} );
		} );
		declineBtn.addEventListener( 'click', function () {
			safeLocalStorageSet( 'atlasPassport.mergeDismissed', '1' );
			banner.hidden = true;
		} );
	}

	function safeLocalStorageGet( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}
	function safeLocalStorageSet( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) { /* no-op — private mode/quota, same tolerance as passport.js. */ }
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		wireFavoriteButton();
		wireCookedButtonAccount();
		bootstrapRecipeState();
		wireRatingWidget();
		wirePhotoUpload();
		wirePassportMergeBanner();
	} );
} )();
