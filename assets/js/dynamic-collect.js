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
	var pending    = {}; // queued, not sent yet
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
			// The «do not translate» marks of other tools (Google's class, TranslatePress, Weglot),
			// as on the server; on <html>/<body> they only silence the browser's popup.
			if ( el.tagName !== 'HTML' && el.tagName !== 'BODY' && el.getAttribute && ( ( el.classList && ( el.classList.contains( 'notranslate' ) || el.classList.contains( 'skiptranslate' ) ) ) || el.hasAttribute( 'data-no-translation' ) || el.hasAttribute( 'data-wg-notranslate' ) || el.hasAttribute( 'data-notranslate' ) ) ) {
				return true;
			}
			if ( el.isContentEditable ) {
				return true;
			}
			el = el.parentNode;
			// Out of a shadow DOM: go on from the element that hosts it (its marks count too).
			if ( el && el.nodeType === 11 && el.host ) {
				el = el.host;
			}
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
			// The same words met again while the first copy still waits hidden: the visible
			// copy counts (7/10/2026: LatePoint has «Available Services» twice - hidden in a
			// progress bar first, then as the visible heading - and the heading was dropped).
			if ( pending[ k ] && el && visible( el ) && ! visible( pending[ k ][ 2 ] ) ) {
				pending[ k ][ 2 ] = el;
			}
			return;
		}
		seen[ k ] = 1;
		pending[ k ] = [ t, attr, el ];
		queue.push( pending[ k ] );
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
		if ( node.nodeType !== 1 && node.nodeType !== 11 ) {
			return;
		}
		var i, j;
		for ( i = 0; i < ATTRS.length; i++ ) {
			if ( node.hasAttribute && node.hasAttribute( ATTRS[ i ] ) ) {
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
		adopt( node );
	}

	// Open shadow DOMs (consent banners built as web components), as in the translation observer.
	var roots = [];
	function adopt( node ) {
		if ( ! node || ! node.querySelectorAll ) {
			return;
		}
		var all  = node.querySelectorAll( '*' );
		var list = node.nodeType === 1 ? [ node ] : [];
		var i;
		for ( i = 0; i < all.length; i++ ) {
			list.push( all[ i ] );
		}
		for ( i = 0; i < list.length; i++ ) {
			var sr = list[ i ].shadowRoot;
			if ( sr && roots.indexOf( sr ) < 0 && ! skip( list[ i ] ) ) {
				roots.push( sr );
				observer.observe( sr, { childList: true, subtree: true, characterData: true } );
				walk( sr );
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
		// Only what is on screen. What is still hidden waits and is looked at again every
		// 2 s for a minute (7/10/2026: LatePoint's booking window, like many pop-ups, is put
		// in the page hidden and then shown by a class change - no node is added, so the
		// observer never saw it again and the window could never be translated). What never
		// shows up in that minute is forgotten.
		var batch = [];
		var kept  = [];
		while ( queue.length && batch.length < Math.min( 200, LIMIT - sent ) ) {
			var item = queue.shift();
			if ( item[ 2 ] && ! visible( item[ 2 ] ) ) {
				item[ 3 ] = ( item[ 3 ] || 0 ) + 1;
				if ( item[ 3 ] < 30 ) {
					kept.push( item );
				} else {
					delete seen[ item[ 1 ] + '|' + item[ 0 ] ];
					delete pending[ item[ 1 ] + '|' + item[ 0 ] ];
				}
				continue;
			}
			delete pending[ item[ 1 ] + '|' + item[ 0 ] ];
			batch.push( [ item[ 0 ], item[ 1 ] ] );
		}
		queue = kept.concat( queue );
		if ( queue.length && ! timer ) {
			timer = window.setTimeout( flush, 2000 );
		}
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
			if ( records[ i ].type === 'characterData' ) {
				// Text rewritten in place. Consent plugins print a placeholder in the page
				// ("{title}") and fill it in from their own settings once the script runs:
				// watching additions only, we never saw those words at all.
				walk( records[ i ].target );
				continue;
			}
			for ( a = 0; a < records[ i ].addedNodes.length; a++ ) {
				walk( records[ i ].addedNodes[ a ] );
			}
		}
	} );
	observer.observe( document.documentElement, { childList: true, subtree: true, characterData: true } );
	[ 300, 1500, 4000, 8000 ].forEach( function ( ms ) {
		window.setTimeout( function () {
			if ( document.body ) {
				adopt( document.body );
			}
		}, ms );
	} );
}() );
