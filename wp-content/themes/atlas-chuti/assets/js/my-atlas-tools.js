/**
 * KROK 8, item 20-28/47: Collections, Shopping list, Meal planner —
 * REST-backed CRUD against the SAME `atlas-chuti/v1` namespace, auth and
 * nonce pattern as my-atlas.js (item 40/47: never a parallel API). Wires two
 * kinds of surfaces: (1) the recipe page's "Do nákupního seznamu"/"Do
 * kolekce" buttons, (2) the Můj Atlas Kolekce/Nákupní seznam/Plán jídel
 * sections. Every write here is account-bound; ownership is re-checked
 * server-side on every request regardless of what this file sends.
 */
( function () {
	'use strict';

	var USER = window.AtlasChutiUser || {};
	var L    = window.AtlasToolsL10n || {};

	function apiFetch( path, options ) {
		options = options || {};
		var headers = options.headers || {};
		headers['X-WP-Nonce'] = USER.restNonce;
		if ( options.body && 'string' === typeof options.body ) {
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

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str || '';
		return div.innerHTML;
	}

	// -----------------------------------------------------------------
	// Recipe page: "Do nákupního seznamu"
	// -----------------------------------------------------------------

	function wireAddToShoppingList() {
		var btn = document.querySelector( '[data-add-to-shopping-list]' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			apiFetch( '/shopping-list/from-recipe', {
				method: 'POST',
				body: JSON.stringify( {
					recipe_key: btn.getAttribute( 'data-recipe-key' ),
					servings: btn.getAttribute( 'data-servings' ),
				} ),
			} ).then( function () {
				btn.querySelector( '.label' ).textContent = L.addedToShoppingList || 'Přidáno';
			} ).catch( function () {
				window.alert( L.genericError || 'Něco se nepovedlo, zkuste to prosím znovu.' ); // eslint-disable-line no-alert
			} ).finally( function () {
				btn.disabled = false;
			} );
		} );
	}

	// -----------------------------------------------------------------
	// Recipe page: "Do kolekce" picker
	// -----------------------------------------------------------------

	function wireAddToCollection() {
		var trigger = document.querySelector( '[data-add-to-collection-trigger]' );
		var modal   = document.querySelector( '[data-collection-picker]' );
		if ( ! trigger || ! modal ) {
			return;
		}
		trigger.addEventListener( 'click', function () {
			var recipeKey = trigger.getAttribute( 'data-recipe-key' );
			modal.innerHTML = '<div class="collection-picker-modal-inner"><p>' + escapeHtml( L.loading || 'Načítám…' ) + '</p></div>';
			modal.hidden = false;
			apiFetch( '/collections' ).then( function ( collections ) {
				var html = '<div class="collection-picker-modal-inner">' +
					'<h3>' + escapeHtml( L.addToCollection || 'Přidat do kolekce' ) + '</h3>';
				if ( collections.length ) {
					html += '<ul class="my-atlas-comment-list">';
					collections.forEach( function ( c ) {
						html += '<li><button type="button" class="btn btn-outline" data-pick-collection="' + c.id + '" style="width:100%;text-align:left;">' + escapeHtml( c.title ) + '</button></li>';
					} );
					html += '</ul>';
				} else {
					html += '<p>' + escapeHtml( L.noCollectionsYet || 'Zatím nemáte žádnou kolekci.' ) + '</p>';
				}
				html += '<form data-new-collection-inline style="margin-top:var(--space-3);display:flex;gap:8px;">' +
					'<input type="text" maxlength="100" required placeholder="' + escapeHtml( L.newCollectionName || 'Nová kolekce' ) + '">' +
					'<button type="submit" class="btn btn-accent">' + escapeHtml( L.create || 'Vytvořit' ) + '</button>' +
					'</form>' +
					'<button type="button" class="btn btn-outline" data-close-picker style="margin-top:var(--space-3);width:100%;">' + escapeHtml( L.close || 'Zavřít' ) + '</button>' +
					'</div>';
				modal.innerHTML = html;

				modal.querySelectorAll( '[data-pick-collection]' ).forEach( function ( pickBtn ) {
					pickBtn.addEventListener( 'click', function () {
						addRecipeToCollection( pickBtn.getAttribute( 'data-pick-collection' ), recipeKey, modal );
					} );
				} );
				var form = modal.querySelector( '[data-new-collection-inline]' );
				form.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					var input = form.querySelector( 'input' );
					apiFetch( '/collections', { method: 'POST', body: JSON.stringify( { title: input.value } ) } )
						.then( function ( res ) { addRecipeToCollection( res.id, recipeKey, modal ); } )
						.catch( function () { window.alert( L.genericError || 'Něco se nepovedlo.' ); } ); // eslint-disable-line no-alert
				} );
				modal.querySelector( '[data-close-picker]' ).addEventListener( 'click', function () { modal.hidden = true; } );
			} );
		} );
	}

	function addRecipeToCollection( collectionId, recipeKey, modal ) {
		apiFetch( '/collections/' + collectionId + '/items', { method: 'POST', body: JSON.stringify( { recipe_key: recipeKey } ) } )
			.then( function () {
				modal.innerHTML = '<div class="collection-picker-modal-inner"><p>' + escapeHtml( L.addedToCollection || 'Přidáno do kolekce.' ) + '</p></div>';
				window.setTimeout( function () { modal.hidden = true; }, 1200 );
			} )
			.catch( function () { window.alert( L.genericError || 'Něco se nepovedlo.' ); } ); // eslint-disable-line no-alert
	}

	// -----------------------------------------------------------------
	// Můj Atlas → Kolekce
	// -----------------------------------------------------------------

	function wireCollectionsSection() {
		var form = document.querySelector( '[data-collection-create-form]' );
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var input = form.querySelector( 'input[name="title"]' );
				apiFetch( '/collections', { method: 'POST', body: JSON.stringify( { title: input.value } ) } )
					.then( function () { window.location.reload(); } )
					.catch( function () { window.alert( L.genericError || 'Něco se nepovedlo.' ); } ); // eslint-disable-line no-alert
			} );
		}
		document.querySelectorAll( '[data-collection-delete]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( ! window.confirm( L.confirmDeleteCollection || 'Opravdu smazat tuto kolekci?' ) ) { // eslint-disable-line no-alert
					return;
				}
				apiFetch( '/collections/' + btn.getAttribute( 'data-collection-id' ), { method: 'DELETE' } )
					.then( function () { window.location.reload(); } )
					.catch( function () { window.alert( L.genericError || 'Něco se nepovedlo.' ); } ); // eslint-disable-line no-alert
			} );
		} );
	}

	// -----------------------------------------------------------------
	// Můj Atlas → Nákupní seznam
	// -----------------------------------------------------------------

	function wireShoppingListSection() {
		var list = document.querySelector( '[data-shopping-list]' );
		if ( ! list ) {
			return;
		}
		list.querySelectorAll( '[data-shopping-check]' ).forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', function () {
				var row = checkbox.closest( '[data-shopping-item-id]' );
				apiFetch( '/shopping-list/' + row.getAttribute( 'data-shopping-item-id' ), {
					method: 'POST',
					body: JSON.stringify( { checked: checkbox.checked } ),
				} ).then( function () {
					row.classList.toggle( 'is-checked', checkbox.checked );
				} );
			} );
		} );
		list.querySelectorAll( '[data-shopping-remove]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var row = btn.closest( '[data-shopping-item-id]' );
				apiFetch( '/shopping-list/' + row.getAttribute( 'data-shopping-item-id' ), { method: 'DELETE' } )
					.then( function () { row.remove(); } );
			} );
		} );

		var addForm = document.querySelector( '[data-shopping-add-form]' );
		if ( addForm ) {
			addForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				apiFetch( '/shopping-list', {
					method: 'POST',
					body: JSON.stringify( {
						display_name: addForm.querySelector( '[name="display_name"]' ).value,
						quantity_text: addForm.querySelector( '[name="quantity_text"]' ).value,
					} ),
				} ).then( function () { window.location.reload(); } )
					.catch( function () { window.alert( L.genericError || 'Něco se nepovedlo.' ); } ); // eslint-disable-line no-alert
			} );
		}

		var clearBtn = document.querySelector( '[data-shopping-clear-checked]' );
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				apiFetch( '/shopping-list/clear-checked', { method: 'POST' } )
					.then( function () { window.location.reload(); } );
			} );
		}
	}

	// -----------------------------------------------------------------
	// Můj Atlas → Plán jídel
	// -----------------------------------------------------------------

	function wireMealPlanSection() {
		var week = document.querySelector( '[data-meal-plan-week]' );
		if ( ! week ) {
			return;
		}
		week.querySelectorAll( '[data-meal-plan-remove]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var row = btn.closest( '[data-meal-plan-item-id]' );
				apiFetch( '/meal-plan/' + row.getAttribute( 'data-meal-plan-item-id' ), { method: 'DELETE' } )
					.then( function () { row.remove(); } );
			} );
		} );

		var picker = document.querySelector( '[data-meal-plan-picker]' );
		week.querySelectorAll( '[data-meal-plan-add]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				openMealPlanPicker( picker, btn.getAttribute( 'data-date' ), btn.getAttribute( 'data-slot' ) );
			} );
		} );

		var toShoppingBtn = document.querySelector( '[data-meal-plan-to-shopping]' );
		if ( toShoppingBtn ) {
			toShoppingBtn.addEventListener( 'click', function () {
				toShoppingBtn.disabled = true;
				apiFetch( '/meal-plan/add-to-shopping-list', {
					method: 'POST',
					body: JSON.stringify( { start: toShoppingBtn.getAttribute( 'data-week-start' ), end: toShoppingBtn.getAttribute( 'data-week-end' ) } ),
				} ).then( function ( res ) {
					toShoppingBtn.textContent = ( L.addedIngredientsFormat || 'Přidáno %d ingrediencí' ).replace( '%d', res.added );
				} ).finally( function () { toShoppingBtn.disabled = false; } );
			} );
		}
	}

	function openMealPlanPicker( modal, date, slot ) {
		if ( ! modal ) {
			return;
		}
		modal.hidden = false;
		modal.innerHTML =
			'<div class="collection-picker-modal-inner">' +
				'<h3>' + escapeHtml( L.pickRecipe || 'Vybrat recept' ) + '</h3>' +
				'<input type="text" data-meal-plan-search placeholder="' + escapeHtml( L.searchRecipes || 'Hledat recept podle názvu…' ) + '">' +
				'<ul class="my-atlas-comment-list" data-meal-plan-results></ul>' +
				'<button type="button" class="btn btn-outline" data-close-picker style="margin-top:var(--space-3);width:100%;">' + escapeHtml( L.close || 'Zavřít' ) + '</button>' +
			'</div>';
		modal.querySelector( '[data-close-picker]' ).addEventListener( 'click', function () { modal.hidden = true; } );

		var input   = modal.querySelector( '[data-meal-plan-search]' );
		var results = modal.querySelector( '[data-meal-plan-results]' );
		var timer   = null;
		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			var q = input.value.trim();
			if ( q.length < 2 ) {
				results.innerHTML = '';
				return;
			}
			timer = window.setTimeout( function () {
				fetch( ( window.wpApiSettings && window.wpApiSettings.root ? window.wpApiSettings.root : '/wp-json/' ) + 'wp/v2/atlas_recipe?search=' + encodeURIComponent( q ) + '&status=publish&per_page=8&_fields=id,title,link,meta' )
					.then( function ( res ) { return res.json(); } )
					.then( function ( posts ) {
						results.innerHTML = '';
						( posts || [] ).forEach( function ( post ) {
							var recipeKey = post.meta && post.meta.atlas_recipe_key ? post.meta.atlas_recipe_key : '';
							if ( ! recipeKey ) {
								return;
							}
							var li = document.createElement( 'li' );
							var btn = document.createElement( 'button' );
							btn.type = 'button';
							btn.className = 'btn btn-outline';
							btn.style.cssText = 'width:100%;text-align:left;';
							btn.textContent = post.title.rendered;
							btn.addEventListener( 'click', function () {
								apiFetch( '/meal-plan', {
									method: 'POST',
									body: JSON.stringify( { plan_date: date, meal_slot: slot, recipe_key: recipeKey } ),
								} ).then( function () { window.location.reload(); } )
									.catch( function () { window.alert( L.genericError || 'Něco se nepovedlo.' ); } ); // eslint-disable-line no-alert
							} );
							li.appendChild( btn );
							results.appendChild( li );
						} );
					} );
			}, 300 );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		wireAddToShoppingList();
		wireAddToCollection();
		wireCollectionsSection();
		wireShoppingListSection();
		wireMealPlanSection();
	} );
} )();
