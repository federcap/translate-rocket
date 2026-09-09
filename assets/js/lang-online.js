/**
 * The on/off switch beside every language on the Languages page.
 *
 * Green: visitors see the language. Grey: only whoever can preview sees it,
 * while it is being translated. Nothing is saved here — the switch is a plain
 * checkbox (offline_languages[]) that travels with the form. This file only
 * keeps what you see honest: the switch appears when a language is enabled,
 * and the progress table underneath follows along without a page reload.
 */
( function () {
	'use strict';

	var grid = document.querySelector( '.trr-lang-grid' );
	if ( ! grid ) {
		return;
	}

	function riga( code ) {
		return document.querySelector( '.trr-lang-state-table tr[data-code="' + code + '"]' );
	}

	// Keeps the read-only pill in the progress table in step with the switch.
	function aggiorna( item ) {
		var off  = item.querySelector( '.trr-onoff-in' );
		var lab  = item.querySelector( '.trr-onoff' );
		var spenta = !! ( off && off.checked );
		item.classList.toggle( 'is-offline', spenta );
		if ( lab ) {
			lab.title = spenta ? ( lab.getAttribute( 'data-off' ) || '' ) : ( lab.getAttribute( 'data-on' ) || '' );
		}
		var tr = riga( item.getAttribute( 'data-code' ) || '' );
		if ( tr ) {
			tr.classList.toggle( 'is-offline', spenta );
		}
	}

	grid.addEventListener( 'change', function ( e ) {
		var input = e.target;
		if ( ! input || 'checkbox' !== input.type ) {
			return;
		}
		var item = input.closest( '.trrocket-lang-item' );
		if ( ! item ) {
			return;
		}

		if ( input.classList.contains( 'trr-onoff-in' ) ) {
			aggiorna( item );
			return;
		}

		// The language itself was ticked or unticked.
		var scelta = input.checked;
		item.classList.toggle( 'is-chosen', scelta );
		var off = item.querySelector( '.trr-onoff-in' );
		if ( ! off ) {
			return;
		}
		if ( scelta ) {
			// A language added now starts OFFLINE. Otherwise the moment you save,
			// a language with nothing translated in it is already public — in the
			// switcher, in the sitemap, in the hreflang tags. A language that was
			// already on the site when the page loaded keeps the state it had.
			off.checked = item.hasAttribute( 'data-era-scelta' ) ? ( '1' === item.getAttribute( 'data-era-spenta' ) ) : true;
		} else {
			// A language you just removed cannot be "offline" either.
			off.checked = false;
		}
		aggiorna( item );
	} );

	// Clicking the switch must not toggle anything else in the row.
	grid.addEventListener( 'click', function ( e ) {
		if ( e.target.closest && e.target.closest( '.trr-onoff' ) ) {
			e.stopPropagation();
		}
	} );
}() );
