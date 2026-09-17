/**
 * TranslateRocket — collects text that JavaScript adds after the page has loaded.
 *
 * Loaded only for administrators, only on source-language pages. Cookie and consent
 * banners, pop-ups and AJAX results are injected in the browser, so the server-side
 * engine never sees them and they never reach the translation screens. This watches
 * the page for such additions and files their text with the page, once per string.
 * Nothing is translated here and nothing is changed on the page.
 */
( function () {
	'use strict';

	var cfg = window.trrocketDynCollect;
	if ( ! cfg || ! cfg.ajax || ! window.MutationObserver || ! window.fetch ) {
		return;
	}
	// Inside a page builder's editing frame: everything added there is the builder's
	// own interface. The server already stays out of it; this covers builders it
	// does not recognise by name.
	try {
		if ( window.self !== window.top && /[?&](elementor-preview|fl_builder|et_fb|bricks|ct_builder|oxygen_iframe|breakdance_iframe|brizy-edit-iframe|vc_editable|vcv-editable|tve|so_live_editor|customize_changeset_uuid)=/.test( window.location.search ) ) {
			return;
		}
	} catch ( e ) {
		return;
	}

	var SKIP_TAGS  = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEXTAREA: 1, CODE: 1, PRE: 1, TEMPLATE: 1, SVG: 1 };
	// Administrator tooling (debug panels, page-builder helpers): never page content.
	var TOOLS      = ( cfg.tools || [] ).map( function ( p ) { return String( p ).toLowerCase(); } );

	function isTool( el ) {
		var id  = ( el.id || '' ).toLowerCase();
		var cls = ( typeof el.className === 'string' ? el.className : '' ).toLowerCase().split( /\s+/ );
		var i, j;
		for ( i = 0; i < TOOLS.length; i++ ) {
			if ( id.indexOf( TOOLS[ i ] ) === 0 ) {
				return true;
			}
			for ( j = 0; j < cls.length; j++ ) {
				if ( cls[ j ].indexOf( TOOLS[ i ] ) === 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	// Rendered on screen: a hidden dialog, a template or a collapsed helper is not
	// something the visitor reads. Checked when the page has settled (see flush).
	function visible( el ) {
		if ( ! el || el.nodeType !== 1 ) {
			return true;
		}
		return el.getClientRects().length > 0;
	}
	var ATTRS      = [ 'alt', 'title', 'placeholder', 'aria-label' ];
	var HAS_LETTER = /\p{L}/u;
	var LIMIT      = 600;  // strings per page view, whatever happens on the page
	var seen       = {};
	var queue      = [];
	var sent       = 0;
	var timer      = null;

	// Same boundaries as the translation observer: never page chrome, never our own UI.
	function skip( node ) {
		var el = node.nodeType === 3 ? node.parentNode : node;
		while ( el && el.nodeType === 1 ) {
			if ( SKIP_TAGS[ el.tagName ] || SKIP_TAGS[ String( el.tagName ).toUpperCase() ] ) {
				return true;
			}
			if ( el.id === 'wpadminbar' || el.id === 'trrocket-ve-bar' || el.id === 'trrocket-ve-bulkpanel' || el.id === 'trrocket-ve-vispanel' || el.id === 'trrocket-ve-seopanel' ) {
				return true;
			}
			if ( el.classList && ( el.classList.contains( 'trrocket-ve-pop' ) || el.classList.contains( 'trrocket-switcher' ) ) ) {
				return true;
			}
			if ( isTool( el ) ) {
				return true;
			}
			if ( el.getAttribute && el.getAttribute( 'translate' ) === 'no' ) {
				return true;
			}
			if ( el.isContentEditable ) {
				return true;
			}
			el = el.parentNode;
		}
		return false;
	}

	function add( text, attr, el ) {
		var t = ( text || '' ).replace( /\s+/g, ' ' ).trim();
		if ( ! t || t.length > 2000 || ! HAS_LETTER.test( t ) ) {
			return;
		}
		var k = attr + '|' + t;
		if ( seen[ k ] ) {
			return;
		}
		seen[ k ] = 1;
		queue.push( [ t, attr, el ] );
		schedule();
	}

	function walk( node ) {
		if ( ! node || skip( node ) ) {
			return;
		}
		if ( node.nodeType === 3 ) {
			add( node.nodeValue, '', node.parentNode );
			return;
		}
		if ( node.nodeType !== 1 ) {
			return;
		}
		var i, j;
		for ( i = 0; i < ATTRS.length; i++ ) {
			if ( node.hasAttribute( ATTRS[ i ] ) ) {
				add( node.getAttribute( ATTRS[ i ] ), ATTRS[ i ], node );
			}
		}
		var walker = document.createTreeWalker( node, NodeFilter.SHOW_TEXT, null );
		var tn;
		while ( ( tn = walker.nextNode() ) ) {
			if ( ! skip( tn ) ) {
				add( tn.nodeValue, '', tn.parentNode );
			}
		}
		var els = node.querySelectorAll( '[' + ATTRS.join( '],[' ) + ']' );
		for ( i = 0; i < els.length; i++ ) {
			if ( skip( els[ i ] ) ) {
				continue;
			}
			for ( j = 0; j < ATTRS.length; j++ ) {
				if ( els[ i ].hasAttribute( ATTRS[ j ] ) ) {
					add( els[ i ].getAttribute( ATTRS[ j ] ), ATTRS[ j ], els[ i ] );
				}
			}
		}
	}

	function schedule() {
		if ( ! timer ) {
			// Banners often build themselves in several steps: wait for them to settle.
			timer = window.setTimeout( flush, 1500 );
		}
	}

	function flush() {
		timer = null;
		if ( ! queue.length || sent >= LIMIT ) {
			queue = [];
			return;
		}
		// Only what is on screen now; what is still hidden is forgotten, and comes back
		// through the observer if it is ever shown.
		var batch = [];
		var kept  = [];
		while ( queue.length && batch.length < Math.min( 200, LIMIT - sent ) ) {
			var item = queue.shift();
			if ( item[ 2 ] && ! visible( item[ 2 ] ) ) {
				delete seen[ item[ 1 ] + '|' + item[ 0 ] ];
				continue;
			}
			batch.push( [ item[ 0 ], item[ 1 ] ] );
		}
		queue = kept.concat( queue );
		if ( ! batch.length ) {
			return;
		}
		sent += batch.length;
		var body = new window.URLSearchParams();
		body.append( 'action', 'trrocket_dyn_collect' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'page', cfg.page );
		body.append( 'sig', cfg.sig );
		body.append( 'title', cfg.title || '' );
		body.append( 'items', JSON.stringify( batch ) );
		window.fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).catch( function () {} );
		if ( queue.length ) {
			schedule();
		}
	}

	// Watching starts at once, from the head, not at DOMContentLoaded: consent plugins
	// (CookieYes among them) build their banner in their own DOMContentLoaded listener,
	// which may run before ours. While the document is still loading, what arrives is
	// the page itself being parsed - the engine has already read that on the server.
	var observer = new window.MutationObserver( function ( records ) {
		if ( document.readyState === 'loading' ) {
			return;
		}
		var i, a;
		for ( i = 0; i < records.length; i++ ) {
			for ( a = 0; a < records[ i ].addedNodes.length; a++ ) {
				walk( records[ i ].addedNodes[ a ] );
			}
		}
	} );
	// Only additions: a text node rewritten in place is usually a counter or a
	// timer, not a sentence to translate.
	observer.observe( document.documentElement, { childList: true, subtree: true } );
}() );
