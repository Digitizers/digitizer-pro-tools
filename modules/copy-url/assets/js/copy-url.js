/**
 * Copy URL widget: clicking anywhere on the block copies the field's value.
 *
 * navigator.clipboard is the mechanism; the execCommand path is the fallback
 * for the contexts that still need one (plain-HTTP pages among them, where
 * the clipboard API is absent). One delegated listener serves any number of
 * widgets on the page.
 */
( function () {
	'use strict';

	function copyFrom( wrap ) {
		var field = wrap.querySelector( '.dpt-copy-url-field' );
		var btn   = wrap.querySelector( '.dpt-copy-url-btn' );
		if ( ! field || ! btn ) {
			return;
		}

		var done = function () {
			if ( ! btn.hasAttribute( 'data-label' ) ) {
				btn.setAttribute( 'data-label', btn.textContent );
			}
			btn.textContent = btn.getAttribute( 'data-copied' ) || btn.textContent;
			btn.classList.add( 'dpt-copied' );
			window.clearTimeout( btn._dptCopyTimer );
			btn._dptCopyTimer = window.setTimeout( function () {
				btn.textContent = btn.getAttribute( 'data-label' );
				btn.classList.remove( 'dpt-copied' );
			}, 2000 );
		};

		var fallback = function () {
			field.focus();
			field.select();
			field.setSelectionRange( 0, 99999 );
			try {
				if ( document.execCommand( 'copy' ) ) {
					done();
				}
			} catch ( e ) {
				// Nothing left to try; the field is selected for a manual copy.
			}
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( field.value ).then( done, fallback );
		} else {
			fallback();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var wrap = e.target && e.target.closest ? e.target.closest( '.dpt-copy-url' ) : null;
		if ( wrap ) {
			copyFrom( wrap );
		}
	} );
} )();
