/* TranslateRocket — fetch the live model list for a provider and let the user pick one. */
( function () {
	'use strict';

	var CFG = window.TRRocketModels;
	if ( ! CFG ) {
		return;
	}

	function field( pid, name ) {
		return document.querySelector( '[name="providers[' + pid + '][' + name + ']"]' );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.trr-load-models' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var pid     = btn.getAttribute( 'data-provider' );
			var keyEl   = field( pid, 'api_key' );
			var modelEl = field( pid, 'model' );
			var sel     = document.querySelector( '.trr-models-select[data-provider="' + pid + '"]' );
			if ( ! sel || ! modelEl ) {
				return;
			}

			var msg   = document.querySelector( '.trr-models-msg[data-provider="' + pid + '"]' );
			var say   = function ( text, bad ) {
				if ( ! msg ) {
					if ( bad ) { window.alert( text ); }
					return;
				}
				msg.textContent = text;
				msg.className   = 'trr-models-msg ' + ( bad ? 'is-bad' : 'is-ok' );
				msg.hidden      = false;
			};
			// No key typed and none saved: say so right away, no round trip.
			if ( keyEl && '' === keyEl.value.trim() && ! keyEl.defaultValue ) {
				sel.style.display = 'none';
				say( CFG.i18n.noKey, true );
				keyEl.focus();
				return;
			}

			var label = btn.textContent;
			btn.disabled  = true;
			btn.textContent = CFG.i18n.loading;
			if ( msg ) { msg.hidden = true; }

			var fd = new FormData();
			fd.append( 'action', 'trrocket_models' );
			fd.append( '_ajax_nonce', CFG.nonce );
			fd.append( 'provider', pid );
			fd.append( 'key', keyEl ? keyEl.value.trim() : '' );

			var why = {
				'no-key': CFG.i18n.noKey,
				'key-refused': CFG.i18n.refused,
				'busy': CFG.i18n.busy,
				'net': CFG.i18n.net,
				'provider-error': CFG.i18n.provErr
			};
			fetch( CFG.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( r ) {
					btn.disabled    = false;
					btn.textContent = label;
					if ( ! r || ! r.success || ! r.data || ! r.data.models || ! r.data.models.length ) {
						sel.style.display = 'none';
						var code = r && r.data && r.data.error ? r.data.error : '';
						say( why[ code ] || CFG.i18n.none, true );
						if ( ( 'no-key' === code || 'key-refused' === code ) && keyEl ) { keyEl.focus(); }
						return;
					}
					sel.innerHTML = '';
					var ph = document.createElement( 'option' );
					ph.value       = '';
					ph.textContent = CFG.i18n.pick;
					sel.appendChild( ph );
					r.data.models.forEach( function ( m ) {
						var o = document.createElement( 'option' );
						o.value       = m;
						o.textContent = m;
						if ( m === modelEl.value ) {
							o.selected = true;
						}
						sel.appendChild( o );
					} );
					sel.style.display = '';
					say( CFG.i18n.found.replace( '%d', r.data.models.length ), false );
				} )
				.catch( function () {
					btn.disabled    = false;
					btn.textContent = label;
					say( CFG.i18n.net, true );
				} );
		} );
	} );

	// DeepL: read used / limit characters from its usage endpoint.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.trr-deepl-usage' ) : null;
		if ( ! btn ) { return; }
		e.preventDefault();
		var card  = btn.closest( '.trr-ai-provider' );
		var keyEl = card ? card.querySelector( '[name="providers[deepl][api_key]"]' ) : field( 'deepl', 'api_key' );
		var out   = card ? card.querySelector( '.trr-deepl-usage-out' ) : null;
		if ( ! out ) { return; }
		out.textContent = CFG.i18n.loading;
		btn.disabled = true;
		var fd = new FormData();
		fd.append( 'action', 'trrocket_deepl_usage' );
		fd.append( '_ajax_nonce', CFG.nonce );
		fd.append( 'key', keyEl ? keyEl.value : '' );
		fetch( CFG.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				btn.disabled = false;
				if ( r && r.success && r.data ) {
					var u = ( r.data.used || 0 ).toLocaleString();
					var l = r.data.limit ? ( ' / ' + r.data.limit.toLocaleString() ) : '';
					out.textContent = u + l + ' ' + CFG.i18n.chars;
				} else {
					out.textContent = ( r && r.data && r.data.error ) ? r.data.error : CFG.i18n.none;
				}
			} )
			.catch( function () { btn.disabled = false; out.textContent = CFG.i18n.none; } );
	} );

	// Picking from the dropdown fills the model text field.
	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t && t.classList && t.classList.contains( 'trr-models-select' ) ) {
			var modelEl = field( t.getAttribute( 'data-provider' ), 'model' );
			if ( modelEl && t.value ) {
				modelEl.value = t.value;
			}
		}
	} );
}() );
