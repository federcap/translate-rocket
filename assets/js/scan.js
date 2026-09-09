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
		var total = urls.length;
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
			if ( i >= total ) {
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
