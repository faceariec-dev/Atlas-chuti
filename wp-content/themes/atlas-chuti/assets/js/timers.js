/**
 * KROK 8, item 4-6/47: kitchen timers bound to a recipe.
 *
 * The state model uses an ABSOLUTE `end_at` (epoch ms), never a decrementing
 * JS counter — a backgrounded/throttled tab or a full page reload just
 * re-compares Date.now() against the same stored end_at, so a timer started
 * before a reload still reads correctly afterwards. State is pure
 * client-side (localStorage), one array per recipe_key; never sent to the
 * server (item 34/47 — Cook checklist/timers stay local-only).
 *
 * Timer-from-step only ever fires from a real "Nastavit časovač" click on a
 * step that HAS structured duration_minutes data (see single-atlas_recipe.php)
 * — this file never parses a duration out of step text.
 *
 * Notification permission is requested ONLY from an explicit "Povolit
 * upozornění" click in the timer tray, never on page load and never as a
 * side effect of starting a timer. Without permission (or without browser
 * support), the fallback is a visible highlight + an audio cue in the open
 * tab — the audio context itself is unlocked by the same real click that
 * starts a timer (a genuine user gesture), never autoplayed.
 */
( function () {
	'use strict';

	var tray = document.getElementById( 'atlas-timer-tray' );
	if ( ! tray ) {
		return;
	}

	var L = window.AtlasCookL10n || {};
	var recipeKey = ( document.querySelector( '#ingredience[data-cook-mode]' ) || {} ).getAttribute
		? document.querySelector( '#ingredience[data-cook-mode]' ).getAttribute( 'data-recipe-key' )
		: '';
	var storageKey = 'atlasTimers:' + recipeKey;

	var timers = loadTimers();
	var nextId = timers.reduce( function ( max, t ) { return Math.max( max, t.id ); }, 0 ) + 1;
	var audioCtx = null;
	var notifyEnabled = false;

	function loadTimers() {
		try {
			var raw = window.localStorage.getItem( storageKey );
			var parsed = raw ? JSON.parse( raw ) : [];
			return Array.isArray( parsed ) ? parsed.filter( function ( t ) { return 'completed' !== t.state || ( Date.now() - t.endAt ) < 3600000; } ) : [];
		} catch ( e ) {
			return [];
		}
	}

	function saveTimers() {
		try {
			window.localStorage.setItem( storageKey, JSON.stringify( timers ) );
		} catch ( e ) { /* no-op — private mode/quota; timers stay in-memory for this load. */ }
	}

	function unlockAudio() {
		if ( audioCtx ) {
			return;
		}
		var Ctx = window.AudioContext || window.webkitAudioContext;
		if ( ! Ctx ) {
			return;
		}
		audioCtx = new Ctx();
	}

	function playBeep() {
		if ( ! audioCtx ) {
			return;
		}
		try {
			var osc  = audioCtx.createOscillator();
			var gain = audioCtx.createGain();
			osc.frequency.value = 880;
			osc.connect( gain );
			gain.connect( audioCtx.destination );
			gain.gain.setValueAtTime( 0.2, audioCtx.currentTime );
			osc.start();
			osc.stop( audioCtx.currentTime + 0.6 );
		} catch ( e ) { /* no-op — audio is a convenience cue, never required. */ }
	}

	function notify( timer ) {
		if ( notifyEnabled && 'Notification' in window && 'granted' === Notification.permission ) {
			try {
				new Notification( L.timerDoneTitle || 'Časovač dokončen', { body: timer.label } );
			} catch ( e ) { /* no-op */ }
		}
		playBeep();
	}

	// -----------------------------------------------------------------
	// Public API — used by "Nastavit časovač" buttons and Cook Mode.
	// -----------------------------------------------------------------

	window.AtlasTimers = {
		start: function ( scopeRecipeKey, label, minutes ) {
			unlockAudio(); // real user click → safe to unlock now, before any later autonomous playback.
			if ( ! minutes || minutes <= 0 ) {
				return;
			}
			var now = Date.now();
			timers.push( {
				id: nextId++,
				label: label || ( L.timer || 'Časovač' ),
				durationSeconds: minutes * 60,
				endAt: now + minutes * 60000,
				state: 'running',
			} );
			saveTimers();
			render();
		},
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-set-timer]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				window.AtlasTimers.start( recipeKey, btn.getAttribute( 'data-label' ), parseInt( btn.getAttribute( 'data-minutes' ), 10 ) );
			} );
		} );
		if ( timers.length ) {
			render();
		}
		window.setInterval( tick, 1000 );
	} );

	function tick() {
		var changed = false;
		timers.forEach( function ( t ) {
			if ( 'running' === t.state && Date.now() >= t.endAt ) {
				t.state = 'completed';
				changed = true;
				notify( t );
			}
		} );
		if ( changed ) {
			saveTimers();
		}
		if ( timers.length ) {
			render();
		}
	}

	function formatRemaining( t ) {
		var ms = 'paused' === t.state ? t.remainingMs : Math.max( 0, t.endAt - Date.now() );
		var totalSeconds = Math.ceil( ms / 1000 );
		var m = Math.floor( totalSeconds / 60 );
		var s = totalSeconds % 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	function render() {
		tray.hidden = 0 === timers.length;
		if ( ! timers.length ) {
			tray.innerHTML = '';
			return;
		}
		if ( ! tray.querySelector( '[data-timer-list]' ) ) {
			tray.innerHTML =
				'<div class="timer-tray-inner">' +
					( ( 'Notification' in window && 'granted' !== Notification.permission )
						? '<button type="button" class="timer-notify-toggle" data-timer-enable-notify>' + escapeHtml( L.enableNotifications || 'Povolit upozornění' ) + '</button>'
						: '' ) +
					'<ul class="timer-list" data-timer-list></ul>' +
				'</div>';
			var notifyBtn = tray.querySelector( '[data-timer-enable-notify]' );
			if ( notifyBtn ) {
				notifyBtn.addEventListener( 'click', function () {
					Notification.requestPermission().then( function ( perm ) {
						notifyEnabled = 'granted' === perm;
						notifyBtn.hidden = true;
					} );
				} );
			}
		}
		var list = tray.querySelector( '[data-timer-list]' );
		list.innerHTML = '';
		timers.forEach( function ( t ) {
			var li = document.createElement( 'li' );
			li.className = 'timer-item timer-item--' + t.state;
			li.innerHTML =
				'<span class="timer-item-label">' + escapeHtml( t.label ) + '</span>' +
				'<span class="timer-item-time" aria-live="' + ( 'completed' === t.state ? 'assertive' : 'off' ) + '">' +
					( 'completed' === t.state ? ( L.timerDone || 'Hotovo!' ) : formatRemaining( t ) ) +
				'</span>' +
				'<span class="timer-item-actions"></span>';
			var actions = li.querySelector( '.timer-item-actions' );
			if ( 'completed' !== t.state ) {
				var toggleBtn = document.createElement( 'button' );
				toggleBtn.type = 'button';
				toggleBtn.className = 'timer-item-btn';
				toggleBtn.textContent = 'running' === t.state ? ( L.pause || 'Pauza' ) : ( L.resume || 'Pokračovat' );
				toggleBtn.addEventListener( 'click', function () { toggleTimer( t.id ); } );
				actions.appendChild( toggleBtn );
			}
			var removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'timer-item-btn';
			removeBtn.setAttribute( 'aria-label', L.removeTimer || 'Odebrat časovač' );
			removeBtn.textContent = '×';
			removeBtn.addEventListener( 'click', function () { removeTimer( t.id ); } );
			actions.appendChild( removeBtn );
			list.appendChild( li );
		} );
	}

	function toggleTimer( id ) {
		var t = timers.filter( function ( x ) { return x.id === id; } )[ 0 ];
		if ( ! t ) {
			return;
		}
		if ( 'running' === t.state ) {
			t.remainingMs = Math.max( 0, t.endAt - Date.now() );
			t.state = 'paused';
		} else if ( 'paused' === t.state ) {
			t.endAt = Date.now() + t.remainingMs;
			t.state = 'running';
		}
		saveTimers();
		render();
	}

	function removeTimer( id ) {
		timers = timers.filter( function ( x ) { return x.id !== id; } );
		saveTimers();
		render();
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str || '';
		return div.innerHTML;
	}
} )();
