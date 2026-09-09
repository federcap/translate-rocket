/* TranslateRocket — click-to-open info popovers. Click an "i" icon to open its
   explanation; it stays open (so you can read it without holding the mouse over),
   and closes on a second click, a click elsewhere, or Esc. */
( function () {
	'use strict';
	var open = null;

	function close() {
		if ( open ) {
			if ( open.parentNode ) {
				open.parentNode.removeChild( open );
			}
			open = null;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.trr-info' ) : null;
		if ( btn ) {
			e.preventDefault();
			e.stopPropagation();
			var same = open && open._for === btn;
			close();
			if ( same ) {
				return; // second click on the same icon -> just close.
			}
			var pop = document.createElement( 'div' );
			pop.className = 'trr-info-pop';
			pop.textContent = btn.getAttribute( 'data-info' ) || '';
			pop._for = btn;
			document.body.appendChild( pop );
			var r    = btn.getBoundingClientRect();
			var maxL = window.scrollX + document.documentElement.clientWidth - pop.offsetWidth - 12;
			pop.style.top  = ( r.bottom + window.scrollY + 6 ) + 'px';
			pop.style.left = Math.max( window.scrollX + 8, Math.min( r.left + window.scrollX, maxL ) ) + 'px';
			open = pop;
			return;
		}
		if ( open && ! ( e.target.closest && e.target.closest( '.trr-info-pop' ) ) ) {
			close();
		}
	}, true );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			close();
		}
	} );
	window.addEventListener( 'resize', close );
}() );
