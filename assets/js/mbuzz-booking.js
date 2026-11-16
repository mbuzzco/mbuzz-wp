/**
 * mbuzz booking-trigger capture.
 *
 * Records one event when a visitor clicks a button that opens an online
 * booking widget. Reports through window.mbuzz.captureLead (mbuzz-capture.js),
 * so it gets the same first-party endpoint, consent gate and cookie handling.
 *
 * Carries only which trigger was used and the page path. It never reads
 * anything from inside the widget. Never throws into the host page.
 */
( function () {
	'use strict';

	var CONFIG_GLOBAL = 'mbuzzBooking';
	var API_GLOBAL = 'mbuzz';
	var CAPTURE_METHOD = 'captureLead';
	var CLICK = 'click';
	var CAPTURE_PHASE = true;
	// One opening can be several clicks: a host page's own script may forward the
	// click from the visible button to a hidden one. Clicks this close together
	// are one opening.
	var SAME_OPENING_MS = 1000;
	var lastReportedAt = 0;

	var cfg = window[ CONFIG_GLOBAL ] || {};
	var selectors = Array.isArray( cfg.selectors ) ? cfg.selectors : [];

	// composedPath() sees through an open shadow root, where a click is
	// retargeted to the host element.
	function origins( event ) {
		var path = typeof event.composedPath === 'function' ? event.composedPath() : [];
		return path.length ? path : [ event.target ];
	}

	function matchedSelector( node ) {
		if ( ! node || typeof node.matches !== 'function' ) {
			return null;
		}
		for ( var i = 0; i < selectors.length; i++ ) {
			try {
				if ( node.matches( selectors[ i ] ) ) {
					return selectors[ i ];
				}
			} catch ( e ) {}
		}
		return null;
	}

	function triggerFor( event ) {
		var nodes = origins( event );
		for ( var i = 0; i < nodes.length; i++ ) {
			var hit = matchedSelector( nodes[ i ] );
			if ( hit ) {
				return hit;
			}
		}
		return null;
	}

	function onClick( event ) {
		try {
			var trigger = triggerFor( event );
			var api = window[ API_GLOBAL ];
			if ( ! trigger || ! api || typeof api[ CAPTURE_METHOD ] !== 'function' ) {
				return;
			}
			var now = Date.now();
			if ( now - lastReportedAt < SAME_OPENING_MS ) {
				return;
			}
			lastReportedAt = now;
			api[ CAPTURE_METHOD ]( {
				type: cfg.event,
				properties: { trigger: trigger, page_path: window.location.pathname }
			} );
		} catch ( e ) {}
	}

	if ( cfg.event && selectors.length ) {
		document.addEventListener( CLICK, onClick, CAPTURE_PHASE );
	}
} )();
