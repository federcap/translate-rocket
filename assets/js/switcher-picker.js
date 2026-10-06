/* TranslateRocket — choose the switcher's spot on your own page (6/10/2026).
 *
 * Runs only on a page opened from the Switcher screen with ?trrocket_pick=1, for an administrator.
 * The zones the theme usually has (header, menu, footer, sidebar) are outlined; hovering outlines
 * anything; a click picks it, «At the start» / «At the end» shows the real switcher right there,
 * «Use this spot» sends the choice back to the Switcher screen (the frame's parent, or the tab that
 * opened this one). Nothing is saved from here.
 */
( function () {
	'use strict';
	var L = window.trrocketPicker || {};
	var tpl = document.getElementById( 'trrocket-spot-tpl' );
	var root = document.documentElement;
	var picked = null;
	var where = 'end';
	var sample = null;

	var css = document.createElement( 'style' );
	css.textContent = [
		'.trrocket-pick-hover{outline:2px dashed #4f46e5!important;outline-offset:2px;cursor:crosshair!important}',
		'.trrocket-pick-zone{outline:2px solid rgba(79,70,229,.35)!important;outline-offset:-2px}',
		'.trrocket-pick-chosen{outline:3px solid #4f46e5!important;outline-offset:2px}',
		'.trrocket-pick-tag{position:absolute;z-index:2147483646;background:#4f46e5;color:#fff;font:600 12px/1.6 system-ui,sans-serif;padding:1px 8px;border-radius:0 0 6px 6px;pointer-events:none}',
		'#trrocket-pick-bar{position:fixed;left:50%;transform:translateX(-50%);bottom:14px;z-index:2147483647;background:#111827;color:#fff;font:14px/1.4 system-ui,sans-serif;border-radius:12px;padding:10px 12px;box-shadow:0 10px 30px rgba(0,0,0,.3);display:flex;flex-wrap:wrap;gap:8px;align-items:center;max-width:calc(100vw - 24px);box-sizing:border-box}',
		'#trrocket-pick-bar button{font:600 13px system-ui,sans-serif;border:0;border-radius:8px;padding:7px 12px;cursor:pointer;background:#374151;color:#fff}',
		'#trrocket-pick-bar button.is-on{background:#4f46e5}',
		'#trrocket-pick-bar button.trr-ok{background:#16a34a}',
		'#trrocket-pick-bar button[disabled]{opacity:.45;cursor:default}',
		'#trrocket-pick-bar .trr-what{opacity:.85;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
		'.trrocket-floating,.trrocket-footer-row{display:none!important}'
	].join( '' );
	document.head.appendChild( css );

	/* --- the selector: short, stable, unique ---------------------------------------- */
	function okClass( c ) {
		return /^[a-zA-Z][\w-]{1,40}$/.test( c ) && ! /\d{3,}/.test( c ) && ! /^(is-|has-|js-|wp-container-|trrocket-pick)/.test( c ) && [ 'active', 'current', 'hover', 'focus', 'open', 'sticky', 'scrolled' ].indexOf( c ) === -1;
	}
	function part( el ) {
		if ( el.id && /^[a-zA-Z][\w-]{1,60}$/.test( el.id ) && ! /\d{4,}/.test( el.id ) ) {
			return '#' + el.id;
		}
		var s = el.tagName.toLowerCase();
		var cls = Array.prototype.filter.call( el.classList, okClass ).slice( 0, 2 );
		if ( cls.length ) {
			s += '.' + cls.join( '.' );
		}
		var p = el.parentElement;
		if ( p ) {
			var same = Array.prototype.filter.call( p.children, function ( c ) { return c.tagName === el.tagName; } );
			if ( same.length > 1 && ! cls.length ) {
				s += ':nth-of-type(' + ( same.indexOf( el ) + 1 ) + ')';
			}
		}
		return s;
	}
	function selectorFor( el ) {
		var parts = [];
		var cur = el;
		while ( cur && cur !== document.body && cur !== root ) {
			parts.unshift( part( cur ) );
			var sel = parts.join( ' > ' );
			try {
				if ( '#' === parts[ 0 ].charAt( 0 ) && document.querySelectorAll( sel ).length === 1 ) {
					return sel;
				}
				if ( parts.length >= 2 && document.querySelectorAll( sel ).length === 1 ) {
					return sel;
				}
			} catch ( e ) {}
			cur = cur.parentElement;
		}
		return parts.join( ' > ' );
	}
	function nameOf( el ) {
		var t = el.tagName.toLowerCase();
		var z = el.closest( 'header, [role="banner"], .site-header' ) ? ( L.header || 'Header' ) : el.closest( 'footer, [role="contentinfo"], .site-footer' ) ? ( L.footer || 'Footer' ) : el.closest( 'nav, [role="navigation"]' ) ? ( L.menu || 'Menu' ) : el.closest( 'aside, .sidebar, .widget-area' ) ? ( L.sidebar || 'Sidebar' ) : '';
		return ( z ? z + ' › ' : '' ) + t + ( el.id ? '#' + el.id : ( el.classList[ 0 ] ? '.' + el.classList[ 0 ] : '' ) );
	}

	/* --- zones the theme usually has ------------------------------------------------- */
	var zones = [
		[ 'header, [role="banner"], .site-header, #masthead', L.header || 'Header' ],
		[ 'header nav, [role="banner"] nav, .main-navigation, .wp-block-navigation', L.menu || 'Menu' ],
		[ 'footer, [role="contentinfo"], .site-footer, #colophon', L.footer || 'Footer' ],
		[ 'aside, .widget-area, #secondary', L.sidebar || 'Sidebar' ]
	];
	var tags = [];
	zones.forEach( function ( z ) {
		var el = document.querySelector( z[ 0 ] );
		if ( ! el || ! el.getClientRects().length ) {
			return;
		}
		el.classList.add( 'trrocket-pick-zone' );
		var r = el.getBoundingClientRect();
		var tag = document.createElement( 'div' );
		tag.className = 'trrocket-pick-tag';
		tag.textContent = z[ 1 ];
		tag.style.left = ( r.left + window.scrollX + 6 ) + 'px';
		tag.style.top = ( r.top + window.scrollY ) + 'px';
		document.body.appendChild( tag );
		tags.push( tag );
	} );

	/* --- the bar ------------------------------------------------------------------- */
	var bar = document.createElement( 'div' );
	bar.id = 'trrocket-pick-bar';
	bar.innerHTML = '<span class="trr-what"></span>' +
		'<button type="button" data-w="start"></button><button type="button" data-w="end" class="is-on"></button>' +
		'<button type="button" class="trr-ok" disabled></button><button type="button" class="trr-no"></button>';
	document.body.appendChild( bar );
	var what = bar.querySelector( '.trr-what' );
	var bStart = bar.querySelector( '[data-w="start"]' );
	var bEnd = bar.querySelector( '[data-w="end"]' );
	var bOk = bar.querySelector( '.trr-ok' );
	var bNo = bar.querySelector( '.trr-no' );
	what.textContent = L.hint || 'Click the place where the switcher should go.';
	bStart.textContent = L.start || 'At the start';
	bEnd.textContent = L.end || 'At the end';
	bOk.textContent = L.use || 'Use this spot';
	bNo.textContent = L.cancel || 'Cancel';

	function inUi( el ) {
		return ! el || el === document.body || el === root || bar.contains( el ) || ( sample && sample.contains( el ) );
	}
	function showSample() {
		if ( sample ) {
			sample.remove();
		}
		if ( ! picked || ! tpl ) {
			return;
		}
		sample = document.createElement( 'div' );
		sample.className = 'trrocket-sw trrocket-sw-default trrocket-spot';
		sample.setAttribute( 'translate', 'no' );
		sample.innerHTML = tpl.innerHTML;
		if ( 'start' === where ) {
			picked.insertBefore( sample, picked.firstChild );
		} else {
			picked.appendChild( sample );
		}
	}
	function send( msg ) {
		var to = window.parent !== window ? window.parent : window.opener;
		if ( to ) {
			to.postMessage( msg, window.location.origin );
		}
	}

	document.addEventListener( 'mouseover', function ( e ) {
		var el = e.target;
		if ( inUi( el ) ) {
			return;
		}
		el.classList.add( 'trrocket-pick-hover' );
	}, true );
	document.addEventListener( 'mouseout', function ( e ) {
		if ( e.target.classList ) {
			e.target.classList.remove( 'trrocket-pick-hover' );
		}
	}, true );
	document.addEventListener( 'click', function ( e ) {
		var el = e.target;
		if ( bar.contains( el ) ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		if ( inUi( el ) ) {
			return;
		}
		// a link, an icon, a title or a paragraph is no place for a switcher: the block that holds it
		while ( el.parentElement && /^(A|SPAN|IMG|SVG|PATH|USE|I|STRONG|EM|B|SMALL|P|H1|H2|H3|H4|H5|H6|LABEL|FIGURE|PICTURE)$/i.test( el.tagName ) ) {
			el = el.parentElement;
		}
		if ( picked ) {
			picked.classList.remove( 'trrocket-pick-chosen' );
		}
		picked = el;
		picked.classList.remove( 'trrocket-pick-hover' );
		picked.classList.add( 'trrocket-pick-chosen' );
		what.textContent = nameOf( picked );
		bOk.disabled = false;
		showSample();
	}, true );
	[ bStart, bEnd ].forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			where = b.getAttribute( 'data-w' );
			bStart.classList.toggle( 'is-on', 'start' === where );
			bEnd.classList.toggle( 'is-on', 'end' === where );
			showSample();
		} );
	} );
	bOk.addEventListener( 'click', function () {
		if ( ! picked ) {
			return;
		}
		if ( sample ) {
			sample.remove();
			sample = null;
		}
		picked.classList.remove( 'trrocket-pick-chosen' );
		send( { trrocketSpot: true, selector: selectorFor( picked ), where: where, label: nameOf( picked ) } );
		picked.classList.add( 'trrocket-pick-chosen' );
		showSample();
		what.textContent = L.done || 'Chosen. Save the switcher to keep it.';
	} );
	bNo.addEventListener( 'click', function () {
		send( { trrocketSpot: true, cancel: true } );
	} );
	window.addEventListener( 'scroll', function () {
		tags.forEach( function ( t ) { t.style.display = 'none'; } );
	}, { once: true } );
	window.trrocketPickerSelector = selectorFor; // for the tests
}() );
