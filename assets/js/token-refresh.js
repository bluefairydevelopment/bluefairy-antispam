/**
 * BlueFairy Anti-Spam: fetch a fresh time-trap token the first time a visitor
 * starts using a protected form.
 *
 * Tokens are bound to the visitor's network, and a full-page cache would hand
 * every visitor the token minted for whoever loaded the page first. Asking for
 * a fresh token on first use gives each visitor their own. Without JavaScript
 * the token printed in the page is used as before.
 */
( function () {
	'use strict';

	var done = typeof WeakSet === 'function' ? new WeakSet() : null;

	function refresh( form ) {
		if ( ! form || ! done || done.has( form ) ) {
			return;
		}
		done.add( form );

		var fields = form.querySelectorAll( 'input[data-bfas-refresh][data-bfas-form]' );
		Array.prototype.forEach.call( fields, function ( input ) {
			var url = input.getAttribute( 'data-bfas-refresh' );
			var sep = url.indexOf( '?' ) === -1 ? '?' : '&';
			fetch( url + sep + 'form_type=' + encodeURIComponent( input.getAttribute( 'data-bfas-form' ) ), {
				credentials: 'same-origin',
				cache: 'no-store',
			} )
				.then( function ( r ) {
					return r.ok ? r.json() : null;
				} )
				.then( function ( data ) {
					if ( data && typeof data.token === 'string' && data.token !== '' ) {
						input.value = data.token;
					}
				} )
				.catch( function () {} );
		} );
	}

	function onUse( event ) {
		var target = event.target;
		var form = target && target.closest ? target.closest( 'form' ) : null;
		if ( form && form.querySelector( 'input[data-bfas-refresh]' ) ) {
			refresh( form );
		}
	}

	document.addEventListener( 'focusin', onUse );
	document.addEventListener( 'pointerdown', onUse );
} )();
