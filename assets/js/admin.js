/**
 * Copy buttons on the Gallop settings screen.
 *
 * A button with data-gallop-copy="<id>" copies the value of the field with that id.
 * The field can always be selected and copied by hand, so nothing depends on this.
 */
( function () {
	'use strict';

	function copy( field ) {
		// The clipboard API only exists on pages served over HTTPS, and an admin
		// screen is not always one.
		if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( field.value );
		}

		return new Promise( function ( resolve, reject ) {
			field.focus();
			field.select();
			if ( document.execCommand( 'copy' ) ) {
				resolve();
			} else {
				reject();
			}
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-gallop-copy]' ) : null;
		if ( ! button ) {
			return;
		}

		var field = document.getElementById( button.getAttribute( 'data-gallop-copy' ) );
		if ( ! field ) {
			return;
		}

		event.preventDefault();

		copy( field ).then(
			function () {
				var label = button.textContent;
				button.textContent = button.getAttribute( 'data-gallop-copied' ) || label;
				window.setTimeout( function () {
					button.textContent = label;
				}, 2000 );
			},
			function () {
				// Left selected, for copying by hand.
				field.focus();
				field.select();
			}
		);
	} );
} )();
