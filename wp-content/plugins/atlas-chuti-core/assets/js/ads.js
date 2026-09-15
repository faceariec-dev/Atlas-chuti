/**
 * KROK 7, item 22/39: vanilla, dependency-free lazy trigger for ad slots. A
 * direct-campaign creative is a plain `<img loading="lazy">` — the browser
 * already handles that natively, this script never touches it. What this
 * script IS for: the `[data-ad-provider-target]` placeholder a future real
 * external-network slot renders into (see class-advertising.php's
 * render_wrapper()) — once that placeholder scrolls near the viewport, this
 * dispatches a plain DOM event a real provider's own script would listen for
 * to actually request/inject its ad unit. No provider is wired up yet, so
 * today this event simply fires into nothing — that is expected, not a bug.
 */
( function () {
	'use strict';

	function reveal( target ) {
		target.dispatchEvent( new CustomEvent( 'atlas-ad-visible', { bubbles: true } ) );
	}

	var targets = document.querySelectorAll( '[data-ad-lazy="1"] [data-ad-provider-target]' );
	if ( ! targets.length ) {
		return;
	}

	if ( ! ( 'IntersectionObserver' in window ) ) {
		// Fallback graceful (item 22): no IntersectionObserver support — reveal
		// everything immediately rather than never firing at all.
		Array.prototype.forEach.call( targets, reveal );
		return;
	}

	var observer = new IntersectionObserver(
		function ( entries, obs ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					reveal( entry.target );
					obs.unobserve( entry.target );
				}
			} );
		},
		{ rootMargin: '200px 0px' }
	);

	Array.prototype.forEach.call( targets, function ( target ) {
		observer.observe( target );
	} );
} )();
