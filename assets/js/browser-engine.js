/**
 * Bulk translation using the translator built into Chrome and Edge.
 *
 * The browser does the work on the device; PHP hands out the strings that are
 * still missing and stores what comes back. Nothing is sent to a third party
 * and nothing is charged to anyone.
 *
 * The whole panel stays hidden until we know the browser can actually do it —
 * the only place that answer exists is here, at run time.
 */
( function () {
	'use strict';

	var C = window.TRRocketBrowser;
	if ( ! C ) {
		return;
	}

	var box = document.getElementById( 'trr-browser-engine' );
	var go = document.getElementById( 'trr-browser-go' );
	var stopBtn = document.getElementById( 'trr-browser-stop' );
	var status = document.getElementById( 'trr-browser-status' );
	if ( ! box || ! go || ! stopBtn || ! status ) {
		return;
	}

	var stopping = false;
	var translator = null;

	function say( text ) {
		status.textContent = text;
	}

	function fmt( template, a, b ) {
		return String( template ).replace( '%1$d', a ).replace( '%2$d', b ).replace( '%d', a );
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', C.nonce );
		body.append( 'lang', C.lang );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( C.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				throw new Error( ( j && j.data && j.data.message ) || 'request failed' );
			}
			return j.data;
		} );
	}

	/**
	 * What this browser can do, as one of three answers:
	 *   'yes'      - translate away (possibly after a one-time model download)
	 *   'insecure' - the API exists in Chrome and Edge but only over https, and
	 *                this admin is on plain http
	 *   'no'       - no translator here (Firefox, Safari, mobile, or no model
	 *                for this pair)
	 *
	 * The https case is worth telling people about: it is their setup, and they
	 * can change it. The rest is just their browser, so we say nothing and stay
	 * out of the way.
	 */
	// Solo sui computer. Su un telefono l'oggetto Translator puo' esserci e
	// dichiarare la lingua "scaricabile", ma il traduttore non c'e': il pulsante
	// compariva e non avrebbe tradotto niente. Qui si risponde "unsupported",
	// cosi' resta offerta la via del copia-incolla, che sul telefono funziona.
	function onAPhone() {
		var uad = navigator.userAgentData;
		if ( uad && typeof uad.mobile === 'boolean' ) { return uad.mobile; }
		return /Android|iPhone|iPad|iPod|Mobile|Silk|Kindle/i.test( navigator.userAgent || '' );
	}

	function whatWeCanDo() {
		// Il telefono ha una risposta tutta sua, distinta da 'unsupported': li'
		// il traduttore non manca per questa coppia di lingue, manca proprio
		// come funzione del dispositivo, e la cosa si dice invece di far
		// sparire il pulsante.
		if ( onAPhone() ) {
			return Promise.resolve( 'phone' );
		}
		if ( typeof Translator === 'undefined' || ! Translator.availability ) {
			return Promise.resolve( window.isSecureContext ? 'no' : 'insecure' );
		}
		return Translator.availability( {
			sourceLanguage: C.source,
			targetLanguage: C.target,
		} ).then( function ( state ) {
			return ( state && state !== 'unavailable' ) ? 'yes' : 'unsupported';
		} ).catch( function () {
			return 'no';
		} );
	}

	function makeTranslator( again ) {
		// `again` throws the current one away first: see doBatch, where a dead
		// translator is rebuilt mid-run.
		if ( again ) {
			translator = null;
		}
		if ( translator ) {
			return Promise.resolve( translator );
		}
		say( C.i18n.preparing );
		return Translator.create( {
			sourceLanguage: C.source,
			targetLanguage: C.target,
			monitor: function ( m ) {
				m.addEventListener( 'downloadprogress', function ( e ) {
					// e.loaded runs 0..1. The browser deliberately blurs this.
					var pct = Math.round( ( e.loaded || 0 ) * 100 );
					say( C.i18n.downloading + ' ' + pct + '%' );
				} );
			},
		} ).then( function ( t ) {
			translator = t;
			return t;
		} );
	}

	/**
	 * One batch: translate every text in turn, then hand the lot back.
	 * Sequential on purpose — the API processes one at a time anyway, and this
	 * keeps the browser responsive and the run stoppable.
	 */
	function doBatch( items, done, total ) {
		var out = [];
		var i = 0;

		// One phrase, with a second chance. Chrome sometimes discards the
		// translator while a long run is going: from that point on EVERY call
		// rejects, the bar walks to the end, and nothing is saved. On the first
		// failure the translator is rebuilt and this same phrase retried; only a
		// second failure is taken as "the model refuses this one".
		function one( item, retried ) {
			return translator.translate( item.text ).then( function ( translation ) {
				if ( translation && translation.trim() !== '' ) {
					out.push( {
						text: item.text,
						ids: item.ids,
						translation: translation,
					} );
				}
			} ).catch( function () {
				if ( retried ) {
					return;
				}
				return makeTranslator( true ).then( function () {
					return one( item, true );
				} ).catch( function () {} );
			} );
		}

		function next() {
			if ( stopping || i >= items.length ) {
				return Promise.resolve();
			}
			var item = items[ i++ ];
			return one( item, false ).then( function () {
				say( fmt( C.i18n.progress, done + i, total ) );
				return next();
			} );
		}

		return next().then( function () {
			if ( ! out.length ) {
				return { saved: 0, missing: null };
			}
			return post( 'trrocket_browser_store', { items: JSON.stringify( out ) } );
		} );
	}

	function run() {
		var saved = 0;
		var reused = 0;
		var seen = 0;
		var total = 0;

		function round() {
			if ( stopping ) {
				return Promise.resolve();
			}
			return post( 'trrocket_browser_pending', {} ).then( function ( data ) {
				reused += data.reused || 0;
				if ( ! data.items || ! data.items.length ) {
					return null; // nothing left that needs the browser
				}
				if ( ! total ) {
					total = data.missing || data.items.length;
				}
				return doBatch( data.items, seen, total ).then( function ( res ) {
					seen += data.items.length;
					saved += ( res && res.saved ) || 0;
					return round();
				} );
			} );
		}

		return makeTranslator().then( round ).then( function () {
			var n = saved + reused;
			if ( stopping ) {
				say( C.i18n.stopped + ' ' + fmt( C.i18n.done, n ) + ' ' + C.i18n.reload );
			} else if ( n === 0 ) {
				say( C.i18n.nothing );
			} else {
				// La pagina si ricarica da sola. Prima chiedevamo all'utente di
				// farlo, ma intanto la tabella qui sotto continuava a mostrare
				// "0 tradotte" e sembrava che il lavoro non fosse servito a
				// niente — proprio mentre il messaggio diceva il contrario.
				say( fmt( C.i18n.done, n ) + ' ' + ( C.i18n.refreshing || '' ) );
				setTimeout( function () { window.location.reload(); }, 1200 );
			}
		} ).catch( function ( e ) {
			say( C.i18n.failed + ' ' + ( e && e.message ? e.message : '' ) );
		} ).then( function () {
			go.disabled = false;
			stopBtn.style.display = 'none';
			stopping = false;
		} );
	}

	go.addEventListener( 'click', function () {
		go.disabled = true;
		stopBtn.style.display = '';
		stopping = false;
		run();
	} );

	stopBtn.addEventListener( 'click', function () {
		stopping = true;
		stopBtn.disabled = true;
		say( C.i18n.stopped );
		window.setTimeout( function () {
			stopBtn.disabled = false;
		}, 1000 );
	} );

	/**
	 * No translator here (Firefox, Safari, a phone). Rather than vanish, point
	 * at the copy-and-paste round trip the plugin already has: it works in any
	 * browser and still needs no API key.
	 */
	function offerFallback() {
		var intro = document.getElementById( 'trr-browser-intro' );
		var actions = document.getElementById( 'trr-browser-actions' );
		var title = document.getElementById( 'trr-browser-title' );
		var altTitle = document.getElementById( 'trr-browser-title-alt' );
		var fallback = document.getElementById( 'trr-browser-fallback' );
		if ( intro ) { intro.hidden = true; }
		if ( actions ) { actions.hidden = true; }
		if ( title ) { title.hidden = true; }
		if ( altTitle ) { altTitle.hidden = false; }
		if ( fallback ) { fallback.hidden = false; }
	}

	// Only now, once we know the answer, does the panel appear.
	whatWeCanDo().then( function ( answer ) {
		box.hidden = false;
		if ( 'yes' === answer ) {
			return;
		}
		if ( 'insecure' === answer ) {
			// The API is there but out of reach over plain http. Say why, and
			// still offer the round trip that works anywhere.
			say( C.i18n.insecure );
			offerFallback();
			return;
		}
		if ( 'phone' === answer ) {
			// Si tiene tutto al suo posto e si spegne il pulsante: la funzione
			// resta visibile, e chi legge sa dove usarla.
			if ( go ) {
				go.disabled = true;
				go.classList.add( 'trr-spento' );
				go.setAttribute( 'aria-disabled', 'true' );
			}
			say( C.i18n.phone );
			var ripiego = document.getElementById( 'trr-browser-fallback' );
			if ( ripiego ) { ripiego.hidden = false; }
			return;
		}
		// 'no' and 'unsupported' both mean: not here, not for this pair.
		offerFallback();
		if ( 'unsupported' === answer ) {
			say( C.i18n.unsupported );
		}
	} );
}() );
