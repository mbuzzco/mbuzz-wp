/**
 * mbuzz embedded-form capture helper.
 *
 * The documented, scope-stable client surface for reporting a lead from an
 * embedded / third-party form that WordPress never processes server-side. Call
 * it on genuine success (e.g. a vendor widget's success callback):
 *
 *   window.mbuzz.captureLead({
 *     type: 'tour_booking',
 *     user_id: emailFromTheForm,
 *     traits: { phone: ..., first_name: ... },
 *     properties: { location: 'Downtown', external_lead_id: id }
 *   });
 *
 * The POST goes to a first-party endpoint that resolves the visitor from the
 * HttpOnly _mbuzz_vid cookie server-side, then fires identify + event. Uses
 * sendBeacon (fallback: fetch keepalive) so the call survives a post-submit
 * redirect. Never throws into the host page.
 */
( function () {
	'use strict';

	// Named constants — no inline literals.
	var CONFIG_GLOBAL = 'mbuzzCapture';
	var API_GLOBAL = 'mbuzz';
	var CAPTURE_METHOD = 'captureLead';
	var ENDPOINT_KEY = 'endpoint';
	var HTTP_METHOD = 'POST';
	var CONTENT_TYPE = 'application/json';
	var SAME_ORIGIN = 'same-origin';

	var cfg = window[ CONFIG_GLOBAL ] || {};

	function post( payload ) {
		var endpoint = cfg[ ENDPOINT_KEY ];
		if ( ! endpoint || ! payload || typeof payload !== 'object' ) {
			return false;
		}

		var body = JSON.stringify( payload );

		try {
			if ( navigator && typeof navigator.sendBeacon === 'function' ) {
				var blob = new Blob( [ body ], { type: CONTENT_TYPE } );
				if ( navigator.sendBeacon( endpoint, blob ) ) {
					return true;
				}
			}
		} catch ( e ) {}

		// Fallback: keepalive fetch survives navigation too.
		try {
			fetch( endpoint, {
				method: HTTP_METHOD,
				headers: { 'Content-Type': CONTENT_TYPE },
				body: body,
				keepalive: true,
				credentials: SAME_ORIGIN
			} );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	var api = window[ API_GLOBAL ] || {};
	api[ CAPTURE_METHOD ] = function ( payload ) {
		return post( payload );
	};
	window[ API_GLOBAL ] = api;
} )();
