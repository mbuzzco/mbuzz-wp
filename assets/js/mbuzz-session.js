/**
 * Session bootstrap for pages served from a full-page cache.
 *
 * A cached page is returned without running PHP, so nothing establishes the
 * visitor and every later event or conversion is dropped for having no one to
 * attribute it to. This posts where the visitor is to a first-party endpoint the
 * cache never serves, and the SERVER sets the cookie on that response.
 *
 * The id is never created, read, or held here. It stays HttpOnly and server-set,
 * which is what keeps its full multi-year lifetime under Safari's ITP — cookies
 * written by document.cookie are capped at 7 days, and 24 hours after an ad
 * click. A JS-owned id would have been worse than the bug it fixed.
 *
 * Fire-and-forget: never blocks render, never throws into the host page.
 */
( function () {
	'use strict';

	var CONFIG_GLOBAL = 'mbuzzSession';
	var ENDPOINT_KEY = 'endpoint';
	var CONTENT_TYPE = 'application/json';

	var cfg = window[ CONFIG_GLOBAL ] || {};

	function send() {
		var endpoint = cfg[ ENDPOINT_KEY ];
		if ( ! endpoint ) {
			return;
		}

		var body = JSON.stringify( {
			url: window.location.href,
			referrer: document.referrer || ''
		} );

		try {
			fetch( endpoint, {
				method: 'POST',
				headers: { 'Content-Type': CONTENT_TYPE },
				body: body,
				credentials: 'same-origin', // send + accept the first-party cookie
				keepalive: true
			} )[ 'catch' ]( function () {} );
		} catch ( e ) {}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', send );
	} else {
		send();
	}
} )();
