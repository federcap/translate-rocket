/* TranslateRocket — one-click site scan. The browser (already logged in as admin)
   fetches every published page so the engine collects its translatable strings.
   No server-side self-requests, so it won't stall hosts with few PHP workers. */
( function () {
	'use strict';
	var S = window.TRRocketScan;
	if ( ! S ) {
		return;
	}
	var btn = document.getElementById( 'trr-scan-btn' );
	if ( ! btn ) {
		return;
	}
	var label = document.getElementById( 'trr-scan-label' );
	var prog  = document.getElementById( 'trr-scan-prog' );
	var fill  = document.getElementById( 'trr-scan-fill' );

	function setLabel( txt ) {
		if ( label ) {
			label.textContent = txt;
		}
	}

	btn.addEventListener( 'click', function () {
		var urls = ( S.urls || [] ).slice();
		// 14/10/2026: the page brings the home page and the first block; the others are asked for
		// on the way (before, the scan stopped at 1,000 pages).
		var total = Math.max( urls.length, 1 + ( parseInt( S.total, 10 ) || 0 ) );
		var given = urls.length - 1; // items handed out so far (the home page is not one of them)
		var block = parseInt( S.block, 10 ) || 500;
		function more( then ) {
			if ( ! S.ajax || given >= total - 1 ) {
				then( false );
				return;
			}
			var body = new URLSearchParams();
			body.append( 'action', 'trrocket_scan_urls' );
			body.append( '_ajax_nonce', S.nonce );
			body.append( 'offset', String( given ) );
			fetch( S.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					var got = ( j && j.success && j.data && j.data.urls ) ? j.data.urls : [];
					given += block;
					for ( var k = 0; k < got.length; k++ ) {
						if ( urls.indexOf( got[ k ] ) < 0 ) {
							urls.push( got[ k ] );
						}
					}
					then( got.length > 0 );
				} )
				.catch( function () { then( false ); } );
		}
		if ( ! total ) {
			setLabel( S.i18n.none );
			return;
		}
		if ( ! window.confirm( S.i18n.confirm ) ) {
			return;
		}
		btn.disabled = true;
		if ( prog ) {
			prog.style.display = 'block';
		}

		var i = 0;
		function step() {
			if ( i >= urls.length && i < total ) {
				more( function ( ok ) {
					if ( ! ok ) {
						total = urls.length; // nothing more to ask for: finish with what there is
					}
					step();
				} );
				return;
			}
			if ( i >= total || i >= urls.length ) {
				if ( fill ) {
					fill.style.width = '100%';
				}
				setLabel( S.i18n.done );
				setTimeout( function () {
					window.location.reload();
				}, 700 );
				return;
			}
			setLabel( S.i18n.scanning + ' ' + ( i + 1 ) + '/' + total );
			if ( fill ) {
				fill.style.width = Math.round( ( i / total ) * 100 ) + '%';
			}
			// The fetch itself triggers string collection; we discard the HTML.
			fetch( urls[ i ], { credentials: 'same-origin', cache: 'no-store' } )
				.catch( function () {} )
				.then( function () {
					i++;
					setTimeout( step, 120 );
				} );
		}
		step();
	} );
}() );
