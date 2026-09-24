/* TranslateRocket — custom dropdown switcher (open/close). */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var toggle = ( e.target && e.target.closest ) ? e.target.closest( '.trrocket-dd-toggle' ) : null;

		Array.prototype.forEach.call( document.querySelectorAll( '.trrocket-dd' ), function ( dd ) {
			if ( toggle && dd.contains( toggle ) ) {
				var open = dd.classList.toggle( 'is-open' );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} else {
				dd.classList.remove( 'is-open' );
				var t = dd.querySelector( '.trrocket-dd-toggle' );
				if ( t ) {
					t.setAttribute( 'aria-expanded', 'false' );
				}
			}
		} );
	} );

	document.addEventListener( 'keyup', function ( e ) {
		if ( 'Escape' === e.key ) {
			Array.prototype.forEach.call( document.querySelectorAll( '.trrocket-dd.is-open' ), function ( dd ) {
				dd.classList.remove( 'is-open' );
			} );
		}
	} );

	/* Width matching is done purely in CSS now (the toggle, the overlay menu and a
	   hidden sizer share one grid column), so no JavaScript measuring is needed. */
}() );

/* Scrollable switcher (type="scroll"): both arrows stay in place and just disable at each
   edge (no flicker). Hover (or hold) an arrow to scroll continuously; leaving it stops. */
( function () {
	function initScroll( root ) {
		var prev = root.querySelector( '.trrocket-scroll-prev' ),
			next = root.querySelector( '.trrocket-scroll-next' ),
			vp   = root.querySelector( '.trrocket-scroll-viewport' );
		if ( ! vp ) { return; }
		var raf = null, dir = 0;
		function maxLeft() { return vp.scrollWidth - vp.clientWidth - 1; }
		function atStart() { return vp.scrollLeft <= 1; }
		function atEnd() { return vp.scrollLeft >= maxLeft(); }
		function refresh() {
			if ( prev ) { prev.disabled = atStart(); }
			if ( next ) { next.disabled = ( maxLeft() <= 0 ) || atEnd(); }
		}
		function stop() { dir = 0; if ( raf ) { window.cancelAnimationFrame( raf ); raf = null; } }
		function tick() {
			if ( ! dir ) { raf = null; return; }
			vp.scrollLeft += dir * 6;
			refresh();
			if ( ( dir < 0 && atStart() ) || ( dir > 0 && atEnd() ) ) { stop(); return; }
			raf = window.requestAnimationFrame( tick );
		}
		function start( d ) { dir = d; if ( ! raf ) { raf = window.requestAnimationFrame( tick ); } }
		function bind( btn, d ) {
			if ( ! btn ) { return; }
			btn.addEventListener( 'mouseenter', function () { if ( ! btn.disabled ) { start( d ); } } );
			btn.addEventListener( 'mouseleave', stop );
			btn.addEventListener( 'click', function () { if ( ! btn.disabled ) { vp.scrollBy( { left: d * Math.max( 90, vp.clientWidth * 0.8 ), behavior: 'smooth' } ); } } );
			btn.addEventListener( 'touchstart', function () { if ( ! btn.disabled ) { start( d ); } }, { passive: true } );
			btn.addEventListener( 'touchend', stop );
			btn.addEventListener( 'touchcancel', stop );
		}
		bind( prev, -1 );
		bind( next, 1 );
		vp.addEventListener( 'scroll', refresh, { passive: true } );
		window.addEventListener( 'resize', refresh );
		/* Start with the current language centred in the strip. scrollLeft clamps
		   itself, so the first language ends up left-aligned and the last one
		   right-aligned — always as close to the middle as physically possible. */
		function center() {
			var cur = vp.querySelector( '.trrocket-current' );
			if ( cur && maxLeft() > 0 ) {
				/* Rect-based: offsetLeft would be measured from the floating box
				   (arrow + padding included), landing ~40px short of the middle. */
				var cr = cur.getBoundingClientRect(), vr = vp.getBoundingClientRect();
				var sb = vp.style.scrollBehavior;
				vp.style.scrollBehavior = 'auto'; /* jump, don't animate, on load */
				vp.scrollLeft += ( cr.left - vr.left ) - ( vp.clientWidth - cr.width ) / 2;
				vp.style.scrollBehavior = sb;
			}
			refresh();
		}
		window.setTimeout( center, 60 );
		center();
		/* Flags, fonts and late styles can still shift the strip's metrics after the
		   first pass: re-centre once everything has actually loaded. */
		if ( 'complete' === document.readyState ) {
			window.setTimeout( center, 250 );
		} else {
			window.addEventListener( 'load', function () { window.setTimeout( center, 50 ); } );
		}
	}
	function run() { Array.prototype.forEach.call( document.querySelectorAll( '.trrocket-scroll' ), initScroll ); }
	if ( 'loading' !== document.readyState ) { run(); } else { document.addEventListener( 'DOMContentLoaded', run ); }
}() );

/* Floating switcher and bottom banners (cookie consent, chat bars): the switcher sits above
   them in z-index so it is never hidden (1.5.2), but then it covered their buttons — on the
   test site it hid the «OK» of the cookie banner, so a visitor could not accept (24/9/2026).
   While something fixed hugs the bottom edge under the switcher, the switcher moves up above
   it; when the banner goes away, it comes back down. Only for a bottom-anchored switcher. */
( function () {
	'use strict';
	function start() {
		var box = document.querySelector( '.trrocket-floating' );
		if ( ! box || ! window.document.elementsFromPoint ) {
			return;
		}
		var cs = window.getComputedStyle( box );
		if ( 'auto' === cs.bottom || '' === cs.bottom ) {
			return;
		}
		var base = parseFloat( cs.bottom ) || 0;
		function fixedAncestor( el ) {
			for ( var n = el; n && n !== document.body && n !== document.documentElement; n = n.parentElement ) {
				var p = window.getComputedStyle( n ).position;
				if ( 'fixed' === p || 'sticky' === p ) {
					return n;
				}
			}
			return null;
		}
		function obstacle() {
			var H = window.innerHeight;
			var r = box.getBoundingClientRect();
			var top = H;
			[ r.left + 4, ( r.left + r.right ) / 2, r.right - 4 ].forEach( function ( x ) {
				document.elementsFromPoint( Math.max( 0, x ), H - 3 ).forEach( function ( el ) {
					if ( box.contains( el ) ) {
						return;
					}
					var f = fixedAncestor( el );
					if ( ! f || box.contains( f ) ) {
						return;
					}
					var fr = f.getBoundingClientRect();
					// A bar along the bottom edge, not a full-screen overlay.
					if ( fr.bottom >= H - 6 && fr.height > 0 && fr.height < H * 0.6 ) {
						top = Math.min( top, fr.top );
					}
				} );
			} );
			return H - top;
		}
		var last = -1;
		function update() {
			var h = obstacle();
			var want = h > 0 ? Math.max( base, h + 8 ) : base;
			if ( want !== last ) {
				box.style.setProperty( 'bottom', want + 'px', 'important' );
				last = want;
			}
		}
		update();
		// Banners appear late (after consent scripts load) and disappear on a click: look again
		// now and then, and at every resize. elementsFromPoint on three points is cheap.
		window.setInterval( function () {
			if ( ! document.hidden ) {
				update();
			}
		}, 700 );
		window.addEventListener( 'resize', update );
	}
	if ( 'loading' !== document.readyState ) { start(); } else { document.addEventListener( 'DOMContentLoaded', start ); }
}() );
