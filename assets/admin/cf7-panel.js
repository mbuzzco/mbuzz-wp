/**
 * CF7 editor panel: show the "mbuzz name" input only for roles that carry one
 * (trait / property), and the "—" placeholder otherwise.
 *
 * Lives here rather than inline: CF7 6.1+ routes editor panel output through
 * WPCF7_HTMLFormatter::print(), which runs wp_kses() with the admin allowlist.
 * That list has no `script` element, so an inline <script> loses its tags and
 * its body renders as visible page text.
 *
 * The server already renders the correct state (Cf7PanelPresenter::key_used),
 * so this script only has to keep it correct as the role changes. If it cannot
 * read the keyed-role list it does NOTHING and leaves the server's markup
 * alone — hiding a populated name box would look like data loss.
 */
( function () {
	/** Fallback if the attribute is unreadable; mirrors Roles::KEYED. */
	var KEYED_FALLBACK = [ 'trait', 'property' ];

	function keyedRoles( select ) {
		var raw = select.getAttribute( 'data-keyed-roles' );
		if ( ! raw ) {
			return KEYED_FALLBACK;
		}
		try {
			var parsed = JSON.parse( raw );
			if ( Array.isArray( parsed ) && parsed.length ) {
				return parsed;
			}
		} catch ( e ) {}

		return KEYED_FALLBACK;
	}

	function sync( select ) {
		var input = document.getElementById( select.getAttribute( 'data-key-target' ) );
		if ( ! input ) {
			return;
		}
		var cell = input.closest ? input.closest( 'td' ) : input.parentNode;
		var na = cell ? cell.querySelector( '.mbuzz-key-na' ) : null;
		var used = keyedRoles( select ).indexOf( select.value ) !== -1;

		input.style.display = used ? '' : 'none';
		if ( na ) {
			na.style.display = used ? 'none' : '';
		}
	}

	function init() {
		var selects = document.querySelectorAll( '.mbuzz-role-select' );
		for ( var i = 0; i < selects.length; i++ ) {
			selects[ i ].addEventListener( 'change', function ( e ) {
				sync( e.target );
			} );
			sync( selects[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
