/**
 * CF7 editor panel: show the "mbuzz name" input only for roles that carry one
 * (trait / property), and the "—" placeholder otherwise. The keyed roles ride
 * on each select's data-keyed-roles attribute, so no PHP→JS bridge is needed.
 *
 * Lives here rather than inline: CF7 6.1+ routes editor panel output through
 * WPCF7_HTMLFormatter::print(), which runs wp_kses() with the admin allowlist.
 * That list has no `script` element, so an inline <script> loses its tags and
 * its body renders as visible page text.
 */
( function () {
	function sync( select ) {
		var input = document.getElementById( select.getAttribute( 'data-key-target' ) );
		if ( ! input ) {
			return;
		}
		var na = input.parentNode.querySelector( '.mbuzz-key-na' );
		var keyed = [];
		try {
			keyed = JSON.parse( select.getAttribute( 'data-keyed-roles' ) || '[]' );
		} catch ( e ) {}
		var used = keyed.indexOf( select.value ) !== -1;
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
