/**
 * BlueFairy Anti-Spam: swap each time-trap token for a fresh one, once, as the
 * page loads. A cached page carries one token for every visitor; each token is
 * accepted once, so each visitor needs their own. Nothing the visitor does
 * later fetches a token again, so the time trap's clock is never restarted.
 * Without JavaScript, or if the request fails, the page's own token stays.
 */
( function () {
	'use strict';
	var fields = document.querySelectorAll( 'input[name^="bfas_tk_"]' );
	if ( ! fields.length || ! window.fetch || ! window.bfasTimeTrap ) {
		return;
	}
	var requests = {};
	Array.prototype.forEach.call( fields, function ( field ) {
		var form = field.name.slice( 'bfas_tk_'.length );
		if ( ! /^[a-z0-9_]{1,40}$/.test( form ) ) {
			return;
		}
		if ( ! requests[ form ] ) {
			var url = window.bfasTimeTrap.url;
			requests[ form ] = fetch( url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'form=' + encodeURIComponent( form ), {
				credentials: 'omit',
				cache: 'no-store'
			} ).then( function ( response ) {
				return response.ok ? response.json() : null;
			} ).catch( function () {
				return null;
			} );
		}
		requests[ form ].then( function ( data ) {
			if ( data && typeof data.token === 'string' && data.token !== '' ) {
				field.value = data.token;
			}
		} );
	} );
}() );
