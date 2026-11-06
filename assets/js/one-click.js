/* TranslateRocket — «Translate my site»: one button, the plugin chooses the rest (5/11/2026).
   1. the browser's translator is made ready at once, inside the click (Chrome downloads its model only after a real click);
   2. every page of the site is read (string collection, as «Scan site for text»);
   3. each language is translated: the owner's key if there is one, else the browser's translator for the languages it has,
      else MyMemory (official, free) on the server;
   4. the languages go online and the links to the translated site are shown. */
( function () {
	'use strict';
	var C = window.TRRocketOneClick;
	var go = document.getElementById( 'trr-oneclick-go' );
	if ( ! C || ! go || ! window.fetch || ! window.FormData ) {
		return;
	}
	var run = document.getElementById( 'trr-oneclick-run' );
	var fill = document.getElementById( 'trr-oneclick-fill' );
	var step = document.getElementById( 'trr-oneclick-step' );
	var eng = document.getElementById( 'trr-oneclick-engine' );
	var end = document.getElementById( 'trr-oneclick-end' );
	var T = C.i18n || {};
	var busy = false;

	function fmt( s ) {
		var a = arguments;
		return String( s || '' ).replace( /%(\d)\$s/g, function ( m, n ) { return a[ +n ]; } ).replace( '%s', a[ 1 ] );
	}
	function say( t ) { step.textContent = t; }
	function bar( p ) { fill.style.width = Math.max( 2, Math.min( 100, Math.round( p ) ) ) + '%'; }
	function post( data ) {
		var b = new FormData();
		Object.keys( data ).forEach( function ( k ) { b.append( k, data[ k ] ); } );
		return fetch( C.ajax, { method: 'POST', credentials: 'same-origin', body: b } ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
			return r.json();
		} );
	}
	function wait( s ) { return new Promise( function ( ok ) { setTimeout( ok, s * 1000 ); } ); }

	/* ---- the browser's own translator ---- */
	function onAPhone() {
		var u = navigator.userAgentData;
		if ( u && typeof u.mobile === 'boolean' ) { return u.mobile; }
		return /Android|iPhone|iPad|iPod|Mobile|Silk|Kindle/i.test( navigator.userAgent || '' );
	}
	var browserOk = ! C.hasKey && ! onAPhone() && window.isSecureContext && typeof window.Translator !== 'undefined' && !! window.Translator.create;
	var translators = {}; // language code → Promise of a translator (null when this language cannot be done here)

	function prepare( l ) {
		if ( ! browserOk ) {
			return Promise.resolve( null );
		}
		var lastPct = -1;
		var p = Promise.resolve( window.Translator.availability ? window.Translator.availability( { sourceLanguage: C.source, targetLanguage: l.bcp } ) : 'available' ).then( function ( st ) {
			if ( ! st || 'unavailable' === st ) { return null; }
			return window.Translator.create( {
				sourceLanguage: C.source,
				targetLanguage: l.bcp,
				monitor: function ( m ) {
					m.addEventListener( 'downloadprogress', function ( e ) {
						var pct = Math.round( ( e.loaded || 0 ) * 100 );
						if ( pct !== lastPct && pct < 100 ) { lastPct = pct; say( fmt( T.download, pct + '%' ) ); }
					} );
				},
			} );
		} ).catch( function () { return null; } );
		// A model that never arrives must not hold the whole run: after 90 s this language goes to the server.
		return Promise.race( [ p, wait( 90 ).then( function () { return null; } ) ] );
	}

	var MARK = /<\/?\d{1,3}\/?>/g;
	function marks( t ) { return ( String( t ).match( MARK ) || [] ).join( '' ); }
	function firstLetter( t ) {
		var re = /<\/?\d{1,3}\/?>|\p{L}/gu, m;
		while ( ( m = re.exec( String( t ) ) ) ) { if ( /^\p{L}$/u.test( m[ 0 ] ) ) { return m; } }
		return null;
	}
	function keepCapital( src, out, bcp ) {
		if ( ! out ) { return out; }
		var a = firstLetter( src ), b = firstLetter( out );
		if ( ! a || ! b || a[ 0 ] === a[ 0 ].toLowerCase() || b[ 0 ] === b[ 0 ].toUpperCase() ) { return out; }
		var up;
		try { up = b[ 0 ].toLocaleUpperCase( bcp ); } catch ( e ) { up = b[ 0 ].toUpperCase(); }
		return out.slice( 0, b.index ) + up + out.slice( b.index + b[ 0 ].length );
	}
	// A sentence with a link travels with numbered marks: the whole sentence first; if the marks do not come back as
	// they left, the pieces between them one by one (as browser-engine.js).
	function tagSafe( tr, text ) {
		var want = marks( text );
		var p = '' === want ? tr.translate( text ) : tr.translate( text ).then( function ( w ) {
			return w && marks( w ) === want ? w : null;
		}, function () { return null; } ).then( function ( w ) {
			if ( w ) { return w; }
			var bits = String( text ).split( /(<\/?\d{1,3}\/?>)/ );
			return bits.reduce( function ( ch, bit, k ) {
				return ch.then( function ( acc ) {
					if ( k % 2 === 1 || '' === bit.trim() ) { return acc + bit; }
					var lead = bit.match( /^\s*/ )[ 0 ], tail = bit.match( /\s*$/ )[ 0 ];
					return tr.translate( bit.trim() ).catch( function () { return ''; } ).then( function ( t ) {
						return acc + lead + ( t && t.trim() ? t.trim() : bit.trim() ) + tail;
					} );
				} );
			}, Promise.resolve( '' ) );
		} );
		return p;
	}

	function inBrowser( l, tr, share ) {
		var tried = {}, seen = 0, total = Math.max( 1, l.missing );
		function round() {
			return post( { action: 'trrocket_browser_pending', nonce: C.brNonce, lang: l.code } ).then( function ( j ) {
				var d = ( j && j.data ) || {};
				var items = ( d.items || [] ).filter( function ( it ) { return ! Object.prototype.hasOwnProperty.call( tried, it.text ); } );
				if ( ! items.length ) { return; }
				total = Math.max( total, seen + items.length );
				var out = [];
				return items.reduce( function ( ch, it ) {
					return ch.then( function () {
						tried[ it.text ] = true;
						return tagSafe( tr, it.text ).then( function ( t ) {
							t = keepCapital( it.text, t, l.bcp );
							if ( t && t.trim() ) { out.push( { text: it.text, ids: it.ids, translation: t } ); }
						}, function () {} ).then( function () {
							seen++;
							say( fmt( T.translating, l.label, seen, total ) );
							share( seen / total );
						} );
					} );
				}, Promise.resolve() ).then( function () {
					return out.length ? post( { action: 'trrocket_browser_store', nonce: C.brNonce, lang: l.code, items: JSON.stringify( out ) } ) : null;
				} ).then( round );
			} );
		}
		return round().then( function () { return { left: 0 }; } );
	}

	/* ---- on the server: the owner's key, or MyMemory ---- */
	function onServer( l, engine, share ) {
		var done = 0, laps = 0, retry = 0;
		function lap() {
			return post( { action: 'trrocket_translate_step', trrocket_auto_nonce: C.stepNonce, lang: l.code, engine: engine, done: done } ).then( function ( j ) {
				if ( ! j || ! j.success ) { throw new Error( ( j && j.data && j.data.error ) || '?' ); }
				var x = j.data;
				retry = 0;
				done = x.total || done;
				laps++;
				var left = x.left >= 0 ? x.left : 0;
				say( fmt( T.translating, l.label, done, done + left ) );
				share( done / Math.max( 1, done + left ) );
				if ( x.wait && laps < 2000 ) { return wait( x.wait ).then( lap ); }
				if ( x.error && ! x.count ) {
					// MyMemory's day is over (or the engine stopped): what is done stays, the rest waits.
					return { left: left, error: x.error };
				}
				if ( x.more && x.count > 0 && laps < 400 ) { return lap(); }
				return { left: left };
			}, function ( e ) {
				if ( retry < 5 ) { retry++; return wait( 10 * retry ).then( lap ); }
				throw e;
			} );
		}
		return lap();
	}

	/* ---- 1. read the site ---- */
	function readSite( share ) {
		var S = C.scan || {};
		var urls = ( S.urls || [] ).slice();
		var total = Math.max( urls.length, 1 + ( parseInt( S.total, 10 ) || 0 ) );
		var given = urls.length - 1, block = parseInt( S.block, 10 ) || 500, i = 0;
		function more() {
			if ( given >= total - 1 ) { return Promise.resolve( false ); }
			return post( { action: 'trrocket_scan_urls', _ajax_nonce: S.nonce, offset: String( given ) } ).then( function ( j ) {
				var got = ( j && j.success && j.data && j.data.urls ) ? j.data.urls : [];
				given += block;
				got.forEach( function ( u ) { if ( urls.indexOf( u ) < 0 ) { urls.push( u ); } } );
				return got.length > 0;
			} ).catch( function () { return false; } );
		}
		function next() {
			if ( i >= urls.length && i < total ) {
				return more().then( function ( ok ) { if ( ! ok ) { total = urls.length; } return next(); } );
			}
			if ( i >= total || i >= urls.length ) { return Promise.resolve(); }
			say( fmt( T.reading, i + 1, total ) );
			share( i / total );
			// The visit itself collects the page's sentences; the answer is thrown away.
			return fetch( urls[ i ], { credentials: 'same-origin', cache: 'no-store' } ).catch( function () {} ).then( function () {
				i++;
				return wait( 0.12 ).then( next );
			} );
		}
		return next();
	}

	function finish( left, limited ) {
		say( T.finishing );
		return post( { action: 'trrocket_oneclick_done', nonce: C.nonce } ).then( function ( j ) {
			var d = ( j && j.data ) || {};
			var langs = d.langs || [];
			var missing = 0;
			end.innerHTML = '';
			var h = document.createElement( 'p' );
			h.className = 'trr-oneclick-ok';
			h.textContent = fmt( T.done, langs.map( function ( x ) { return x.label; } ).join( ', ' ) );
			end.appendChild( h );
			var p = document.createElement( 'p' );
			langs.forEach( function ( x ) {
				missing += x.missing || 0;
				var a = document.createElement( 'a' );
				a.className = 'button';
				a.href = x.home;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = T.see + ': ' + x.label + ' ↗';
				p.appendChild( a );
				p.appendChild( document.createTextNode( ' ' ) );
			} );
			end.appendChild( p );
			if ( missing > 0 ) {
				var w = document.createElement( 'p' );
				w.className = 'description';
				w.textContent = ( limited ? T.limit + ' ' : '' ) + fmt( T.partial, missing );
				end.appendChild( w );
				// MyMemory's free day ran out: ten times more with the owner's e-mail, only if they say so.
				if ( limited && ! C.mmEmail && C.myEmail ) {
					var mm = document.createElement( 'p' );
					var mb = document.createElement( 'button' );
					mb.type = 'button';
					mb.className = 'button button-primary';
					mb.id = 'trr-oneclick-mm';
					mb.textContent = fmt( T.mmAsk, C.myEmail );
					var why = document.createElement( 'span' );
					why.className = 'description';
					why.style.display = 'block';
					why.textContent = T.mmWhy;
					mb.addEventListener( 'click', function () {
						mb.disabled = true;
						post( { action: 'trrocket_oneclick_mm', nonce: C.nonce } ).then( function ( j ) {
							if ( j && j.success ) {
								C.mmEmail = true;
								why.textContent = T.mmOk;
							} else {
								mb.disabled = false;
							}
						} ).catch( function () { mb.disabled = false; } );
					} );
					mm.appendChild( mb );
					mm.appendChild( why );
					end.appendChild( mm );
				}
			}
			end.hidden = false;
			run.hidden = true;
			bar( 100 );
		} );
	}

	// The e-mail opt-in for MyMemory: only where MyMemory is what will work (no key, no translator in this browser).
	var consent = document.getElementById( 'trr-oneclick-consent' );
	var consentBox = document.getElementById( 'trr-oneclick-mm-ok' );
	if ( consent && ! C.hasKey && ! C.mmEmail && ! ( 'Translator' in self && self.Translator ) ) {
		consent.hidden = false;
	}

	// «Remove it»: MyMemory no longer receives the e-mail.
	var off = document.getElementById( 'trr-oneclick-mm-off' );
	if ( off ) {
		off.addEventListener( 'click', function () {
			off.disabled = true;
			post( { action: 'trrocket_oneclick_mm', nonce: C.nonce, off: '1' } ).then( function ( j ) {
				if ( j && j.success ) {
					C.mmEmail = false;
					off.parentNode.textContent = T.mmOff;
				} else { off.disabled = false; }
			} ).catch( function () { off.disabled = false; } );
		} );
	}

	// the button is printed disabled: a click before this script is ready would otherwise be lost without a word
	go.disabled = false;
	go.addEventListener( 'click', function () {
		if ( busy ) { return; }
		busy = true;
		go.disabled = true;
		end.hidden = true;
		run.hidden = false;
		bar( 2 );
		say( T.preparing );
		// Inside the click: Chrome downloads a language model only after a real gesture of the user.
		C.langs.forEach( function ( l ) { translators[ l.code ] = prepare( l ); } );
		var limited = false, left = 0;
		var nL = C.langs.length;
		// The owner ticked the box: the e-mail is saved before the first sentence, so the larger day counts from the start.
		var agreed = ( consent && ! consent.hidden && consentBox && consentBox.checked && ! C.mmEmail )
			? post( { action: 'trrocket_oneclick_mm', nonce: C.nonce } ).then( function ( j ) {
				if ( j && j.success ) { C.mmEmail = true; consent.hidden = true; }
			} ).catch( function () {} )
			: Promise.resolve();
		agreed.then( function () {
			return readSite( function ( f ) { bar( 2 + f * 18 ); } );
		} ).then( function () {
			return C.langs.reduce( function ( ch, l, k ) {
				return ch.then( function () {
					var share = function ( f ) { bar( 20 + ( 78 * ( k + Math.min( 1, f ) ) ) / nL ); };
					return translators[ l.code ].then( function ( tr ) {
						if ( C.hasKey ) {
							eng.textContent = T.withKey;
							return onServer( l, '', share );
						}
						if ( tr ) {
							eng.textContent = T.withBrowser;
							return inBrowser( l, tr, share );
						}
						eng.textContent = C.mmEmail && T.withFreeMail ? T.withFreeMail : T.withFree;
						return onServer( l, C.free, share );
					} ).then( function ( r ) {
						left += ( r && r.left ) || 0;
						if ( r && r.error ) { limited = true; }
					} );
				} );
			}, Promise.resolve() );
		} ).then( function () {
			return finish( left, limited );
		} ).catch( function ( e ) {
			say( fmt( T.error, e && e.message ? e.message : '?' ) );
		} ).then( function () {
			busy = false;
			go.disabled = false;
		} );
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( busy ) { e.preventDefault(); e.returnValue = T.leave || ''; return T.leave; }
	} );
}() );
