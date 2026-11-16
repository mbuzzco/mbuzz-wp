/**
 * mbuzz click-event capture.
 *
 * Records a named event when a visitor clicks an element the site owner chose
 * (settings page, `event_name = css selector`, one per line). The plugin ships
 * with no rules. Reports through window.mbuzz.captureLead (mbuzz-capture.js),
 * so it gets the same first-party endpoint, consent gate and cookie handling.
 *
 * Carries only the matched selector and the page path, and reads nothing from
 * the element. Never throws into the host page.
 */
( function () {
	'use strict';

	var CONFIG_GLOBAL = 'mbuzzClickEvents';
	var API_GLOBAL = 'mbuzz';
	var CAPTURE_METHOD = 'captureLead';
	var CLICK = 'click';
	var CAPTURE_PHASE = true;
	// One interaction can be several clicks: a host page's own script may forward
	// the click from the visible element to a hidden one. Clicks for the same
	// event this close together are one interaction.
	var SAME_INTERACTION_MS = 1000;

	var cfg = window[ CONFIG_GLOBAL ] || {};
	var rules = Array.isArray( cfg.rules ) ? cfg.rules : [];
	var lastReportedAt = {};

	// composedPath() sees through an open shadow root, where a click is
	// retargeted to the host element.
	function origins( event ) {
		var path = typeof event.composedPath === 'function' ? event.composedPath() : [];
		return path.length ? path : [ event.target ];
	}

	function matches( node, selector ) {
		try {
			return !! node && typeof node.matches === 'function' && node.matches( selector );
		} catch ( e ) {
			return false;
		}
	}

	function ruleFor( event ) {
		var nodes = origins( event );
		for ( var r = 0; r < rules.length; r++ ) {
			for ( var n = 0; n < nodes.length; n++ ) {
				if ( matches( nodes[ n ], rules[ r ].selector ) ) {
					return rules[ r ];
				}
			}
		}
		return null;
	}

	function alreadyReported( name ) {
		var now = Date.now();
		if ( now - ( lastReportedAt[ name ] || 0 ) < SAME_INTERACTION_MS ) {
			return true;
		}
		lastReportedAt[ name ] = now;
		return false;
	}

	function onClick( event ) {
		try {
			var rule = ruleFor( event );
			var api = window[ API_GLOBAL ];
			if ( ! rule || ! api || typeof api[ CAPTURE_METHOD ] !== 'function' || alreadyReported( rule.event ) ) {
				return;
			}
			api[ CAPTURE_METHOD ]( {
				type: rule.event,
				properties: { selector: rule.selector, page_path: window.location.pathname }
			} );
		} catch ( e ) {}
	}

	if ( rules.length ) {
		document.addEventListener( CLICK, onClick, CAPTURE_PHASE );
	}
} )();
