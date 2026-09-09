/**
 * TranslateRocket — WooCommerce Cart/Checkout blocks translator.
 *
 * The block Cart & Checkout render client-side (React) from a data store, so the
 * server-side page engine can't reach their text. This walks the block containers
 * and swaps any text node whose exact text has a translation, re-applying after
 * React re-renders. Best-effort: it only changes text content, never structure,
 * and the swaps are self-terminating (a translated string is never itself a key).
 */
( function () {
	'use strict';

	var cfg = window.trrocketWooBlocks || {};
	var map = cfg.map || {};
	if ( ! map || ! Object.keys( map ).length ) {
		return;
	}

	// WooCommerce block containers (cart, checkout, mini-cart drawer).
	var ROOTS = '.wp-block-woocommerce-cart,.wp-block-woocommerce-checkout,'
		+ '.wc-block-cart,.wc-block-checkout,.wc-block-mini-cart,.wc-block-mini-cart__drawer,'
		+ '.wc-block-components-drawer';

	function translateIn( root ) {
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null );
		var node;
		while ( ( node = walker.nextNode() ) ) {
			var val = node.nodeValue;
			if ( ! val ) {
				continue;
			}
			var t = val.trim();
			if ( '' !== t && map[ t ] && map[ t ] !== t ) {
				node.nodeValue = val.replace( t, map[ t ] );
			}
		}
	}

	function run() {
		var roots = document.querySelectorAll( ROOTS );
		for ( var i = 0; i < roots.length; i++ ) {
			translateIn( roots[ i ] );
		}
	}

	var pending = false;
	function schedule() {
		if ( pending ) {
			return;
		}
		pending = true;
		( window.requestAnimationFrame || window.setTimeout )( function () {
			pending = false;
			run();
		}, 50 );
	}

	function start() {
		run();
		if ( ! window.MutationObserver ) {
			return;
		}
		var obs = new MutationObserver( schedule );
		obs.observe( document.body, { childList: true, subtree: true, characterData: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
