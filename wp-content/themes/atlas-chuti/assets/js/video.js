/**
 * KROK 8, item 30-34/47: click-to-load YouTube embed. class-video.php always
 * renders a placeholder button, never an auto-inserted iframe — this file's
 * only job is to swap that button for the real youtube-nocookie.com iframe
 * after an explicit click, which is itself the consent-friendly mechanism
 * item 34 describes (no separate consent gate needed before the click; the
 * click IS the opt-in). No autoplay with sound: the iframe is only created
 * after this direct user action, and never carries `autoplay=1`.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '.atlas-video-load' );
		if ( ! btn ) {
			return;
		}
		var wrapper = btn.closest( '.atlas-video--youtube' );
		if ( ! wrapper ) {
			return;
		}
		var videoId = wrapper.getAttribute( 'data-video-id' );
		if ( ! videoId ) {
			return;
		}
		var iframe = document.createElement( 'iframe' );
		iframe.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent( videoId ) + '?rel=0';
		iframe.title = btn.getAttribute( 'aria-label' ) || 'YouTube video';
		iframe.setAttribute( 'allow', 'accelerometer; encrypted-media; gyroscope; picture-in-picture' );
		iframe.setAttribute( 'allowfullscreen', '' );
		iframe.loading = 'eager';
		iframe.style.width = '100%';
		iframe.style.aspectRatio = '16/9';
		iframe.style.border = '0';
		wrapper.innerHTML = '';
		wrapper.appendChild( iframe );
	} );
} )();
