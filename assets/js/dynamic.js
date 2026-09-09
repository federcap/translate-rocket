/**
 * TranslateRocket — dynamic content translator.
 *
 * Translates text that JavaScript injects into the page AFTER load — cookie/consent
 * banners, popups, AJAX-loaded results — which the server-side engine can't reach.
 * Watches the DOM with a MutationObserver, asks the server for translations of the
 * text it sees (the site's EXISTING translations only; never an AI call), and swaps
 * them in. Results are cached per tab so re-opened widgets translate instantly.
 */
( function () {
	'use strict';

	var cfg = window.trrocketDyn;
	if ( ! cfg || ! cfg.ajax || ! cfg.lang || ! window.MutationObserver ) {
		return;
	}

	var ATTRS      = [ 'alt', 'title', 'placeholder', 'aria-label' ];
	var SKIP_TAGS  = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEXTAREA: 1, CODE: 1, PRE: 1 };
	var HAS_LETTER = /\p{L}/u;

	var cache        = {};   // original -> translation (string) or null (known miss).
	var pendingNodes = [];   // { node, orig } text nodes awaiting a translation.
	var pendingAttrs = [];   // { el, attr, orig } attributes awaiting a translation.
	var toFetch      = {};   // set of unique originals to request next.
	var timer        = null;
	var storeKey     = 'trrocket_dyn_' + cfg.lang;

	// Warm the cache from this tab's session (instant re-translation on revisit).
	try {
		var saved = window.sessionStorage.getItem( storeKey );
		if ( saved ) {
			cache = JSON.parse( saved ) || {};
		}
	} catch ( e ) {}

	function persist() {
		try {
			window.sessionStorage.setItem( storeKey, JSON.stringify( cache ) );
		} catch ( e ) {}
	}

	// Whether a node lives inside something we must never touch.
	function skip( node ) {
		var el = node.nodeType === 3 ? node.parentNode : node;
		while ( el && el.nodeType === 1 ) {
			if ( SKIP_TAGS[ el.tagName ] ) {
				return true;
			}
			if ( el.id === 'wpadminbar' || el.id === 'trrocket-ve-bar' ) {
				return true;
			}
			// The visual editor's own popups/panels are chrome, not page content:
			// translating their source-text preview would rewrite it into the target
			// language (the exact "English → Italian" flip). They also carry
			// translate="no", but match by class/id too so a stripped attribute can
			// never reintroduce the bug.
			if ( el.classList && el.classList.contains( 'trrocket-ve-pop' ) ) {
				return true;
			}
			if ( el.id === 'trrocket-ve-bulkpanel' || el.id === 'trrocket-ve-vispanel' || el.id === 'trrocket-ve-seopanel' ) {
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

	// Trimmed text worth translating (non-empty, contains a letter), else ''.
	function want( text ) {
		var t = ( text || '' ).trim();
		return t !== '' && HAS_LETTER.test( t ) ? t : '';
	}

	function queueText( node ) {
		if ( skip( node ) ) {
			return;
		}
		var orig = want( node.nodeValue );
		if ( ! orig ) {
			return;
		}
		if ( Object.prototype.hasOwnProperty.call( cache, orig ) ) {
			if ( cache[ orig ] ) {
				applyText( node, orig, cache[ orig ] );
			}
			return;
		}
		pendingNodes.push( { node: node, orig: orig } );
		toFetch[ orig ] = true;
		schedule();
	}

	function queueAttr( el, attr ) {
		var orig = want( el.getAttribute( attr ) );
		if ( ! orig ) {
			return;
		}
		if ( Object.prototype.hasOwnProperty.call( cache, orig ) ) {
			if ( cache[ orig ] ) {
				el.setAttribute( attr, cache[ orig ] );
			}
			return;
		}
		pendingAttrs.push( { el: el, attr: attr, orig: orig } );
		toFetch[ orig ] = true;
		schedule();
	}

	// Walk a newly-added subtree for translatable text and attributes.
	function collect( node ) {
		if ( node.nodeType === 3 ) {
			queueText( node );
			return;
		}
		if ( node.nodeType !== 1 || skip( node ) ) {
			return;
		}

		var i;
		for ( i = 0; i < ATTRS.length; i++ ) {
			if ( node.hasAttribute && node.hasAttribute( ATTRS[ i ] ) ) {
				queueAttr( node, ATTRS[ i ] );
			}
		}

		var walker = document.createTreeWalker( node, NodeFilter.SHOW_TEXT, null );
		var tn;
		while ( ( tn = walker.nextNode() ) ) {
			queueText( tn );
		}

		if ( node.querySelectorAll ) {
			var els = node.querySelectorAll( '[' + ATTRS.join( '],[' ) + ']' );
			var j, k;
			for ( j = 0; j < els.length; j++ ) {
				if ( skip( els[ j ] ) ) {
					continue;
				}
				for ( k = 0; k < ATTRS.length; k++ ) {
					if ( els[ j ].hasAttribute( ATTRS[ k ] ) ) {
						queueAttr( els[ j ], ATTRS[ k ] );
					}
				}
			}
		}
	}

	// Swap a text node's source text for its translation, only if it still shows
	// the source (so we never clobber a later re-render).
	function applyText( node, orig, trans ) {
		if ( ! node || ! node.isConnected || ! trans ) {
			return;
		}
		if ( ( node.nodeValue || '' ).trim() !== orig ) {
			return;
		}
		node.nodeValue = node.nodeValue.replace( orig, trans );
	}

	function schedule() {
		if ( timer ) {
			return;
		}
		timer = window.setTimeout( flush, 200 );
	}

	function flush() {
		timer = null;
		var items = Object.keys( toFetch );
		toFetch = {};
		if ( ! items.length ) {
			applyPending();
			return;
		}

		var batch = items.slice( 0, 200 );
		var rest  = items.slice( 200 );
		var r;
		for ( r = 0; r < rest.length; r++ ) {
			toFetch[ rest[ r ] ] = true;
		}

		var body = new window.URLSearchParams();
		body.append( 'action', 'trrocket_dyn' );
		body.append( 'lang', cfg.lang );
		var i;
		for ( i = 0; i < batch.length; i++ ) {
			body.append( 'items[]', batch[ i ] );
		}

		window.fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( res ) {
			return res.json();
		} ).then( function ( res ) {
			var data = ( res && res.success && res.data ) ? res.data : {};
			var b;
			for ( b = 0; b < batch.length; b++ ) {
				var o = batch[ b ];
				// Remember misses as null too, so we never re-ask for them.
				cache[ o ] = Object.prototype.hasOwnProperty.call( data, o ) ? data[ o ] : null;
			}
			persist();
			applyPending();
			if ( rest.length ) {
				schedule();
			}
		} ).catch( function () {
			applyPending();
		} );
	}

	// Apply everything we now know to the queued nodes/attributes. The observer is
	// paused while we write so our own edits don't feed back into it.
	function applyPending() {
		observer.disconnect();

		var keepN = [];
		var i;
		for ( i = 0; i < pendingNodes.length; i++ ) {
			var p = pendingNodes[ i ];
			if ( ! Object.prototype.hasOwnProperty.call( cache, p.orig ) ) {
				keepN.push( p );
				continue;
			}
			applyText( p.node, p.orig, cache[ p.orig ] );
		}
		pendingNodes = keepN;

		var keepA = [];
		for ( i = 0; i < pendingAttrs.length; i++ ) {
			var q = pendingAttrs[ i ];
			if ( ! Object.prototype.hasOwnProperty.call( cache, q.orig ) ) {
				keepA.push( q );
				continue;
			}
			if ( cache[ q.orig ] && q.el.isConnected &&
				( q.el.getAttribute( q.attr ) || '' ).trim() === q.orig ) {
				q.el.setAttribute( q.attr, cache[ q.orig ] );
			}
		}
		pendingAttrs = keepA;

		observe();
	}

	var observer = new window.MutationObserver( function ( records ) {
		observer.disconnect();
		var i, a;
		for ( i = 0; i < records.length; i++ ) {
			var m = records[ i ];
			if ( m.type === 'childList' ) {
				for ( a = 0; a < m.addedNodes.length; a++ ) {
					collect( m.addedNodes[ a ] );
				}
			} else if ( m.type === 'characterData' ) {
				queueText( m.target );
			}
		}
		observe();
	} );

	function observe() {
		if ( document.body ) {
			observer.observe( document.body, {
				childList: true,
				subtree: true,
				characterData: true
			} );
		}
	}

	observe();
}() );
