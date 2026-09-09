/**
 * TranslateRocket — form validation/notice message translator.
 *
 * Form plugins insert validation and success messages with JavaScript after the
 * page loads, so the server-side engine never sees them. This swaps the text in
 * the known message containers (and only those — never the form inputs) using the
 * same translation map, re-applying after the plugin re-renders.
 */
( function () {
	'use strict';

	var cfg = window.trrocketForms || {};
	var map = cfg.map || {};
	if ( ! map || ! Object.keys( map ).length ) {
		return;
	}

	// Message/notice containers across the popular form plugins (never inputs).
	var SEL = '.wpcf7-response-output,.wpcf7-not-valid-tip,'
		+ '.srfm-error-message,.srfm-error,.srfm-success-box,.srfm-success-message,'
		+ '.wpforms-error,.wpforms-confirmation-container-full,'
		+ '.gfield_validation_message,.validation_message,.gform_validation_errors,.gform_confirmation_message,'
		+ '.forminator-label-error,.forminator-error-message,.forminator-response-message,'
		+ '.frm_error,.frm_message,'
		+ '[role="alert"]';

	function translateEl( el ) {
		var walker = document.createTreeWalker( el, NodeFilter.SHOW_TEXT, null );
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
		var els = document.querySelectorAll( SEL );
		for ( var i = 0; i < els.length; i++ ) {
			translateEl( els[ i ] );
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
		if ( window.MutationObserver ) {
			new MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true, characterData: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
