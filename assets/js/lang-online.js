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
		avvisa();
	}

	// 15/11/2026 (ticket #16): a language switched from offline to online while sentences are still missing is said here,
	// before saving: how many, and that visitors will read them in the site language. No dialog: one line per language.
	function avvisa() {
		var box = document.querySelector( '.trr-online-warn' );
		if ( ! box ) {
			return;
		}
		while ( box.firstChild ) {
			box.removeChild( box.firstChild );
		}
		var righe = 0;
		grid.querySelectorAll( '.trrocket-lang-item[data-era-spenta="1"]' ).forEach( function ( item ) {
			var off = item.querySelector( '.trr-onoff-in' );
			var pick = item.querySelector( 'input[name="target_languages[]"]' );
			if ( ! off || off.checked || ( pick && ! pick.checked ) ) {
				return;
			}
			var tr = riga( item.getAttribute( 'data-code' ) || '' );
			if ( ! tr ) {
				return;
			}
			// The same numbers as the row underneath («489 / 734»), which the live script keeps current.
			var manca = parseInt( tr.getAttribute( 'data-manca' ) || '0', 10 );
			var totale = tr.getAttribute( 'data-totale' ) || '';
			var n = tr.querySelector( '.trr-auto-n' );
			var m = n ? /(\d[\d.,\s]*)\s*\/\s*(\d[\d.,\s]*)/.exec( n.textContent || '' ) : null;
			if ( m ) {
				var fatte = parseInt( m[1].replace( /\D/g, '' ), 10 );
				var tot = parseInt( m[2].replace( /\D/g, '' ), 10 );
				if ( tot >= fatte ) {
					manca = tot - fatte;
					totale = String( tot );
				}
			}
			if ( manca <= 0 ) {
				return;
			}
			var p = document.createElement( 'p' );
			p.textContent = ( box.getAttribute( 'data-line' ) || '' )
				.replace( '%1$s', tr.getAttribute( 'data-nome' ) || '' )
				.replace( '%2$s', String( manca ) )
				.replace( '%3$s', totale ) + ' ';
			var a = document.createElement( 'a' );
			a.className = 'button button-small button-primary';
			a.href = tr.getAttribute( 'data-vai' ) || '#';
			a.textContent = box.getAttribute( 'data-button' ) || '';
			p.appendChild( a );
			box.appendChild( p );
			righe++;
		} );
		box.hidden = 0 === righe;
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
