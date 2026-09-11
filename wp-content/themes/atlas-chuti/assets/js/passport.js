/**
 * Kulinářský pas (item 14 of the brief). V1 is intentionally accounts-free and
 * stores everything in localStorage — but every write goes through this one API
 * object, so a future account sync only has to replace load()/save() with REST
 * calls, nothing else in the site has to change.
 */
window.AtlasPassport = ( function () {
	'use strict';

	var KEY_RECIPES = 'atlasPassport.recipes';
	var KEY_COUNTRIES = 'atlasPassport.countries';

	function safeParse( raw ) {
		try {
			var v = JSON.parse( raw );
			return v && typeof v === 'object' ? v : {};
		} catch ( e ) {
			return {};
		}
	}

	function load( key ) {
		try {
			return safeParse( window.localStorage.getItem( key ) );
		} catch ( e ) {
			return {};
		}
	}

	function save( key, obj ) {
		try {
			window.localStorage.setItem( key, JSON.stringify( obj ) );
		} catch ( e ) {
			/* localStorage unavailable (private mode, quota) — fail silently, UI just won't persist. */
		}
	}

	function toggleRecipe( data ) {
		var key = data.recipe_key || data.slug;
		var all = load( KEY_RECIPES );
		var nowCooked;
		if ( all[ key ] ) {
			delete all[ key ];
			nowCooked = false;
		} else {
			all[ key ] = data;
			nowCooked = true;
		}
		save( KEY_RECIPES, all );
		return nowCooked;
	}

	function toggleCountry( data ) {
		var key = data.iso || data.slug;
		var all = load( KEY_COUNTRIES );
		var nowTasted;
		if ( all[ key ] ) {
			delete all[ key ];
			nowTasted = false;
		} else {
			all[ key ] = data;
			nowTasted = true;
		}
		save( KEY_COUNTRIES, all );
		return nowTasted;
	}

	function isRecipeCooked( key ) {
		return !! load( KEY_RECIPES )[ key ];
	}

	function isCountryTasted( key ) {
		return !! load( KEY_COUNTRIES )[ key ];
	}

	function getCookedRecipes() {
		return load( KEY_RECIPES );
	}

	function getTastedCountries() {
		return load( KEY_COUNTRIES );
	}

	function clearAll() {
		save( KEY_RECIPES, {} );
		save( KEY_COUNTRIES, {} );
	}

	return {
		toggleRecipe: toggleRecipe,
		toggleCountry: toggleCountry,
		isRecipeCooked: isRecipeCooked,
		isCountryTasted: isCountryTasted,
		getCookedRecipes: getCookedRecipes,
		getTastedCountries: getTastedCountries,
		clearAll: clearAll,
	};
} )();

( function () {
	'use strict';

	var L10N = window.AtlasChutiL10n || {};

	function readJSON( el, attr ) {
		try {
			return JSON.parse( el.getAttribute( attr ) );
		} catch ( e ) {
			return null;
		}
	}

	function wireRecipeButton() {
		var btn = document.querySelector( '[data-passport-recipe-toggle]' );
		if ( ! btn ) {
			return;
		}
		var data = readJSON( btn, 'data-recipe' );
		if ( ! data ) {
			return;
		}
		var render = function () {
			var cooked = AtlasPassport.isRecipeCooked( data.recipe_key || data.slug );
			btn.classList.toggle( 'is-active', cooked );
			btn.setAttribute( 'aria-pressed', cooked ? 'true' : 'false' );
			btn.querySelector( '.label' ).textContent = cooked ? L10N.recipeCooked : L10N.recipeMarkCooked;
		};
		btn.addEventListener( 'click', function () {
			AtlasPassport.toggleRecipe( data );
			render();
		} );
		render();
	}

	function wireCountryButton() {
		var btn = document.querySelector( '[data-passport-country-toggle]' );
		if ( ! btn ) {
			return;
		}
		var data = readJSON( btn, 'data-country' );
		if ( ! data ) {
			return;
		}
		var render = function () {
			var tasted = AtlasPassport.isCountryTasted( data.iso || data.slug );
			btn.classList.toggle( 'is-active', tasted );
			btn.setAttribute( 'aria-pressed', tasted ? 'true' : 'false' );
			btn.querySelector( '.label' ).textContent = tasted ? L10N.countryTasted : L10N.countryMarkTasted;
		};
		btn.addEventListener( 'click', function () {
			AtlasPassport.toggleCountry( data );
			render();
		} );
		render();
	}

	function renderMiniWidget() {
		var widget = document.querySelector( '[data-passport-widget]' );
		if ( ! widget || typeof AtlasChutiContinents === 'undefined' ) {
			return;
		}
		var totalCountries = AtlasChutiContinents.reduce( function ( sum, c ) {
			return sum + c.total;
		}, 0 );
		var tasted = AtlasPassport.getTastedCountries();
		var tastedCount = Object.keys( tasted ).length;

		var countEl = widget.querySelector( '[data-passport-count]' );
		if ( countEl ) {
			countEl.textContent = ( L10N.countsFormat || '%1$d / %2$d' ).replace( '%1$d', tastedCount ).replace( '%2$d', totalCountries );
		}

		var flagsEl = widget.querySelector( '[data-passport-flags]' );
		if ( flagsEl ) {
			var allCountries = AtlasChutiContinents.reduce( function ( acc, c ) {
				return acc.concat( c.countries );
			}, [] );
			flagsEl.innerHTML = allCountries
				.slice( 0, 16 )
				.map( function ( c ) {
					var isTasted = !! tasted[ c.iso ];
					return '<span style="opacity:' + ( isTasted ? 1 : 0.3 ) + ';filter:' + ( isTasted ? 'none' : 'grayscale(1)' ) + ';">' + c.flag + '</span>';
				} )
				.join( '' );
		}
	}

	function recipeCardHTML( r ) {
		var img = r.image ? '<img src="' + r.image + '" alt="" loading="lazy">' : '<div class="placeholder-media"><span>' + r.title + '</span></div>';
		return (
			'<div style="position:relative;">' +
			'<a class="card" href="' + ( r.url || '#' ) + '">' +
			'<div class="card-media">' + img + '</div>' +
			'<div class="card-body">' +
			'<div class="card-eyebrow"><span>' + ( r.flag || '' ) + '</span><span>' + ( r.country || '' ) + '</span></div>' +
			'<h3 class="card-title">' + r.title + '</h3>' +
			'<div class="card-meta">' + [ r.time, r.difficulty ].filter( Boolean ).join( ' · ' ) + '</div>' +
			'</div></a>' +
			'<span class="cooked-badge"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="4,13 9,18 20,6"></polyline></svg></span>' +
			'</div>'
		);
	}

	function renderPassportPage() {
		var page = document.querySelector( '[data-passport-page]' );
		if ( ! page || typeof AtlasChutiContinents === 'undefined' ) {
			return;
		}

		var tastedCountries = AtlasPassport.getTastedCountries();
		var totalCountries = AtlasChutiContinents.reduce( function ( sum, c ) {
			return sum + c.total;
		}, 0 );
		var tastedCount = Object.keys( tastedCountries ).length;

		var countEl = page.querySelector( '[data-total-count]' );
		if ( countEl ) {
			countEl.innerHTML = tastedCount + '<span> / ' + totalCountries + '</span>';
		}
		var progressFill = page.querySelector( '[data-progress-fill]' );
		if ( progressFill ) {
			progressFill.style.width = ( totalCountries ? ( tastedCount / totalCountries ) * 100 : 0 ) + '%';
		}

		var continentsWrap = page.querySelector( '[data-continent-list]' );
		if ( continentsWrap ) {
			continentsWrap.innerHTML = AtlasChutiContinents.map( function ( c ) {
				var localTasted = c.countries.filter( function ( country ) {
					return !! tastedCountries[ country.iso ];
				} ).length;
				var flags = c.countries
					.map( function ( country ) {
						var isTasted = !! tastedCountries[ country.iso ];
						return '<span style="opacity:' + ( isTasted ? 1 : 0.3 ) + ';filter:' + ( isTasted ? 'none' : 'grayscale(1)' ) + ';">' + country.flag + '</span>';
					} )
					.join( '' );
				return (
					'<div class="continent-progress-card"><div class="continent-progress-head"><h3>' +
					c.name +
					'</h3><span>' +
					localTasted +
					' / ' +
					c.total +
					'</span></div><div class="passport-flags">' +
					flags +
					'</div></div>'
				);
			} ).join( '' );
		}

		var cookedWrap = page.querySelector( '[data-cooked-list]' );
		if ( cookedWrap ) {
			var cooked = AtlasPassport.getCookedRecipes();
			var keys = Object.keys( cooked );
			if ( ! keys.length ) {
				cookedWrap.innerHTML = '<p class="passport-empty">' + L10N.noCookedRecipes + '</p>';
			} else {
				cookedWrap.innerHTML = '<div class="card-grid card-grid-4">' + keys.map( function ( k ) { return recipeCardHTML( cooked[ k ] ); } ).join( '' ) + '</div>';
			}
		}
	}

	function wireClearButton() {
		var btn = document.querySelector( '[data-passport-clear]' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			if ( window.confirm( L10N.confirmClear ) ) {
				AtlasPassport.clearAll();
				renderPassportPage();
				renderMiniWidget();
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		wireRecipeButton();
		wireCountryButton();
		renderMiniWidget();
		renderPassportPage();
		wireClearButton();
	} );
} )();
