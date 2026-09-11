/* TranslateRocket — front-end visual editor. Click any text to translate it. */
( function () {
	'use strict';
	var VE = window.trrocketVE;
	if ( ! VE ) {
		return;
	}
	// Source language: nothing to translate (the toolbar shows a "pick a language"
	// message instead), so the editor stays inert.
	if ( ! VE.editable ) {
		return;
	}

	/**
	 * The toolbar panels (visibility, SEO, whole-page) all drop from the same
	 * bar and overlap. Opening one has to close the others.
	 *
	 * Leaving it to the "click outside" handler did not work: every button
	 * calls stopPropagation(), so the document listener never ran and the panel
	 * you left open stayed on top of the one you just asked for.
	 */
	var PANNELLI = [
		[ 'trrocket-ve-vispanel', 'trrocket-ve-visbtn' ],
		[ 'trrocket-ve-seopanel', 'trrocket-ve-seobtn' ],
		[ 'trrocket-ve-bulkpanel', 'trrocket-ve-bulkbtn' ]
	];
	function chiudiAltriPannelli( tranne ) {
		PANNELLI.forEach( function ( coppia ) {
			if ( coppia[ 0 ] === tranne ) {
				return;
			}
			var pan = document.getElementById( coppia[ 0 ] );
			if ( ! pan || pan.hidden ) {
				return;
			}
			pan.hidden = true;
			var b = document.querySelector( '.' + coppia[ 1 ] );
			if ( b ) {
				b.classList.remove( 'is-open' );
				b.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}
	var pop = null, activeEl = null;
	var onlyTodo = false; // When true, Next/Prev only stop on untranslated texts.
	var blockMode = 'markers'; // Block-editor view: 'markers' ([1][2]) or 'html' (raw tags). Sticks across popups.
	var cur = null;       // { ta, initial, save } of the open popup, for auto-save.
	var downInPopup = false; // Whether the last mousedown started inside the popup.
	var dragging = null, dragOX, dragOY, dragSX, dragSY; // Quale riquadro si sta trascinando (null = nessuno).
	var locked = false, lockPos = null; // When locked, the popup stays put across navigation.
	var currentBlock = null; // The block element when the popup is in whole-paragraph mode.
	var currentAttr  = null; // The attribute name when the popup edits an attribute (alt/title…).

	function decode( s ) {
		try {
			return decodeURIComponent( s || '' );
		} catch ( e ) {
			return s || '';
		}
	}

	function esc( s ) {
		return ( s == null ? '' : String( s ) )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	// Strip leading/trailing whitespace (incl. non-breaking spaces) — that padding
	// is page structure, not part of the translation (and the server trims on save).
	function trimEdge( s ) {
		return ( s == null ? '' : String( s ) ).replace( /^[\s ]+|[\s ]+$/g, '' );
	}

	// Modern inline icons (16px, inherit color).
	var ICONS = {
		check: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>',
		save: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>',
		ai: '<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M12 2l1.9 5.6L19.5 9l-5.6 1.4L12 16l-1.9-5.6L4.5 9l5.6-1.4z"></path></svg>',
		gt: '<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M12.87 15.07l-2.54-2.51.03-.03A17.5 17.5 0 0 0 14.07 6H17V4h-7V2H8v2H1v2h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"></path></svg>',
		br: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="13" rx="2"></rect><path d="M8 21h8M12 17v4"></path></svg>',
		lockOpen: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 9.9-1"></path></svg>',
		lockClosed: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>',
		move: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l-3 3 3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20"></path></svg>',
		close: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"></path></svg>',
		info: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5M12 7.5h.01"></path></svg>',
		undo: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6M3.5 13a9 9 0 1 0 2.3-9.3L3 7"></path></svg>'
	};

	function btn( icon, label ) {
		return icon + '<span>' + esc( label ) + '</span>';
	}

	// Show/hide a small spinner on a button while it works (and disable it).
	function setBusy( el, busy ) {
		if ( ! el ) {
			return;
		}
		el.disabled = busy;
		if ( busy ) {
			if ( ! el.querySelector( '.trrocket-ve-spin' ) ) {
				var sp = document.createElement( 'span' );
				sp.className = 'trrocket-ve-spin';
				el.insertBefore( sp, el.firstChild );
			}
			el.classList.add( 'is-busy' );
		} else {
			var s = el.querySelector( '.trrocket-ve-spin' );
			if ( s ) {
				s.parentNode.removeChild( s );
			}
			el.classList.remove( 'is-busy' );
		}
	}

	function busyAll( pop, busy ) {
		Array.prototype.forEach.call(
			pop.querySelectorAll( '.trrocket-ve-save, .trrocket-ve-saveonly' ),
			function ( bb ) { setBusy( bb, busy ); }
		);
	}

	function lockIcon() {
		return locked ? ICONS.lockClosed : ICONS.lockOpen;
	}

	// "From → to" flags for the popup title bar (icons only), matching the top bar.
	function flagPair() {
		if ( ! VE.srcFlag && ! VE.curFlag ) {
			return '';
		}
		return '<span class="trrocket-ve-baflags" aria-hidden="true">' +
			( VE.srcFlag || '' ) +
			'<span class="trrocket-ve-baarrow"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>' +
			( VE.curFlag || '' ) +
			'</span>';
	}

	// The gradient title bar: drag handle (4-arrows on hover) + lock + close.
	function bar() {
		return '<div class="trrocket-ve-bar">' +
			'<span class="trrocket-ve-grip" aria-hidden="true">' + ICONS.move + '</span>' +
			flagPair() +
			'<span class="trrocket-ve-bartitle">' + esc( VE.label || 'TranslateRocket' ) + '</span>' +
			'<button type="button" class="trrocket-ve-lock' + ( locked ? ' is-locked' : '' ) + '" aria-label="' + esc( locked ? VE.i18n.unlock : VE.i18n.lock ) + '" title="' + esc( locked ? VE.i18n.unlock : VE.i18n.lock ) + '">' + lockIcon() + '</button>' +
			'<button type="button" class="trrocket-ve-close" aria-label="' + esc( VE.i18n.close ) + '" title="' + esc( VE.i18n.close ) + ' (Esc)">' + ICONS.close + '</button>' +
			'</div>';
	}

	// Google's per-language code tweaks, for the lawful "open in Google" link.
	function gtCode( code ) {
		var m = { 'pt-br': 'pt', zh: 'zh-CN', 'zh-tw': 'zh-TW' };
		return m[ code ] || code;
	}

	// --- il traduttore dentro Chrome ed Edge --------------------------------
	// Lavora sul dispositivo: nessuna chiave, nessun costo, e il testo non esce
	// dal computer. Se questo browser ce l'abbia si sa solo qui, a video, quindi
	// i pulsanti nascono nascosti e si mostrano da soli.
	//
	// Una sola istanza per tutta la pagina: crearne una a ogni clic ripartirebbe
	// da capo, e la prima creazione puo' dover scaricare il modello.
	var trBrowserPromessa = null, browserSaPromessa = null;

	// Il traduttore dentro il browser esiste solo sui computer: su Android e iOS
	// non c'e', ma l'oggetto Translator puo' comunque affacciarsi e rispondere
	// che la lingua e' "scaricabile" — e allora il pulsante compariva su un
	// telefono, dove non avrebbe funzionato. Meglio non offrirlo affatto.
	function suTelefono() {
		var uad = navigator.userAgentData;
		if ( uad && typeof uad.mobile === 'boolean' ) { return uad.mobile; }
		return /Android|iPhone|iPad|iPod|Mobile|Silk|Kindle/i.test( navigator.userAgent || '' );
	}

	// Tre esiti, non due:
	//   'si'       → si puo' tradurre qui e ora;
	//   'telefono' → il traduttore su questo dispositivo NON esiste (Chrome ed
	//                Edge lo hanno solo su computer). Il pulsante si mostra
	//                comunque, ma SPENTO: nasconderlo voleva dire che chi apre
	//                l'editor dal telefono non sapeva nemmeno che la funzione
	//                esistesse, e nelle dimostrazioni non si vedeva;
	//   'no'       → browser senza traduttore: non c'e' niente da promettere.
	function statoBrowser() {
		if ( browserSaPromessa ) { return browserSaPromessa; }
		if ( suTelefono() ) {
			browserSaPromessa = Promise.resolve( 'telefono' );
		} else if ( typeof Translator === 'undefined' || ! Translator.availability || ! window.isSecureContext ) {
			browserSaPromessa = Promise.resolve( 'no' );
		} else {
			browserSaPromessa = Translator.availability( { sourceLanguage: VE.srcBcp, targetLanguage: VE.dstBcp } )
				.then( function ( st ) { return ( !! st && 'unavailable' !== st ) ? 'si' : 'no'; } )
				.catch( function () { return 'no'; } );
		}
		return browserSaPromessa;
	}

	// Il cartello che spiega perche' quel pulsante e' spento. Si costruisce una
	// volta sola e resta appeso al documento.
	function avvisoSoloPc() {
		var esistente = document.getElementById( 'trrocket-solopc' );
		if ( esistente ) { esistente.hidden = false; return; }

		var velo = document.createElement( 'div' );
		velo.id = 'trrocket-solopc';
		velo.className = 'trrocket-solopc';
		velo.innerHTML = '<div class="trrocket-solopc-card" role="alertdialog" aria-modal="true">'
			+ '<b></b><p></p><button type="button"></button></div>';
		velo.querySelector( 'b' ).textContent = VE.i18n.soloPcTit || '';
		velo.querySelector( 'p' ).textContent = VE.i18n.soloPcTxt || '';
		velo.querySelector( 'button' ).textContent = VE.i18n.soloPcOk || 'OK';
		var chiudi = function () { velo.hidden = true; };
		velo.querySelector( 'button' ).addEventListener( 'click', chiudi );
		velo.addEventListener( 'click', function ( e ) { if ( e.target === velo ) { chiudi(); } } );
		document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key ) { chiudi(); } } );
		document.body.appendChild( velo );
	}

	// Spegne un pulsante senza toglierlo di mezzo. Il gestore si aggancia in
	// fase di CATTURA e ferma la propagazione: cosi' scatta prima di quello
	// vero, che resta registrato ma non parte mai.
	function spegniPerTelefono( el ) {
		el.classList.add( 'trrocket-spento' );
		el.setAttribute( 'aria-disabled', 'true' );
		if ( VE.i18n.soloPcTit ) { el.setAttribute( 'title', VE.i18n.soloPcTit ); }
		el.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			avvisoSoloPc();
		}, true );
	}

	function prendiTraduttore( rifai ) {
		if ( rifai ) { trBrowserPromessa = null; }
		if ( ! trBrowserPromessa ) {
			trBrowserPromessa = Translator.create( { sourceLanguage: VE.srcBcp, targetLanguage: VE.dstBcp } );
		}
		return trBrowserPromessa;
	}

	// Una frase, con una seconda possibilita'. Chrome ogni tanto butta via il
	// traduttore mentre un lavoro lungo e' in corso: da li' in poi TUTTE le
	// chiamate falliscono, la barra di avanzamento arriva in fondo e non e'
	// stato tradotto niente. Al primo errore il traduttore si ricrea e questa
	// stessa frase si riprova; solo il secondo errore e' un vero rifiuto.
	function traduciColBrowser( testo ) {
		return prendiTraduttore().then( function ( tr ) {
			return tr.translate( testo );
		} ).catch( function () {
			return prendiTraduttore( true ).then( function ( tr2 ) {
				return tr2.translate( testo );
			} );
		} );
	}

	function mostraSeBrowserSa( el ) {
		if ( ! el ) { return; }
		statoBrowser().then( function ( stato ) {
			if ( 'no' === stato ) { return; }
			el.hidden = false;
			if ( 'telefono' === stato ) { spegniPerTelefono( el ); }
		} );
	}

	function riempiColBrowser( src, ta, msg, bottone ) {
		msg.textContent = VE.i18n.working || '';
		setBusy( bottone, true );
		traduciColBrowser( src ).then( function ( out ) {
			setBusy( bottone, false );
			ta.value = out;
			msg.textContent = '';
			ta.focus();
		} ).catch( function () {
			setBusy( bottone, false );
			msg.textContent = perche( res );
		} );
	}

	function gtUrl( src ) {
		return 'https://translate.google.com/?sl=' + encodeURIComponent( gtCode( VE.srclang || 'auto' ) ) +
			'&tl=' + encodeURIComponent( gtCode( VE.lang ) ) +
			'&text=' + encodeURIComponent( src ) + '&op=translate';
	}

	// Keep the popup pinned where it is (used after a drag and while locked).
	function pin() {
		if ( ! pop ) {
			return;
		}
		if ( locked && lockPos ) {
			pop.style.left = lockPos.left + 'px';
			pop.style.top = lockPos.top + 'px';
			pop._dragged = true;
		}
	}

	// Toggle the lock: on -> remember the current spot and stop following the text;
	// off -> snap back to the active text.
	function toggleLock() {
		if ( ! pop ) {
			return;
		}
		locked = ! locked;
		if ( locked ) {
			var r = pop.getBoundingClientRect();
			lockPos = { left: r.left, top: r.top };
			pop._dragged = true;
		} else {
			lockPos = null;
			pop._dragged = false;
			position();
		}
		var b = pop.querySelector( '.trrocket-ve-lock' );
		if ( b ) {
			b.innerHTML = lockIcon();
			b.classList.toggle( 'is-locked', locked );
			b.title = locked ? VE.i18n.unlock : VE.i18n.lock;
		}
	}

	// data-trr-a-<key>: aria-label -> arialabel (the key has no punctuation).
	function attrKey( a ) {
		return ( a || '' ).replace( /[^a-z]/g, '' );
	}

	function attrLabel( a ) {
		var m = VE.i18n.attrLabels || {};
		return m[ a ] || a;
	}

	function closePop() {
		if ( pop && pop.parentNode ) {
			pop.parentNode.removeChild( pop );
		}
		pop = null;
		cur = null;
		// Block mode highlights several fragments at once — clear them all.
		Array.prototype.forEach.call( document.querySelectorAll( '.trrocket-ed-active' ), function ( e ) {
			e.classList.remove( 'trrocket-ed-active' );
		} );
		activeEl = null;
		currentBlock = null;
		currentAttr = null;
	}

	function position() {
		if ( ! pop || ! activeEl || pop._dragged ) {
			return;
		}
		// position:fixed (viewport coords), highest z-index, so it sits above sticky
		// page chrome. Prefer placing it BESIDE the text (right, then left) so the
		// text you're translating stays visible; fall back to below/above only when
		// there's no room on either side. Always clamped fully on-screen.
		var r   = activeEl.getBoundingClientRect();
		var vh  = window.innerHeight || document.documentElement.clientHeight;
		var vw  = document.documentElement.clientWidth;
		var ph  = pop.offsetHeight;
		var pw  = pop.offsetWidth;
		var gap = 10;
		var left, top;
		if ( r.right + gap + pw <= vw - 8 ) {
			left = r.right + gap;            // to the right of the text
		} else if ( r.left - gap - pw >= 8 ) {
			left = r.left - gap - pw;        // to the left of the text
		} else {
			// No room beside: go below, or above when there isn't room below.
			left = Math.max( 8, Math.min( r.left, vw - pw - 10 ) );
			top  = ( r.bottom + gap + ph <= vh - 8 ) ? ( r.bottom + gap ) : ( r.top - gap - ph );
			top  = Math.max( 8, Math.min( top, vh - ph - 8 ) );
			pop.style.left = left + 'px';
			pop.style.top  = top + 'px';
			return;
		}
		// Beside the text: align near its top, clamped on-screen.
		top = Math.max( 8, Math.min( r.top, vh - ph - 8 ) );
		pop.style.left = left + 'px';
		pop.style.top  = top + 'px';
	}

	// Ogni chiamata al server passa da qui.
	//
	// ⚠️ Con un tempo massimo, apposta. Una richiesta che non risponde mai
	// lasciava i pulsanti a girare all'infinito, senza niente sullo schermo che
	// dicesse cos'era successo: si restava a guardare due rotelline finche' non
	// ci si arrendeva, perdendo la frase appena scritta. Adesso l'attesa
	// finisce, il testo resta nella casella, e il messaggio dice se il sito ha
	// risposto oppure no.
	var API_ATTESA = 30000;

	function api( action, data, cb ) {
		var body = 'action=' + action + '&nonce=' + encodeURIComponent( VE.nonce ) + '&lang=' + encodeURIComponent( VE.lang );
		Object.keys( data ).forEach( function ( k ) {
			body += '&' + k + '=' + encodeURIComponent( data[ k ] );
		} );

		var chiuso = false;
		var ctrl   = ( 'undefined' !== typeof AbortController ) ? new AbortController() : null;

		function rispondi( res ) {
			if ( chiuso ) {
				return;
			}
			chiuso = true;
			window.clearTimeout( timer );
			cb( res );
		}

		var timer = window.setTimeout( function () {
			if ( ctrl ) {
				try { ctrl.abort(); } catch ( e ) {}
			}
			rispondi( { success: false, muto: true } );
		}, API_ATTESA );

		var opz = {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		};
		if ( ctrl ) {
			opz.signal = ctrl.signal;
		}

		fetch( VE.ajax, opz ).then( function ( r ) {
			return r.text().then( function ( t ) { return { stato: r.status, testo: t }; } );
		} ).then( function ( o ) {
			var res;
			try {
				res = JSON.parse( o.testo );
			} catch ( e ) {
				var m = o.testo.match( /\{[\s\S]*\}/ ); // tolerate a stray notice / trailing 0
				try {
					res = m ? JSON.parse( m[ 0 ] ) : { success: false };
				} catch ( e2 ) {
					res = { success: false };
				}
			}
			if ( res && ! res.success && ! res.stato ) {
				res.stato = o.stato;
			}
			rispondi( res );
		} ).catch( function () {
			rispondi( { success: false, muto: true } );
		} );
	}

	// Che cosa scrivere quando un salvataggio non e' andato a buon fine: un '⚠'
	// muto non dice se il sito ha risposto male o non ha risposto affatto.
	function perche( res ) {
		if ( res && res.muto ) {
			return VE.i18n.noAnswer || '⚠';
		}
		if ( res && res.stato && 200 !== res.stato ) {
			return ( VE.i18n.saveFailed || '⚠' ) + ' (' + res.stato + ')';
		}
		return VE.i18n.saveFailed || '⚠';
	}

	// All on-page editable texts, in document order, that are actually visible.
	function navItems() {
		return Array.prototype.filter.call(
			document.querySelectorAll( '.trrocket-ed' ),
			function ( e ) { return e.offsetParent !== null; }
		);
	}

	// Inline tags that merely format a run of text (so a paragraph that contains
	// them is still "one text" to translate, with the tags shown as [1] markers).
	var INLINE = { STRONG: 1, B: 1, EM: 1, I: 1, U: 1, S: 1, MARK: 1, CODE: 1, SUB: 1, SUP: 1, SMALL: 1, A: 1, SPAN: 1, Q: 1, ABBR: 1, CITE: 1, BDI: 1, BDO: 1, TIME: 1, INS: 1, DEL: 1, WBR: 1 };

	// The nearest text-bearing block an editable fragment belongs to.
	function textBlockOf( span ) {
		return span.closest ? span.closest( 'p,li,h1,h2,h3,h4,h5,h6,figcaption,blockquote,dd,dt,td,th,caption' ) : null;
	}

	// Visible editable fragments inside a block, in document order.
	function blockFrags( block ) {
		return Array.prototype.filter.call(
			block.querySelectorAll( '.trrocket-ed' ),
			function ( e ) { return e.offsetParent !== null; }
		);
	}

	// Reconstruct an inline element's opening tag (e.g. '<a href="…">') so the HTML
	// view can show real tags instead of [n] markers. Attribute values keep their
	// double quotes escaped so the tag string is valid and round-trips exactly.
	function openTag( el ) {
		var s = '<' + el.tagName.toLowerCase();
		var at = el.attributes || [];
		for ( var i = 0; i < at.length; i++ ) {
			s += ' ' + at[ i ].name + '="' + String( at[ i ].value ).replace( /"/g, '&quot;' ) + '"';
		}
		return s + '>';
	}

	// Flatten a block into one string. Inline tags become either numbered [n] markers
	// (mode 'markers' — the simplest view) or their real HTML tags (mode 'html' — full
	// control); each editable fragment contributes its source (useSrc) or current text.
	// Returns { str, slots } — slots is the ordered structure used to map an edited
	// string back onto the individual fragments. A "bail" slot means the block has
	// something we can't safely round-trip (a nested block), so the caller falls
	// back to single-fragment editing.
	function serializeBlock( block, useSrc, mode ) {
		var html = ( 'html' === mode );
		var n = 0, str = '', slots = [];
		( function walk( node ) {
			var kids = node.childNodes;
			for ( var i = 0; i < kids.length; i++ ) {
				var c = kids[ i ];
				if ( 3 === c.nodeType ) {
					var tv = c.nodeValue || '';
					if ( tv ) { str += tv; slots.push( { fixed: tv } ); }
				} else if ( 1 === c.nodeType ) {
					if ( c.classList && c.classList.contains( 'trrocket-ed' ) ) {
						// Trim each fragment: leading/trailing whitespace is structural
						// (HTML indentation, the space before a <strong>) and is re-applied
						// from the page on display — never baked into the stored string.
						var v = trimEdge( useSrc ? decode( c.getAttribute( 'data-trr-src' ) ) : c.textContent );
						str += v; slots.push( { frag: c } );
					} else if ( 'BR' === c.tagName ) {
						var b = html ? '<br>' : ( '[' + ( ++n ) + ']' );
						str += b; slots.push( { fixed: b } );
					} else if ( INLINE[ c.tagName ] ) {
						var o = html ? openTag( c ) : ( '[' + ( ++n ) + ']' );
						str += o; slots.push( { fixed: o } );
						walk( c );
						var cl = html ? ( '</' + c.tagName.toLowerCase() + '>' ) : ( '[' + ( ++n ) + ']' );
						str += cl; slots.push( { fixed: cl } );
					} else {
						slots.push( { bail: true } );
					}
				}
			}
		} )( block );
		return { str: str, slots: slots };
	}

	// Map a user-edited block string back onto its fragments, using the fixed
	// markers/whitespace as anchors. Returns [{span, value}] or null if the markers
	// were broken (so we never save a corrupted mapping).
	function parseBlock( userStr, slots ) {
		var pos = 0, pending = null, out = [];
		for ( var i = 0; i < slots.length; i++ ) {
			var s = slots[ i ];
			if ( s.bail ) { return null; }
			if ( s.frag ) {
				if ( pending ) { return null; }
				pending = s.frag;
			} else {
				var idx = userStr.indexOf( s.fixed, pos );
				if ( -1 === idx ) { return null; }
				if ( pending ) { out.push( { span: pending, value: userStr.slice( pos, idx ) } ); pending = null; }
				pos = idx + s.fixed.length;
			}
		}
		if ( pending ) { out.push( { span: pending, value: userStr.slice( pos ) } ); }
		return out;
	}

	// Rebuild a block string from its slot template, choosing each fragment's value
	// via valueFor(span). Inverse of parseBlock — used to merge AI output with the
	// fragments the user has already translated.
	function buildBlockStr( slots, valueFor ) {
		var s = '';
		slots.forEach( function ( sl ) { s += sl.frag ? valueFor( sl.frag ) : sl.fixed; } );
		return s;
	}

	function slotValue( map, span ) {
		for ( var i = 0; map && i < map.length; i++ ) { if ( map[ i ].span === span ) { return map[ i ].value; } }
		return null;
	}

	// Navigation units in document order: a multi-fragment block is one unit; any
	// other editable text (or attribute-bearing span) is its own unit.
	function navUnits() {
		// Both translatable text (.trrocket-ed) and attributes (.trrocket-ed-attr),
		// in document order, so Prev/Next walks them all as one numbered sequence.
		var all = Array.prototype.filter.call(
			document.querySelectorAll( '.trrocket-ed, .trrocket-ed-attr' ),
			function ( e ) { return e.offsetParent !== null; }
		);
		var units = [], seen = [];
		all.forEach( function ( sp ) {
			if ( sp.classList.contains( 'trrocket-ed-attr' ) ) {
				var attrs = ( sp.getAttribute( 'data-trr-attrs' ) || '' ).split( ',' ).filter( Boolean );
				attrs.forEach( function ( a ) { units.push( { type: 'attr', el: sp, attr: a } ); } );
				return;
			}
			var blk = textBlockOf( sp );
			var frags = blk ? blockFrags( blk ) : [ sp ];
			if ( blk && frags.length > 1 ) {
				if ( seen.indexOf( blk ) === -1 ) {
					seen.push( blk );
					units.push( { type: 'block', block: blk, frags: frags, el: frags[ 0 ] } );
				}
			} else {
				units.push( { type: 'single', el: sp } );
			}
		} );
		return units;
	}

	function unitTodo( u ) {
		if ( 'block' === u.type ) {
			return u.frags.some( function ( f ) { return f.classList.contains( 'trrocket-ed-untr' ); } );
		}
		if ( 'attr' === u.type ) {
			// An attribute still showing its source text counts as "to do".
			var srcv = decode( u.el.getAttribute( 'data-trr-a-' + attrKey( u.attr ) ) || '' );
			var curv = trimEdge( u.el.getAttribute( u.attr ) || '' );
			return '' !== srcv && srcv === curv;
		}
		return u.el.classList.contains( 'trrocket-ed-untr' );
	}

	function currentUnitIndex( units ) {
		for ( var k = 0; k < units.length; k++ ) {
			var u = units[ k ];
			if ( 'block' === u.type && u.block === currentBlock ) { return k; }
			if ( 'attr' === u.type && u.el === activeEl && u.attr === currentAttr ) { return k; }
			if ( 'single' === u.type && u.el === activeEl ) { return k; }
		}
		// Fallback: a block we opened, matched by its fragment.
		for ( var j = 0; j < units.length; j++ ) {
			if ( units[ j ].frags && units[ j ].frags.indexOf( activeEl ) !== -1 ) { return j; }
			if ( units[ j ].el === activeEl ) { return j; }
		}
		return -1;
	}

	// Open the right editor for a navigation unit.
	function openUnit( u ) {
		if ( 'block' === u.type ) {
			openBlockPop( u );
		} else if ( 'attr' === u.type ) {
			openAttrPop( u.el, u.attr );
		} else {
			openPop( u.el );
		}
	}

	// Visited-string history, so "Back" returns to the previous text you edited —
	// however you got there (clicking around, Prev/Next…), clickable repeatedly.
	var navHistory   = [];
	var navGoingBack = false;
	function currentDescriptor() {
		if ( currentAttr && activeEl ) { return { type: 'attr', el: activeEl, attr: currentAttr }; }
		if ( currentBlock ) { return { type: 'block', block: currentBlock }; }
		if ( activeEl ) { return { type: 'single', el: activeEl }; }
		return null;
	}
	function recordHistory() {
		if ( navGoingBack ) {
			return;
		}
		var d = currentDescriptor();
		if ( ! d ) {
			return;
		}
		var last = navHistory[ navHistory.length - 1 ];
		if ( last && last.type === d.type && last.el === d.el && last.block === d.block && last.attr === d.attr ) {
			return; // Don't record the same unit twice in a row.
		}
		navHistory.push( d );
		if ( navHistory.length > 50 ) {
			navHistory.shift();
		}
	}
	function goBack() {
		if ( ! navHistory.length ) {
			return;
		}
		var d = navHistory.pop();
		commit( function () {
			navGoingBack = true;
			try {
				if ( 'attr' === d.type ) {
					openAttrPop( d.el, d.attr );
				} else if ( 'block' === d.type ) {
					openBlockPop( { type: 'block', block: d.block, frags: blockFrags( d.block ), el: blockFrags( d.block )[ 0 ] } );
				} else {
					openPop( d.el );
				}
			} finally {
				navGoingBack = false;
			}
		} );
	}

	function isDirty() {
		return !! ( cur && cur.ta && cur.ta.value !== cur.initial );
	}

	// Save the current edit if it changed, then run `then`. Prevents losing edits
	// when you navigate to another text or close the editor.
	function commit( then ) {
		if ( isDirty() && cur && cur.save ) {
			cur.save( false, then );
		} else {
			then();
		}
	}

	// Lo scostamento della pagina segue l'altezza VERA della barra: su schermi
	// stretti va a capo su due righe, e un valore fisso lascerebbe il primo
	// pezzo di pagina nascosto sotto.
	function adeguaSpazioBarra() {
		var bar = document.getElementById( 'trrocket-ve-bar' );
		if ( ! bar ) { return; }
		document.body.style.setProperty( 'padding-top', bar.offsetHeight + 'px', 'important' );
	}
	window.addEventListener( 'resize', adeguaSpazioBarra );
	window.addEventListener( 'orientationchange', adeguaSpazioBarra );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', adeguaSpazioBarra );
	} else {
		adeguaSpazioBarra();
	}

	// Show "% · done/total" progress in the top toolbar; refreshed after each save.
	function updateProgress() {
		var bar = document.getElementById( 'trrocket-ve-bar' );
		if ( ! bar ) {
			return;
		}
		var els = navItems();
		var total = els.length, todo = 0;
		els.forEach( function ( e ) {
			if ( e.classList.contains( 'trrocket-ed-untr' ) ) {
				todo++;
			}
		} );
		var done = total - todo;
		var span = bar.querySelector( '.trrocket-ve-progress' );
		if ( ! span ) {
			span = document.createElement( 'span' );
			span.className = 'trrocket-ve-progress';
			span.innerHTML = '<span class="trrocket-ve-progbar"><span class="trrocket-ve-progfill"></span></span><span class="trrocket-ve-proglabel"></span>';
			var exit = bar.querySelector( '.trrocket-ve-exit' );
			if ( exit ) {
				bar.insertBefore( span, exit );
			} else {
				bar.appendChild( span );
			}
		}
		var pct  = total ? Math.round( done / total * 100 ) : 100;
		var fill = span.querySelector( '.trrocket-ve-progfill' );
		if ( fill ) {
			fill.style.width = pct + '%';
			// Red (0%) → amber → green (100%).
			fill.style.background = 'hsl(' + Math.round( pct * 1.2 ) + ', 72%, 50%)';
		}
		var lbl = span.querySelector( '.trrocket-ve-proglabel' );
		if ( lbl ) {
			lbl.textContent = pct + '% · ' + done + '/' + total;
		}
		// La barra di avanzamento e' un elemento in piu': su schermo stretto puo'
		// far scattare un altro capo, e lo spazio sopra la pagina va rifatto.
		adeguaSpazioBarra();
	}

	// Jump to the editable `delta` units away (e.g. +1 / -1); a whole paragraph
	// counts as one unit. When "only untranslated" is on, skip units that are
	// already fully translated.
	function navTo( delta ) {
		var units = navUnits();
		var i = currentUnitIndex( units );
		if ( i < 0 ) {
			return false;
		}
		var j = i;
		do {
			j += delta;
		} while ( j >= 0 && j < units.length && onlyTodo && ! unitTodo( units[ j ] ) );
		if ( j < 0 || j >= units.length ) {
			return false;
		}
		var u = units[ j ];
		commit( function () {
			u.el.scrollIntoView( { block: 'center' } );
			openUnit( u );
		} );
		return true;
	}

	function openPop( el ) {
		recordHistory();
		closePop();
		activeEl = el;
		currentBlock = null;
		currentAttr = null;
		el.classList.add( 'trrocket-ed-active' );
		var src = decode( el.getAttribute( 'data-trr-src' ) );
		var items = navUnits();
		var idx = -1;
		for ( var u = 0; u < items.length; u++ ) { if ( items[ u ].el === el ) { idx = u; break; } }

		pop = document.createElement( 'div' );
		pop.className = 'trrocket-ve-pop';
		pop.setAttribute( 'translate', 'no' ); // Editor chrome: never let a translator rewrite the source preview.
		pop.innerHTML = bar() +
			'<div class="trrocket-ve-top">' +
			'<div class="trrocket-ve-nav">' +
			'<button type="button" class="trrocket-ve-prev" title="' + esc( VE.i18n.prev ) + ' (Alt+←)">‹</button>' +
			'<span class="trrocket-ve-count"></span>' +
			'<button type="button" class="trrocket-ve-next" title="' + esc( VE.i18n.next ) + ' (Alt+→)">›</button>' +
			'</div>' +
			'<label class="trrocket-ve-onlytodo"><input type="checkbox" class="trrocket-ve-onlytodo-cb"> <span></span></label>' +
			'</div>' +
			'<div class="trrocket-ve-src"></div>' +
			'<div class="trrocket-ve-tools"><a class="trrocket-ve-histback" role="button" tabindex="0" hidden></a><a class="trrocket-ve-selall" role="button" tabindex="0"></a><a class="trrocket-ve-restore" role="button" tabindex="0" title="' + esc( VE.i18n.restoreTip || '' ) + '"></a></div>' +
			'<textarea class="trrocket-ve-tr"></textarea>' +
			'<div class="trrocket-ve-row trrocket-ve-row1">' +
			'<button type="button" class="trrocket-ve-save"></button>' +
			'<button type="button" class="trrocket-ve-saveonly"></button>' +
			'</div>' +
			'<div class="trrocket-ve-stack">' +
			'<button type="button" class="trrocket-ve-br" hidden></button>' +
			'<button type="button" class="trrocket-ve-gt"></button>' +
			'<button type="button" class="trrocket-ve-ai"></button>' +
			'</div>' +
			'<div class="trrocket-ve-msgrow"><span class="trrocket-ve-msg"></span></div>';
		pop.querySelector( '.trrocket-ve-src' ).textContent = src;
		pop.querySelector( '.trrocket-ve-count' ).textContent = ( idx >= 0 ? ( idx + 1 ) : '?' ) + ' / ' + items.length;
		var ta = pop.querySelector( '.trrocket-ve-tr' );
		ta.value = trimEdge( el.textContent );
		pop.querySelector( '.trrocket-ve-save' ).innerHTML = btn( ICONS.check, VE.i18n.savenext );
		pop.querySelector( '.trrocket-ve-save' ).title = 'Ctrl+Enter';
		pop.querySelector( '.trrocket-ve-saveonly' ).innerHTML = btn( ICONS.save, VE.i18n.save );
		pop.querySelector( '.trrocket-ve-ai' ).innerHTML = btn( ICONS.ai, VE.i18n.ai );
		pop.querySelector( '.trrocket-ve-gt' ).innerHTML = btn( ICONS.gt, VE.i18n.gt );
		pop.querySelector( '.trrocket-ve-br' ).innerHTML = btn( ICONS.br, VE.i18n.br );
		pop.querySelector( '.trrocket-ve-selall' ).textContent = VE.i18n.selall;
		var hb = pop.querySelector( '.trrocket-ve-histback' );
		if ( hb ) { hb.innerHTML = '&#8592; ' + esc( VE.i18n.back || 'Back' ); hb.hidden = ( 0 === navHistory.length ); }
		pop.querySelector( '.trrocket-ve-restore' ).innerHTML = ICONS.undo + ' <span>' + esc( VE.i18n.restore ) + '</span>';
		pop.querySelector( '.trrocket-ve-onlytodo span' ).textContent = VE.i18n.onlytodo;
		pop.querySelector( '.trrocket-ve-onlytodo' ).title = VE.i18n.onlytodoTip || '';
		var cb = pop.querySelector( '.trrocket-ve-onlytodo-cb' );
		cb.checked = onlyTodo;
		cb.addEventListener( 'change', function () { onlyTodo = cb.checked; } );
		var prevBtn = pop.querySelector( '.trrocket-ve-prev' );
		var nextBtn = pop.querySelector( '.trrocket-ve-next' );
		prevBtn.disabled = ( idx <= 0 );
		nextBtn.disabled = ( idx < 0 || idx >= items.length - 1 );
		document.body.appendChild( pop );
		position();
		// preventScroll stops the browser scrolling the textarea into view, which
		// would shift the popup right after it opens (missed clicks).
		try {
			ta.focus( { preventScroll: true } );
		} catch ( e ) {
			ta.focus();
		}
		ta.setSelectionRange( ta.value.length, ta.value.length );
		position();
		pin();

		var msg = pop.querySelector( '.trrocket-ve-msg' );

		// thenNext = true -> after saving jump to the next text; after = run instead
		// of the default close (used by the auto-save-before-leaving flow).
		function save( thenNext, after ) {
			msg.textContent = VE.i18n.working;
			busyAll( pop, true );
			var val = ta.value;
			var target = activeEl;
			api( 'trrocket_ve_save', { src: src, translation: val }, function ( res ) {
				busyAll( pop, false );
				if ( res && res.success ) {
					if ( target ) {
						if ( '' === val.trim() ) {
							target.textContent = src;
							target.classList.add( 'trrocket-ed-untr' );
						} else {
							target.textContent = val;
							target.classList.remove( 'trrocket-ed-untr' );
						}
					}
					if ( cur && cur.ta === ta ) {
						cur.initial = val; // no longer dirty.
					}
					// A progress-bar glitch must never block saving/navigation.
					try { updateProgress(); } catch ( ex ) {}
					if ( after ) {
						after();
						return;
					}
					if ( thenNext && navTo( 1 ) ) {
						return;
					}
					msg.textContent = VE.i18n.saved;
					setTimeout( closePop, 500 );
				} else {
					msg.textContent = perche( res );
				}
			} );
		}

		cur = { ta: ta, initial: ta.value, save: save };

		pop.querySelector( '.trrocket-ve-save' ).addEventListener( 'click', function () { save( true ); } );
		pop.querySelector( '.trrocket-ve-saveonly' ).addEventListener( 'click', function () { save( false ); } );
		prevBtn.addEventListener( 'click', function () { navTo( -1 ); } );
		nextBtn.addEventListener( 'click', function () { navTo( 1 ); } );
		pop.querySelector( '.trrocket-ve-selall' ).addEventListener( 'click', function () { ta.focus(); ta.select(); } );
		// Auto-fill the translation in place (same tab) — AI provider or free Google.
		function autofill( action, srcBtn ) {
			msg.textContent = VE.i18n.working;
			setBusy( srcBtn, true );
			api( action, { src: src }, function ( res ) {
				setBusy( srcBtn, false );
				if ( res && res.success && res.data && res.data.translation ) {
					ta.value = res.data.translation;
					msg.textContent = '';
					ta.focus();
				} else {
					msg.textContent = ( res && res.data ) ? String( res.data ).slice( 0, 50 ) : '⚠';
				}
			} );
		}
		pop.querySelector( '.trrocket-ve-ai' ).addEventListener( 'click', function () { autofill( 'trrocket_ve_ai', this ); } );
		// Il traduttore del browser: nessuna chiamata al server, la traduzione
		// nasce sul dispositivo. Il pulsante si mostra solo dove esiste davvero.
		var brB = pop.querySelector( '.trrocket-ve-br' );
		mostraSeBrowserSa( brB );
		brB.addEventListener( 'click', function () { riempiColBrowser( src, ta, msg, this ); } );
		pop.querySelector( '.trrocket-ve-gt' ).addEventListener( 'click', function () {
			if ( VE.autoG ) {
				autofill( 'trrocket_ve_gt', this );
			} else {
				window.open( gtUrl( pop.querySelector( '.trrocket-ve-src' ).textContent ), '_blank', 'noopener' );
			}
		} );
		ta.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ( e.ctrlKey || e.metaKey ) ) {
				e.preventDefault();
				save( true ); // Ctrl/Cmd+Enter = save & next.
			} else if ( e.altKey && ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) ) {
				e.preventDefault();
				navTo( 1 );
			} else if ( e.altKey && ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) ) {
				e.preventDefault();
				navTo( -1 );
			}
		} );
	}

	// Whole-paragraph editor: a block split by inline tags (<strong>, <a>, <br>…)
	// is edited as ONE text, with the tags shown as [1] [2] markers that snap back
	// to the real formatting on save. Falls back to single-fragment editing if the
	// block can't be safely round-tripped.
	function openBlockPop( unit ) {
		var block = unit.block;
		var ser = serializeBlock( block, false, blockMode ); // current translation, in the chosen view.
		if ( ser.slots.some( function ( s ) { return s.bail; } ) ) {
			openPop( unit.el );
			return;
		}
		var srcStr = serializeBlock( block, true, blockMode ).str; // source text, in the chosen view.

		recordHistory();
		closePop();
		activeEl = unit.el;
		currentBlock = block;
		currentAttr = null;
		unit.frags.forEach( function ( f ) { f.classList.add( 'trrocket-ed-active' ); } );

		var units = navUnits();
		var idx = -1;
		for ( var u = 0; u < units.length; u++ ) { if ( units[ u ].block === block ) { idx = u; break; } }

		pop = document.createElement( 'div' );
		pop.className = 'trrocket-ve-pop trrocket-ve-pop-block';
		pop.setAttribute( 'translate', 'no' ); // Editor chrome: never let a translator rewrite the source preview.
		pop.innerHTML = bar() +
			'<div class="trrocket-ve-top">' +
			'<div class="trrocket-ve-nav">' +
			'<button type="button" class="trrocket-ve-prev" title="' + esc( VE.i18n.prev ) + ' (Alt+←)">‹</button>' +
			'<span class="trrocket-ve-count"></span>' +
			'<button type="button" class="trrocket-ve-next" title="' + esc( VE.i18n.next ) + ' (Alt+→)">›</button>' +
			'</div>' +
			'<label class="trrocket-ve-onlytodo"><input type="checkbox" class="trrocket-ve-onlytodo-cb"> <span></span></label>' +
			'</div>' +
			'<div class="trrocket-ve-src"></div>' +
			'<div class="trrocket-ve-tools"><a class="trrocket-ve-histback" role="button" tabindex="0" hidden></a><a class="trrocket-ve-selall" role="button" tabindex="0"></a><a class="trrocket-ve-restore" role="button" tabindex="0" title="' + esc( VE.i18n.restoreTip || '' ) + '"></a></div>' +
			'<div class="trrocket-ve-modes" role="group">' +
			'<button type="button" class="trrocket-ve-mode-blocks"></button>' +
			'<button type="button" class="trrocket-ve-mode-html"></button>' +
			'</div>' +
			'<textarea class="trrocket-ve-tr"></textarea>' +
			'<div class="trrocket-ve-blockhint"></div>' +
			'<div class="trrocket-ve-row trrocket-ve-row1">' +
			'<button type="button" class="trrocket-ve-save"></button>' +
			'<button type="button" class="trrocket-ve-saveonly"></button>' +
			'</div>' +
			'<div class="trrocket-ve-stack">' +
			'<button type="button" class="trrocket-ve-br" hidden></button>' +
			'<button type="button" class="trrocket-ve-gt"></button>' +
			'<button type="button" class="trrocket-ve-ai"></button>' +
			'</div>' +
			'<div class="trrocket-ve-msgrow"><span class="trrocket-ve-msg"></span></div>';
		pop.querySelector( '.trrocket-ve-src' ).textContent = srcStr;
		pop.querySelector( '.trrocket-ve-count' ).textContent = ( idx >= 0 ? ( idx + 1 ) : '?' ) + ' / ' + units.length;
		var ta = pop.querySelector( '.trrocket-ve-tr' );
		ta.value = ser.str;
		pop.querySelector( '.trrocket-ve-save' ).innerHTML = btn( ICONS.check, VE.i18n.savenext );
		pop.querySelector( '.trrocket-ve-save' ).title = 'Ctrl+Enter';
		pop.querySelector( '.trrocket-ve-saveonly' ).innerHTML = btn( ICONS.save, VE.i18n.save );
		pop.querySelector( '.trrocket-ve-ai' ).innerHTML = btn( ICONS.ai, VE.i18n.ai );
		pop.querySelector( '.trrocket-ve-gt' ).innerHTML = btn( ICONS.gt, VE.i18n.gt );
		pop.querySelector( '.trrocket-ve-br' ).innerHTML = btn( ICONS.br, VE.i18n.br );
		pop.querySelector( '.trrocket-ve-selall' ).textContent = VE.i18n.selall;
		var hb = pop.querySelector( '.trrocket-ve-histback' );
		if ( hb ) { hb.innerHTML = '&#8592; ' + esc( VE.i18n.back || 'Back' ); hb.hidden = ( 0 === navHistory.length ); }
		pop.querySelector( '.trrocket-ve-restore' ).innerHTML = ICONS.undo + ' <span>' + esc( VE.i18n.restore ) + '</span>';
		pop.querySelector( '.trrocket-ve-onlytodo span' ).textContent = VE.i18n.onlytodo;
		pop.querySelector( '.trrocket-ve-onlytodo' ).title = VE.i18n.onlytodoTip || '';
		var cb = pop.querySelector( '.trrocket-ve-onlytodo-cb' );
		cb.checked = onlyTodo;
		cb.addEventListener( 'change', function () { onlyTodo = cb.checked; } );
		var prevBtn = pop.querySelector( '.trrocket-ve-prev' );
		var nextBtn = pop.querySelector( '.trrocket-ve-next' );
		prevBtn.disabled = ( idx <= 0 );
		nextBtn.disabled = ( idx < 0 || idx >= units.length - 1 );
		document.body.appendChild( pop );
		position();
		try {
			ta.focus( { preventScroll: true } );
		} catch ( e ) {
			ta.focus();
		}
		ta.setSelectionRange( ta.value.length, ta.value.length );
		position();
		pin();

		var msg = pop.querySelector( '.trrocket-ve-msg' );

		// View toggle: numbered [1][2] markers ↔ raw HTML (full control). Rebuilds the
		// textarea, the source preview and the slots used by save/AI for the chosen view,
		// carrying any unsaved edits across.
		pop.querySelector( '.trrocket-ve-mode-blocks' ).textContent = VE.i18n.modeBlocks || 'Blocks';
		pop.querySelector( '.trrocket-ve-mode-blocks' ).title = VE.i18n.modeBlocksTip || '';
		pop.querySelector( '.trrocket-ve-mode-html' ).textContent = VE.i18n.modeHtml || 'HTML';
		pop.querySelector( '.trrocket-ve-mode-html' ).title = VE.i18n.modeHtmlTip || '';
		function applyModeUI() {
			var mb = pop.querySelector( '.trrocket-ve-mode-blocks' );
			var mh = pop.querySelector( '.trrocket-ve-mode-html' );
			if ( mb ) { mb.classList.toggle( 'is-active', 'markers' === blockMode ); }
			if ( mh ) { mh.classList.toggle( 'is-active', 'html' === blockMode ); }
			var hint = pop.querySelector( '.trrocket-ve-blockhint' );
			if ( hint ) {
				hint.innerHTML = '<span class="trrocket-ve-hint-ico">' + ICONS.info + '</span> ' +
					esc( 'html' === blockMode ? ( VE.i18n.htmlHint || '' ) : ( VE.i18n.blockHint || '' ) );
			}
		}
		function switchMode( newMode ) {
			if ( newMode === blockMode ) { return; }
			var curMap = parseBlock( ta.value, ser.slots ); // carry current edits across, if mappable.
			blockMode = newMode;
			ser = serializeBlock( block, false, blockMode );
			srcStr = serializeBlock( block, true, blockMode ).str;
			if ( curMap ) {
				ta.value = buildBlockStr( ser.slots, function ( frag ) {
					var v = slotValue( curMap, frag );
					return ( null !== v ) ? v : trimEdge( frag.textContent );
				} );
			} else {
				// Markers/tags were broken — fall back to the current translation in the new view.
				ta.value = ser.str;
				if ( msg ) { msg.textContent = VE.i18n.blockMarkers || ''; setTimeout( function () { if ( msg ) { msg.textContent = ''; } }, 2500 ); }
			}
			if ( cur && cur.ta === ta ) { cur.initial = ser.str; }
			pop.querySelector( '.trrocket-ve-src' ).textContent = srcStr;
			applyModeUI();
			try { ta.focus( { preventScroll: true } ); } catch ( e ) { ta.focus(); }
		}
		pop.querySelector( '.trrocket-ve-mode-blocks' ).addEventListener( 'click', function () { switchMode( 'markers' ); } );
		pop.querySelector( '.trrocket-ve-mode-html' ).addEventListener( 'click', function () { switchMode( 'html' ); } );
		applyModeUI();

		function save( thenNext, after ) {
			var val = ta.value;
			var mapping = parseBlock( val, ser.slots );
			if ( ! mapping ) {
				msg.textContent = VE.i18n.blockMarkers || '⚠';
				return;
			}
			msg.textContent = VE.i18n.working;
			busyAll( pop, true );
			var items = mapping.map( function ( m ) {
				return { src: decode( m.span.getAttribute( 'data-trr-src' ) ), translation: trimEdge( m.value ) };
			} );
			api( 'trrocket_ve_save_block', { items: JSON.stringify( items ) }, function ( res ) {
				busyAll( pop, false );
				if ( res && res.success ) {
					mapping.forEach( function ( m ) {
						// Keep each fragment's structural whitespace (e.g. the space
						// before a <strong>) while swapping in the new translation.
						var orig = m.span.textContent;
						var lead = ( orig.match( /^\s*/ ) || [ '' ] )[ 0 ];
						var trail = ( orig.match( /\s*$/ ) || [ '' ] )[ 0 ];
						var v = trimEdge( m.value );
						if ( '' === v ) {
							m.span.textContent = decode( m.span.getAttribute( 'data-trr-src' ) );
							m.span.classList.add( 'trrocket-ed-untr' );
						} else {
							m.span.textContent = lead + v + trail;
							m.span.classList.remove( 'trrocket-ed-untr' );
						}
					} );
					if ( cur && cur.ta === ta ) {
						cur.initial = val;
					}
					try { updateProgress(); } catch ( ex ) {}
					if ( after ) { after(); return; }
					if ( thenNext && navTo( 1 ) ) { return; }
					msg.textContent = VE.i18n.saved;
					setTimeout( closePop, 500 );
				} else {
					msg.textContent = perche( res );
				}
			} );
		}

		cur = { ta: ta, initial: ta.value, save: save };

		pop.querySelector( '.trrocket-ve-save' ).addEventListener( 'click', function () { save( true ); } );
		pop.querySelector( '.trrocket-ve-saveonly' ).addEventListener( 'click', function () { save( false ); } );
		prevBtn.addEventListener( 'click', function () { navTo( -1 ); } );
		nextBtn.addEventListener( 'click', function () { navTo( 1 ); } );
		pop.querySelector( '.trrocket-ve-selall' ).addEventListener( 'click', function () { ta.focus(); ta.select(); } );
		function autofill( action, srcBtn ) {
			// Only fill the fragments still missing; keep the ones already translated.
			var missing = ser.slots.filter( function ( s ) { return s.frag && s.frag.classList.contains( 'trrocket-ed-untr' ); } );
			if ( ! missing.length ) {
				msg.textContent = VE.i18n.allDone || VE.i18n.saved;
				setTimeout( function () { if ( msg ) { msg.textContent = ''; } }, 1500 );
				return;
			}
			msg.textContent = VE.i18n.working;
			setBusy( srcBtn, true );
			api( action, { src: srcStr }, function ( res ) {
				setBusy( srcBtn, false );
				if ( res && res.success && res.data && res.data.translation ) {
					var aiStr = res.data.translation;
					var curMap = parseBlock( ta.value, ser.slots );
					var aiMap  = parseBlock( aiStr, ser.slots );
					if ( curMap && aiMap ) {
						// Merge: done fragments keep their current text, missing ones take AI.
						ta.value = buildBlockStr( ser.slots, function ( frag ) {
							var done = ! frag.classList.contains( 'trrocket-ed-untr' );
							var cv = slotValue( curMap, frag );
							var av = slotValue( aiMap, frag );
							if ( done ) { return ( null !== cv ) ? cv : ( av || '' ); }
							return ( null !== av ) ? av : ( cv || '' );
						} );
					} else {
						// Markers came back broken — fall back to the full AI result.
						ta.value = aiStr;
					}
					msg.textContent = '';
					ta.focus();
				} else {
					msg.textContent = ( res && res.data ) ? String( res.data ).slice( 0, 50 ) : '⚠';
				}
			} );
		}
		pop.querySelector( '.trrocket-ve-ai' ).addEventListener( 'click', function () { autofill( 'trrocket_ve_ai', this ); } );
		// Il traduttore del browser: nessuna chiamata al server, la traduzione
		// nasce sul dispositivo. Il pulsante si mostra solo dove esiste davvero.
		var brB = pop.querySelector( '.trrocket-ve-br' );
		mostraSeBrowserSa( brB );
		brB.addEventListener( 'click', function () { riempiColBrowser( src, ta, msg, this ); } );
		pop.querySelector( '.trrocket-ve-gt' ).addEventListener( 'click', function () {
			if ( VE.autoG ) {
				autofill( 'trrocket_ve_gt', this );
			} else {
				window.open( gtUrl( pop.querySelector( '.trrocket-ve-src' ).textContent ), '_blank', 'noopener' );
			}
		} );
		ta.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ( e.ctrlKey || e.metaKey ) ) {
				e.preventDefault();
				save( true );
			} else if ( e.altKey && ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) ) {
				e.preventDefault();
				navTo( 1 );
			} else if ( e.altKey && ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) ) {
				e.preventDefault();
				navTo( -1 );
			}
		} );
	}

	// Editor for a translatable attribute (image alt, link title, placeholder…).
	function openAttrPop( el, attr ) {
		recordHistory();
		closePop();
		activeEl = el;
		currentBlock = null;
		el.classList.add( 'trrocket-ed-active' );
		var attrs = ( el.getAttribute( 'data-trr-attrs' ) || '' ).split( ',' ).filter( Boolean );
		attr = attr || attrs[ 0 ];
		if ( ! attr ) {
			closePop();
			return;
		}
		currentAttr = attr;
		var src = decode( el.getAttribute( 'data-trr-a-' + attrKey( attr ) ) );

		// Position in the unified Prev/Next sequence (text + attributes).
		var navU = navUnits();
		var navIdx = -1;
		for ( var ui = 0; ui < navU.length; ui++ ) {
			if ( 'attr' === navU[ ui ].type && navU[ ui ].el === el && navU[ ui ].attr === attr ) { navIdx = ui; break; }
		}

		var selHtml = '';
		if ( attrs.length > 1 ) {
			selHtml = '<select class="trrocket-ve-attrsel">' + attrs.map( function ( a ) {
				return '<option value="' + esc( a ) + '"' + ( a === attr ? ' selected' : '' ) + '>' + esc( attrLabel( a ) ) + '</option>';
			} ).join( '' ) + '</select>';
		}
		pop = document.createElement( 'div' );
		pop.className = 'trrocket-ve-pop';
		pop.setAttribute( 'translate', 'no' ); // Editor chrome: never let a translator rewrite the source preview.
		pop.innerHTML = bar() +
			'<div class="trrocket-ve-top">' +
			'<div class="trrocket-ve-nav">' +
			'<button type="button" class="trrocket-ve-prev" title="' + esc( VE.i18n.prev ) + ' (Alt+←)">‹</button>' +
			'<span class="trrocket-ve-count"></span>' +
			'<button type="button" class="trrocket-ve-next" title="' + esc( VE.i18n.next ) + ' (Alt+→)">›</button>' +
			'</div>' +
			'<label class="trrocket-ve-onlytodo"><input type="checkbox" class="trrocket-ve-onlytodo-cb"> <span></span></label>' +
			'</div>' +
			'<div class="trrocket-ve-attrhead"><span class="trrocket-ve-attrlabel"></span>' + selHtml + '</div>' +
			'<div class="trrocket-ve-src"></div>' +
			'<div class="trrocket-ve-tools"><a class="trrocket-ve-histback" role="button" tabindex="0" hidden></a><a class="trrocket-ve-selall" role="button" tabindex="0"></a><a class="trrocket-ve-restore" role="button" tabindex="0" title="' + esc( VE.i18n.restoreTip || '' ) + '"></a></div>' +
			'<textarea class="trrocket-ve-tr"></textarea>' +
			'<div class="trrocket-ve-row trrocket-ve-row1">' +
			'<button type="button" class="trrocket-ve-saveonly"></button>' +
			'</div>' +
			'<div class="trrocket-ve-stack">' +
			'<button type="button" class="trrocket-ve-br" hidden></button>' +
			'<button type="button" class="trrocket-ve-gt"></button>' +
			'<button type="button" class="trrocket-ve-ai"></button>' +
			'</div>' +
			'<div class="trrocket-ve-msgrow"><span class="trrocket-ve-msg"></span></div>';
		pop.querySelector( '.trrocket-ve-attrlabel' ).textContent = attrLabel( attr );
		pop.querySelector( '.trrocket-ve-src' ).textContent = src;
		var ta = pop.querySelector( '.trrocket-ve-tr' );
		ta.value = trimEdge( el.getAttribute( attr ) || '' );
		pop.querySelector( '.trrocket-ve-saveonly' ).innerHTML = btn( ICONS.save, VE.i18n.save );
		pop.querySelector( '.trrocket-ve-ai' ).innerHTML = btn( ICONS.ai, VE.i18n.ai );
		pop.querySelector( '.trrocket-ve-gt' ).innerHTML = btn( ICONS.gt, VE.i18n.gt );
		pop.querySelector( '.trrocket-ve-br' ).innerHTML = btn( ICONS.br, VE.i18n.br );
		pop.querySelector( '.trrocket-ve-selall' ).textContent = VE.i18n.selall;
		var hb = pop.querySelector( '.trrocket-ve-histback' );
		if ( hb ) { hb.innerHTML = '&#8592; ' + esc( VE.i18n.back || 'Back' ); hb.hidden = ( 0 === navHistory.length ); }
		pop.querySelector( '.trrocket-ve-restore' ).innerHTML = ICONS.undo + ' <span>' + esc( VE.i18n.restore ) + '</span>';
		pop.querySelector( '.trrocket-ve-count' ).textContent = ( navIdx >= 0 ? ( navIdx + 1 ) : '?' ) + ' / ' + navU.length;
		pop.querySelector( '.trrocket-ve-onlytodo span' ).textContent = VE.i18n.onlytodo;
		pop.querySelector( '.trrocket-ve-onlytodo' ).title = VE.i18n.onlytodoTip || '';
		var cbA = pop.querySelector( '.trrocket-ve-onlytodo-cb' );
		cbA.checked = onlyTodo;
		cbA.addEventListener( 'change', function () { onlyTodo = cbA.checked; } );
		var prevA = pop.querySelector( '.trrocket-ve-prev' );
		var nextA = pop.querySelector( '.trrocket-ve-next' );
		prevA.disabled = ( navIdx <= 0 );
		nextA.disabled = ( navIdx < 0 || navIdx >= navU.length - 1 );
		prevA.addEventListener( 'click', function () { navTo( -1 ); } );
		nextA.addEventListener( 'click', function () { navTo( 1 ); } );
		document.body.appendChild( pop );
		position();
		try {
			ta.focus( { preventScroll: true } );
		} catch ( e2 ) {
			ta.focus();
		}
		ta.setSelectionRange( ta.value.length, ta.value.length );
		position();
		pin();
		var msg = pop.querySelector( '.trrocket-ve-msg' );

		function save( thenNext, after ) {
			msg.textContent = VE.i18n.working;
			busyAll( pop, true );
			var val = ta.value;
			var target = activeEl, a = attr;
			api( 'trrocket_ve_save', { src: src, translation: val, type: 'attribute', ctx: a }, function ( res ) {
				busyAll( pop, false );
				if ( res && res.success ) {
					if ( target ) {
						target.setAttribute( a, val );
					}
					if ( cur && cur.ta === ta ) {
						cur.initial = val;
					}
					if ( after ) {
						after();
						return;
					}
					msg.textContent = VE.i18n.saved;
					setTimeout( closePop, 500 );
				} else {
					msg.textContent = perche( res );
				}
			} );
		}

		cur = { ta: ta, initial: ta.value, save: save };

		pop.querySelector( '.trrocket-ve-saveonly' ).addEventListener( 'click', function () { save( false ); } );
		pop.querySelector( '.trrocket-ve-selall' ).addEventListener( 'click', function () { ta.focus(); ta.select(); } );
		var sel = pop.querySelector( '.trrocket-ve-attrsel' );
		if ( sel ) {
			sel.addEventListener( 'change', function () { var na = sel.value; commit( function () { openAttrPop( el, na ); } ); } );
		}
		function autofill( action, srcBtn ) {
			msg.textContent = VE.i18n.working;
			setBusy( srcBtn, true );
			api( action, { src: src }, function ( res ) {
				setBusy( srcBtn, false );
				if ( res && res.success && res.data && res.data.translation ) {
					ta.value = res.data.translation;
					msg.textContent = '';
					ta.focus();
				} else {
					msg.textContent = ( res && res.data ) ? String( res.data ).slice( 0, 50 ) : '⚠';
				}
			} );
		}
		pop.querySelector( '.trrocket-ve-ai' ).addEventListener( 'click', function () { autofill( 'trrocket_ve_ai', this ); } );
		// Il traduttore del browser: nessuna chiamata al server, la traduzione
		// nasce sul dispositivo. Il pulsante si mostra solo dove esiste davvero.
		var brB = pop.querySelector( '.trrocket-ve-br' );
		mostraSeBrowserSa( brB );
		brB.addEventListener( 'click', function () { riempiColBrowser( src, ta, msg, this ); } );
		pop.querySelector( '.trrocket-ve-gt' ).addEventListener( 'click', function () {
			if ( VE.autoG ) {
				autofill( 'trrocket_ve_gt', this );
			} else {
				window.open( gtUrl( pop.querySelector( '.trrocket-ve-src' ).textContent ), '_blank', 'noopener' );
			}
		} );
		ta.addEventListener( 'keydown', function ( e3 ) {
			if ( 'Enter' === e3.key && ( e3.ctrlKey || e3.metaKey ) ) {
				e3.preventDefault();
				save( false );
			} else if ( e3.altKey && ( 'ArrowRight' === e3.key || 'ArrowDown' === e3.key ) ) {
				e3.preventDefault();
				navTo( 1 );
			} else if ( e3.altKey && ( 'ArrowLeft' === e3.key || 'ArrowUp' === e3.key ) ) {
				e3.preventDefault();
				navTo( -1 );
			}
		} );
	}

	// ---------------------------------------------------------------------
	// Un'immagine diversa per questa lingua.
	//
	// Il "testo" di un'immagine e' l'indirizzo del file, quindi si salva con
	// lo stesso meccanismo di tutto il resto: origine = indirizzo originale,
	// traduzione = indirizzo scelto. Cambia solo il modo di sceglierla, che
	// qui e' la libreria di WordPress e non una casella dove scrivere.
	// ---------------------------------------------------------------------
	var popImg = null;

	// Accanto all'elemento se c'e' spazio, se no sotto, e comunque sempre
	// dentro allo schermo. Stessa logica di position(), ma quella lavora sulle
	// variabili del popup di testo e riusarla sposterebbe l'altro pannello.
	function accanto( pannello, el ) {
		var r   = el.getBoundingClientRect();
		var vh  = window.innerHeight || document.documentElement.clientHeight;
		var vw  = document.documentElement.clientWidth;
		var ph  = pannello.offsetHeight;
		var pw  = pannello.offsetWidth;
		var gap = 10;
		var left;
		var top;

		if ( r.right + gap + pw <= vw - 8 ) {
			left = r.right + gap;
			top  = Math.max( 8, Math.min( r.top, vh - ph - 8 ) );
		} else if ( r.left - gap - pw >= 8 ) {
			left = r.left - gap - pw;
			top  = Math.max( 8, Math.min( r.top, vh - ph - 8 ) );
		} else {
			left = Math.max( 8, Math.min( r.left, vw - pw - 10 ) );
			top  = ( r.bottom + gap + ph <= vh - 8 ) ? ( r.bottom + gap ) : ( r.top - gap - ph );
			top  = Math.max( 8, Math.min( top, vh - ph - 8 ) );
		}
		pannello.style.left = left + 'px';
		pannello.style.top  = top + 'px';
	}

	// Toglie di mezzo (o rimette) tutto quello che l'editor disegna sopra la
	// pagina: la finestra dell'immagine e i pulsantini sulle foto. Serve quando
	// si apre la libreria media di WordPress, che sta a un piano piu' basso e
	// se li ritroverebbe addosso.
	function scena( visibile ) {
		var strato = document.getElementById( 'trrocket-ve-imgbadges' );
		if ( strato ) {
			strato.style.display = visibile ? '' : 'none';
		}
		if ( popImg ) {
			popImg.style.display = visibile ? '' : 'none';
		}
	}

	// In mezzo allo schermo, ma mai fuori dai bordi.
	function centra( pannello ) {
		var vw = document.documentElement.clientWidth;
		var vh = window.innerHeight || document.documentElement.clientHeight;
		var pw = pannello.offsetWidth;
		var ph = pannello.offsetHeight;
		pannello.style.left = Math.max( 8, Math.round( ( vw - pw ) / 2 ) ) + 'px';
		pannello.style.top  = Math.max( 8, Math.min( Math.round( ( vh - ph ) / 2 ), vh - ph - 8 ) ) + 'px';
	}

	function chiudiImg() {
		if ( popImg && popImg.parentNode ) { popImg.parentNode.removeChild( popImg ); }
		popImg = null;
	}

	function apriImg( el ) {
		chiudiImg();
		closePop();

		var originale = decode( el.getAttribute( 'data-trr-img' ) );
		var attuale   = el.getAttribute( 'src' ) || originale;

		popImg = document.createElement( 'div' );
		popImg.className = 'trrocket-ve-pop trrocket-ve-imgpop';
		// La stessa barra del riquadro di testo: sfondo colorato, maniglia per
		// trascinare, bandiere e pulsante di chiusura. Prima questa finestra si
		// costruiva un'intestazione tutta sua (.trrocket-ve-head) per cui non
		// esisteva nemmeno una riga di CSS: si vedevano il simbolo e la ✕
		// appiccicati al titolo, senza barra.
		popImg.innerHTML =
			'<div class="trrocket-ve-bar">' +
				'<span class="trrocket-ve-grip" aria-hidden="true">' + ICONS.move + '</span>' +
				flagPair() +
				'<span class="trrocket-ve-bartitle trrocket-ve-imgtit"></span>' +
				'<button type="button" class="trrocket-ve-close" aria-label="' + esc( VE.i18n.close ) + '" title="' + esc( VE.i18n.close ) + ' (Esc)">' + ICONS.close + '</button>' +
			'</div>' +
			'<p class="trrocket-ve-imgtxt"></p>' +
			'<div class="trrocket-ve-imgprev"><img alt=""><span class="trrocket-ve-imglab"></span></div>' +
			'<input type="url" class="trrocket-ve-imgurl" spellcheck="false">' +
			'<div class="trrocket-ve-imgbtns">' +
				'<button type="button" class="trrocket-ve-imgpick"></button>' +
				'<button type="button" class="trrocket-ve-imgreset"></button>' +
				'<button type="button" class="trrocket-ve-imgsave"></button>' +
			'</div>' +
			'<div class="trrocket-ve-msg"></div>';

		popImg.querySelector( '.trrocket-ve-imgtit' ).textContent  = VE.i18n.imgTit || '';
		popImg.querySelector( '.trrocket-ve-imgtxt' ).textContent  = VE.i18n.imgTxt || '';
		popImg.querySelector( '.trrocket-ve-imgpick' ).textContent = VE.i18n.imgPick || '';
		popImg.querySelector( '.trrocket-ve-imgreset' ).textContent = VE.i18n.imgReset || '';
		popImg.querySelector( '.trrocket-ve-imgsave' ).textContent = VE.i18n.save || 'Save';

		var anteprima = popImg.querySelector( '.trrocket-ve-imgprev img' );
		var etichetta = popImg.querySelector( '.trrocket-ve-imglab' );
		var casella   = popImg.querySelector( '.trrocket-ve-imgurl' );
		var msg       = popImg.querySelector( '.trrocket-ve-msg' );

		function mostra( url ) {
			anteprima.src = url;
			casella.value = url;
			etichetta.textContent = ( url === originale )
				? ( VE.i18n.imgOriginal || '' )
				: ( VE.i18n.imgNow || '' );
		}
		mostra( attuale );

		document.body.appendChild( popImg );
		// In mezzo allo schermo, non appiccicata alla foto: le immagini grandi
		// stanno spesso in cima alla pagina, e la finestra finiva schiacciata
		// contro il bordo di sopra. Da li' si trascina dove si vuole.
		centra( popImg );

		// La libreria di WordPress. Se per qualsiasi motivo non c'e', resta la
		// casella dell'indirizzo: meglio una strada in meno che un pulsante
		// che non fa niente.
		popImg.querySelector( '.trrocket-ve-imgpick' ).addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				msg.textContent = VE.i18n.imgNoMedia || '';
				casella.focus();
				return;
			}
			var telaio = window.wp.media( {
				title: VE.i18n.imgPickTit || '',
				button: { text: VE.i18n.imgPickUse || '' },
				library: { type: 'image' },
				multiple: false,
			} );
			telaio.on( 'select', function () {
				var scelta = telaio.state().get( 'selection' ).first();
				if ( scelta ) { mostra( scelta.toJSON().url ); }
			} );
			// I pulsantini sulle immagini e la finestra dell'editor stanno sopra
			// tutto: davanti alla libreria di WordPress diventano scarabocchi che
			// galleggiano sul vuoto. Si tolgono di mezzo finche' la libreria e'
			// aperta, e tornano appena si chiude — anche se si chiude con Esc o
			// cliccando fuori, per questo si ascolta 'escape' e 'close'.
			var indietro = function () { scena( true ); };
			telaio.on( 'close', indietro );
			telaio.on( 'escape', indietro );
			scena( false );
			telaio.open();
		} );

		popImg.querySelector( '.trrocket-ve-imgreset' ).addEventListener( 'click', function () {
			mostra( originale );
		} );

		popImg.querySelector( '.trrocket-ve-imgsave' ).addEventListener( 'click', function () {
			var scelto = trimEdge( casella.value );
			if ( '' === scelto ) { return; }
			var bottone = this;
			setBusy( bottone, true );
			// Tornare all'originale vuol dire salvare una traduzione uguale
			// all'originale: il motore non sostituisce piu' niente.
			api( 'trrocket_ve_save', { src: originale, translation: scelto, type: 'image' }, function ( res ) {
				setBusy( bottone, false );
				if ( res && res.success ) {
					el.setAttribute( 'src', scelto );
					// Anche qui: srcset resterebbe quello di prima e il browser
					// ricaricherebbe la foto originale.
					el.removeAttribute( 'srcset' );
					el.removeAttribute( 'sizes' );
					chiudiImg();
				} else {
					msg.textContent = perche( res );
				}
			} );
		} );

		popImg.querySelector( '.trrocket-ve-close' ).addEventListener( 'click', chiudiImg );
	}

	// Un pulsantino su ogni immagine marcata.
	//
	// Senza, un'immagine coperta da qualcosa non si puo' aprire: nelle hero a
	// tutta larghezza il titolo sta SOPRA alla foto, quindi il clic arriva al
	// testo e l'immagine sotto e' irraggiungibile. E non si puo' dirottare il
	// clic sul contenitore: quel testo e' a sua volta da tradurre, ed e' il
	// bersaglio piu' probabile.
	function segnaImmagini() {
		var viste = document.querySelectorAll( 'img.trrocket-ed-img' );
		if ( ! viste.length ) { return; }

		var strato = document.getElementById( 'trrocket-ve-imgbadges' );
		if ( ! strato ) {
			strato = document.createElement( 'div' );
			strato.id = 'trrocket-ve-imgbadges';
			document.body.appendChild( strato );
		}
		strato.innerHTML = '';

		Array.prototype.forEach.call( viste, function ( img ) {
			var r = img.getBoundingClientRect();
			// Fuori schermo o troppo piccola per meritarsi un pulsante.
			if ( r.width < 60 || r.height < 40 || r.bottom < 0 || r.top > window.innerHeight ) { return; }

			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'trrocket-ve-imgbadge';
			b.textContent = VE.i18n.imgPick || 'Image';
			b.style.left = Math.round( r.left + 8 ) + 'px';
			b.style.top  = Math.round( r.top + 8 ) + 'px';
			b.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				ev.stopPropagation();
				commit( function () { apriImg( img ); } );
			} );
			strato.appendChild( b );
		} );
	}

	// I pulsantini seguono la pagina: le posizioni sono in coordinate dello
	// schermo, quindi vanno rifatte quando si scorre o si ridimensiona.
	var attesaBadge = null;
	function ridisegnaBadge() {
		if ( attesaBadge ) { clearTimeout( attesaBadge ); }
		attesaBadge = setTimeout( segnaImmagini, 120 );
	}
	window.addEventListener( 'scroll', ridisegnaBadge, { passive: true } );
	window.addEventListener( 'resize', ridisegnaBadge );
	setTimeout( segnaImmagini, 600 );

	// Click a wrapped text -> open its editor (saving any unsaved edit first).
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest && e.target.closest( '.trrocket-ve-close' ) ) {
			e.preventDefault();
			e.stopPropagation();
			commit( closePop );
			return;
		}
		if ( e.target.closest && e.target.closest( '.trrocket-ve-lock' ) ) {
			e.preventDefault();
			e.stopPropagation();
			toggleLock();
			return;
		}
		if ( e.target.closest && e.target.closest( '.trrocket-ve-histback' ) ) {
			e.preventDefault();
			e.stopPropagation();
			goBack();
			return;
		}
		if ( e.target.closest && e.target.closest( '.trrocket-ve-restore' ) ) {
			e.preventDefault();
			e.stopPropagation();
			if ( pop ) {
				var rta = pop.querySelector( '.trrocket-ve-tr' );
				var rsrc = pop.querySelector( '.trrocket-ve-src' );
				if ( rta && rsrc ) { rta.value = rsrc.textContent; rta.focus(); }
			}
			return;
		}
		var el = e.target.closest ? e.target.closest( '.trrocket-ed' ) : null;
		if ( el ) {
			e.preventDefault();
			e.stopPropagation();
			// If this text sits in a paragraph split by inline tags, edit the WHOLE
			// paragraph as one; otherwise edit just this text.
			var blk = textBlockOf( el );
			var frags = blk ? blockFrags( blk ) : [ el ];
			commit( function () {
				if ( blk && frags.length > 1 ) {
					openBlockPop( { type: 'block', block: blk, frags: frags, el: el } );
				} else {
					openPop( el );
				}
			} );
			return;
		}
		// Le immagini si guardano PRIMA degli attributi: un <img> ha quasi
		// sempre anche un alt tradotto, e finirebbe nel ramo sbagliato aprendo
		// l'editor del testo alternativo invece del selettore.
		var imgEl = e.target.closest ? e.target.closest( '.trrocket-ed-img' ) : null;
		if ( imgEl ) {
			e.preventDefault();
			e.stopPropagation();
			commit( function () { apriImg( imgEl ); } );
			return;
		}
		var attrEl = e.target.closest ? e.target.closest( '.trrocket-ed-attr' ) : null;
		if ( attrEl ) {
			e.preventDefault();
			e.stopPropagation();
			commit( function () { openAttrPop( attrEl ); } );
			return;
		}
		// "Done" link: save first, then let it leave edit mode.
		var exit = e.target.closest ? e.target.closest( '.trrocket-ve-exit' ) : null;
		if ( exit && isDirty() ) {
			e.preventDefault();
			var href = exit.getAttribute( 'href' );
			commit( function () { window.location = href; } );
			return;
		}
		// Only close on a genuine outside click — NOT when a text selection that
		// started inside the popup happens to end outside it.
		if ( pop && ! downInPopup && ! e.target.closest( '.trrocket-ve-pop' ) && ! e.target.closest( '#trrocket-ve-bar' ) ) {
			commit( closePop );
		}
	}, true );

	// Remember where each press started, so a selection drag out of the popup
	// (mousedown inside, mouseup outside) doesn't count as an outside click.
	// Also: pressing the popup header (not a control) starts dragging it.
	document.addEventListener( 'mousedown', function ( e ) {
		downInPopup = !! ( pop && e.target.closest && e.target.closest( '.trrocket-ve-pop' ) );
		// Si trascina QUALSIASI riquadro, non solo quello del testo: prima
		// questo guardava la sola variabile `pop`, e la finestra dell'immagine
		// restava inchiodata dove nasceva.
		var handle = e.target.closest ? e.target.closest( '.trrocket-ve-bar' ) : null;
		var quale  = handle ? handle.closest( '.trrocket-ve-pop' ) : null;
		if ( quale && ! e.target.closest( 'button, a, input, select, label' ) ) {
			var r = quale.getBoundingClientRect();
			dragging = quale;
			dragOX = r.left; dragOY = r.top; dragSX = e.clientX; dragSY = e.clientY;
			quale._dragged = true;
			quale.style.left = r.left + 'px';
			quale.style.top = r.top + 'px';
			e.preventDefault();
		}
	}, true );

	document.addEventListener( 'mousemove', function ( e ) {
		if ( ! dragging ) {
			return;
		}
		var vw = document.documentElement.clientWidth;
		var vh = window.innerHeight || document.documentElement.clientHeight;
		dragging.style.left = Math.max( 4, Math.min( dragOX + ( e.clientX - dragSX ), vw - dragging.offsetWidth - 4 ) ) + 'px';
		dragging.style.top  = Math.max( 4, Math.min( dragOY + ( e.clientY - dragSY ), vh - dragging.offsetHeight - 4 ) ) + 'px';
	} );
	document.addEventListener( 'mouseup', function () {
		if ( dragging && locked && dragging === pop ) {
			var r = pop.getBoundingClientRect();
			lockPos = { left: r.left, top: r.top };
		}
		dragging = null;
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			commit( closePop );
		}
	} );
	window.addEventListener( 'resize', position );
	window.addEventListener( 'scroll', position, true );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( isDirty() ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	// Collapsible "Language visibility" panel in the top bar: per language, choose
	// translate / redirect to home / redirect to a URL / custom message / 404 — the
	// same controls as the post-editor meta box, saved straight from the live page.
	( function initVisPanel() {
		var btn   = document.querySelector( '.trrocket-ve-visbtn' );
		var panel = document.getElementById( 'trrocket-ve-vispanel' );
		if ( ! btn || ! panel ) {
			return;
		}
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			chiudiAltriPannelli( 'trrocket-ve-vispanel' );
			panel.hidden = ! panel.hidden;
			btn.classList.toggle( 'is-open', ! panel.hidden );
			btn.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
		} );
		// Close on a genuine outside click.
		document.addEventListener( 'click', function ( e ) {
			if ( panel.hidden ) {
				return;
			}
			if ( e.target.closest( '#trrocket-ve-vispanel' ) || e.target.closest( '.trrocket-ve-visbtn' ) ) {
				return;
			}
			panel.hidden = true;
			btn.classList.remove( 'is-open' );
			btn.setAttribute( 'aria-expanded', 'false' );
		} );
		// Only show the URL field for "redirect to URL" and the message box for
		// "custom message" — mirrors the meta box.
		function syncRow( row ) {
			var mode = row.querySelector( '.trrocket-ve-vismode' ).value;
			var url  = row.querySelector( '.trrocket-ve-visurl' );
			var msg  = row.querySelector( '.trrocket-ve-vismsg' );
			if ( url ) { url.hidden = ( 'url' !== mode ); }
			if ( msg ) { msg.hidden = ( 'message' !== mode ); }
		}
		Array.prototype.forEach.call( panel.querySelectorAll( '.trrocket-ve-visrow' ), function ( row ) {
			syncRow( row );
			row.querySelector( '.trrocket-ve-vismode' ).addEventListener( 'change', function () { syncRow( row ); } );
		} );
		var saveBtn = panel.querySelector( '.trrocket-ve-vissave' );
		var out     = panel.querySelector( '.trrocket-ve-vismsgout' );
		saveBtn.addEventListener( 'click', function () {
			var rules = {};
			Array.prototype.forEach.call( panel.querySelectorAll( '.trrocket-ve-visrow' ), function ( row ) {
				var u = row.querySelector( '.trrocket-ve-visurl' );
				var m = row.querySelector( '.trrocket-ve-vismsg' );
				rules[ row.getAttribute( 'data-lang' ) ] = {
					mode: row.querySelector( '.trrocket-ve-vismode' ).value,
					url:  u ? u.value : '',
					msg:  m ? m.value : ''
				};
			} );
			out.textContent = VE.i18n.working;
			setBusy( saveBtn, true );
			api( 'trrocket_ve_visibility', { post: panel.getAttribute( 'data-post' ), rules: JSON.stringify( rules ) }, function ( res ) {
				setBusy( saveBtn, false );
				out.textContent = ( res && res.success ) ? VE.i18n.saved : '⚠';
				setTimeout( function () { out.textContent = ''; }, 1800 );
			} );
		} );
	}() );

	// Collapsible "SEO" panel in the top bar: translated slug + SEO title + meta
	// description for the current language, saved straight from the live page.
	( function initSeoPanel() {
		var btn   = document.querySelector( '.trrocket-ve-seobtn' );
		var panel = document.getElementById( 'trrocket-ve-seopanel' );
		if ( ! btn || ! panel ) {
			return;
		}
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			chiudiAltriPannelli( 'trrocket-ve-seopanel' );
			panel.hidden = ! panel.hidden;
			btn.classList.toggle( 'is-open', ! panel.hidden );
			btn.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
			if ( ! panel.hidden ) {
				// Read the page again every time: an alt text saved a minute ago
				// must show as done without reloading anything.
				elencoSeo();
			}
		} );

		// --- Everything else search engines read, listed in the panel ---------
		//
		// The whole SEO side has to be visible at once, in a fixed order, and
		// every image needs a link that opens the visual editor on that exact
		// picture — otherwise you are hunting for it.
		//
		// The header fields above come from the database; these two lists come
		// from the page itself, so they always match what is really on screen.

		function estratto( t, n ) {
			t = ( t || '' ).replace( /\s+/g, ' ' ).trim();
			return t.length > n ? t.slice( 0, n - 1 ) + '…' : t;
		}

		// Jumps to the element and opens the right editor on it.
		function vaiA( el, apri ) {
			panel.hidden = true;
			btn.classList.remove( 'is-open' );
			btn.setAttribute( 'aria-expanded', 'false' );
			commit( function () {
				el.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				el.classList.add( 'trrocket-ve-seoflash' );
				setTimeout( function () { el.classList.remove( 'trrocket-ve-seoflash' ); }, 1400 );
				apri();
			} );
		}

		function bollino( fatto ) {
			var b = document.createElement( 'span' );
			b.className = 'trrocket-ve-seobadge ' + ( fatto ? 'is-done' : 'is-todo' );
			b.textContent = fatto ? ( VE.i18n.seoDone || '' ) : ( VE.i18n.seoTodo || '' );
			return b;
		}

		function bottone( testo, onclick ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'trrocket-ve-seogo';
			b.textContent = testo;
			b.addEventListener( 'click', onclick );
			return b;
		}

		function elencoSeo() {
			var visibile = function ( e ) { return e.offsetParent !== null; };

			// --- images ------------------------------------------------------
			var imgs = Array.prototype.filter.call(
				document.querySelectorAll( 'img.trrocket-ed-img, img.trrocket-ed-attr' ),
				visibile
			);
			var boxI  = panel.querySelector( '.trrocket-ve-seoimgs' );
			var rigaI = panel.querySelector( '.trrocket-ve-seoimgrow' );
			if ( boxI && rigaI ) {
				boxI.textContent = '';
				imgs.forEach( function ( im ) {
					var attrs   = ( im.getAttribute( 'data-trr-attrs' ) || '' ).split( ',' ).filter( Boolean );
					var haAlt   = attrs.indexOf( 'alt' ) !== -1;
					var haFoto  = im.classList.contains( 'trrocket-ed-img' );
					var riga    = document.createElement( 'div' );
					riga.className = 'trrocket-ve-seoitem';

					var mini = document.createElement( 'img' );
					mini.className = 'trrocket-ve-seothumb';
					mini.src = im.getAttribute( 'src' ) || '';
					mini.alt = '';
					riga.appendChild( mini );

					var testo = document.createElement( 'div' );
					testo.className = 'trrocket-ve-seoitxt';
					var alt = document.createElement( 'span' );
					alt.className = 'trrocket-ve-seoalt';
					var valAlt = trimEdge( im.getAttribute( 'alt' ) || '' );
					alt.textContent = valAlt ? estratto( valAlt, 60 ) : ( VE.i18n.seoNoAlt || '' );
					if ( ! valAlt ) {
						alt.classList.add( 'is-empty' );
					}
					testo.appendChild( alt );
					if ( haAlt ) {
						testo.appendChild( bollino( ! unitTodo( { type: 'attr', el: im, attr: 'alt' } ) ) );
					}
					riga.appendChild( testo );

					var azioni = document.createElement( 'div' );
					azioni.className = 'trrocket-ve-seoacts';
					if ( haAlt ) {
						azioni.appendChild( bottone( VE.i18n.seoEditAlt || 'Alt', function () {
							vaiA( im, function () { openAttrPop( im, 'alt' ); } );
						} ) );
					}
					if ( haFoto ) {
						azioni.appendChild( bottone( VE.i18n.seoEditImg || '', function () {
							vaiA( im, function () { apriImg( im ); } );
						} ) );
					}
					riga.appendChild( azioni );
					boxI.appendChild( riga );
				} );
				rigaI.hidden = ( 0 === imgs.length );
				var contaI = rigaI.querySelector( '.trrocket-ve-seocount' );
				if ( contaI ) {
					contaI.textContent = imgs.length ? '(' + imgs.length + ')' : '';
				}
			}

			// --- the other attributes, grouped and always in the same order ---
			var ordine = [ 'title', 'placeholder', 'aria-label' ];
			var gruppi = {};
			Array.prototype.filter.call( document.querySelectorAll( '.trrocket-ed-attr' ), visibile )
				.forEach( function ( el ) {
					( el.getAttribute( 'data-trr-attrs' ) || '' ).split( ',' ).filter( Boolean ).forEach( function ( a ) {
						// The alt text of an image is listed with its photo above.
						if ( 'alt' === a && 'IMG' === el.tagName ) {
							return;
						}
						if ( ! gruppi[ a ] ) {
							gruppi[ a ] = [];
							if ( ordine.indexOf( a ) === -1 ) {
								ordine.push( a );
							}
						}
						gruppi[ a ].push( el );
					} );
				} );

			var boxA  = panel.querySelector( '.trrocket-ve-seoattrs' );
			var rigaA = panel.querySelector( '.trrocket-ve-seoattrrow' );
			if ( ! boxA || ! rigaA ) {
				return;
			}
			boxA.textContent = '';
			var quanti = 0;
			ordine.forEach( function ( a ) {
				var lista = gruppi[ a ];
				if ( ! lista || ! lista.length ) {
					return;
				}
				var tit = document.createElement( 'div' );
				tit.className = 'trrocket-ve-seogroup';
				tit.textContent = attrLabel( a ) + ' (' + lista.length + ')';
				boxA.appendChild( tit );
				lista.forEach( function ( el ) {
					quanti++;
					var riga = document.createElement( 'div' );
					riga.className = 'trrocket-ve-seoitem';
					var testo = document.createElement( 'div' );
					testo.className = 'trrocket-ve-seoitxt';
					var t = document.createElement( 'span' );
					var val = trimEdge( el.getAttribute( a ) || '' );
					t.textContent = val ? estratto( val, 60 ) : ( VE.i18n.seoNoText || '' );
					if ( ! val ) {
						t.classList.add( 'is-empty' );
					}
					testo.appendChild( t );
					testo.appendChild( bollino( ! unitTodo( { type: 'attr', el: el, attr: a } ) ) );
					riga.appendChild( testo );
					var azioni = document.createElement( 'div' );
					azioni.className = 'trrocket-ve-seoacts';
					azioni.appendChild( bottone( VE.i18n.seoEdit || '', function () {
						vaiA( el, function () { openAttrPop( el, a ); } );
					} ) );
					riga.appendChild( azioni );
					boxA.appendChild( riga );
				} );
			} );
			rigaA.hidden = ( 0 === quanti );
			var contaA = rigaA.querySelector( '.trrocket-ve-seocount' );
			if ( contaA ) {
				contaA.textContent = quanti ? '(' + quanti + ')' : '';
			}
		}
		// Close on a genuine outside click.
		document.addEventListener( 'click', function ( e ) {
			if ( panel.hidden ) {
				return;
			}
			if ( e.target.closest( '#trrocket-ve-seopanel' ) || e.target.closest( '.trrocket-ve-seobtn' ) ) {
				return;
			}
			panel.hidden = true;
			btn.classList.remove( 'is-open' );
			btn.setAttribute( 'aria-expanded', 'false' );
		} );
		var saveBtn = panel.querySelector( '.trrocket-ve-seosave' );
		var out     = panel.querySelector( '.trrocket-ve-vismsgout' );
		var slugIn  = panel.querySelector( '.trrocket-ve-seoslug' );
		var slug0   = slugIn ? slugIn.value : '';
		saveBtn.addEventListener( 'click', function () {
			var items = [];
			Array.prototype.forEach.call( panel.querySelectorAll( '.trrocket-ve-seotr' ), function ( t ) {
				items.push( { src: t.getAttribute( 'data-src' ), translation: t.value, ctx: t.getAttribute( 'data-ctx' ) } );
			} );
			out.textContent = VE.i18n.working;
			setBusy( saveBtn, true );
			api( 'trrocket_ve_seo', {
				post:  panel.getAttribute( 'data-post' ),
				slug:  slugIn ? slugIn.value : '',
				items: JSON.stringify( items )
			}, function ( res ) {
				setBusy( saveBtn, false );
				var ok = res && res.success;
				out.textContent = ok ? VE.i18n.saved : '⚠';
				if ( ok && slugIn ) {
					// The server echoes the slug as saved (it may have been sanitised).
					var saved = ( res.data && 'string' === typeof res.data.slug ) ? res.data.slug : slugIn.value;
					slugIn.value = saved;
					if ( saved !== slug0 ) {
						// The page address changed with the slug — follow it, staying in
						// the editor (replace the last path segment with the new slug).
						// On the language home ("/de/") there is no slug segment: the
						// last segment IS the language code, so stay put.
						var parts = window.location.pathname.split( '/' );
						var i     = parts.length - 1;
						while ( i >= 0 && '' === parts[ i ] ) {
							i--;
						}
						if ( i >= 0 && parts[ i ] !== VE.lang ) {
							parts[ i ]      = saved || slugIn.getAttribute( 'placeholder' ) || parts[ i ];
							window.location = parts.join( '/' ) + window.location.search;
							return;
						}
					}
				}
				setTimeout( function () { out.textContent = ''; }, 1800 );
			} );
		} );
	}() );

	// Independent-copy controls in the top bar: detach the page into an editable copy,
	// or pause that copy back to runtime string translation.
	document.addEventListener( 'click', function ( e ) {
		var create = e.target.closest ? e.target.closest( '.trrocket-ve-copy-create' ) : null;
		if ( create ) {
			e.preventDefault();
			if ( create.classList.contains( 'is-busy' ) ) {
				return;
			}
			setBusy( create, true );
			api( 'trrocket_ve_copy_create', { post: create.getAttribute( 'data-source' ) }, function ( res ) {
				if ( res && res.success && res.data && res.data.edit ) {
					window.location = res.data.edit; // open the new copy in WordPress to edit.
				} else {
					setBusy( create, false );
					window.alert( ( res && res.data ) ? String( res.data ) : '⚠' );
				}
			} );
			return;
		}
		var revert = e.target.closest ? e.target.closest( '.trrocket-ve-copy-revert' ) : null;
		if ( revert ) {
			e.preventDefault();
			if ( revert.classList.contains( 'is-busy' ) ) {
				return;
			}
			if ( ! window.confirm( VE.i18n.copyRevertConfirm || 'Back to strings?' ) ) {
				return;
			}
			setBusy( revert, true );
			api( 'trrocket_ve_copy_pause', { post: revert.getAttribute( 'data-source' ) }, function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					setBusy( revert, false );
				}
			} );
			return;
		}
		var del = e.target.closest ? e.target.closest( '.trrocket-ve-copy-delete' ) : null;
		if ( del ) {
			e.preventDefault();
			if ( del.classList.contains( 'is-busy' ) ) {
				return;
			}
			if ( ! window.confirm( VE.i18n.copyDeleteConfirm || 'Delete this copy?' ) ) {
				return;
			}
			setBusy( del, true );
			api( 'trrocket_ve_copy_delete', { post: del.getAttribute( 'data-source' ) }, function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					setBusy( del, false );
				}
			} );
		}
	} );

	// "Translate page" — bulk-translate the whole page in one place: one-click auto with the
	// AI provider or free Google, or copy → translate anywhere → paste back. Built entirely
	// here (no server markup) and reusing the block serialize/parse + save endpoints.
	( function initBulkPanel() {
		var bar = document.getElementById( 'trrocket-ve-bar' );
		if ( ! bar ) { return; }

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'trrocket-ve-bulkbtn';
		btn.setAttribute( 'aria-expanded', 'false' );
		btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 0 20 15.3 15.3 0 0 1 0-20z"/></svg><span>' + esc( VE.i18n.bulkBtn || 'Translate page' ) + '</span>';
		var hint = bar.querySelector( '.trrocket-ve-hint' );
		var exit = bar.querySelector( '.trrocket-ve-exit' );
		if ( hint ) { bar.insertBefore( btn, hint.nextSibling ); } else if ( exit ) { bar.insertBefore( btn, exit ); } else { bar.appendChild( btn ); }

		var panel = document.createElement( 'div' );
		panel.id = 'trrocket-ve-bulkpanel';
		panel.setAttribute( 'translate', 'no' );
		panel.hidden = true;
		var autoBtns = '';
		if ( VE.aiReady ) { autoBtns += '<button type="button" class="trrocket-ve-bulk-ai">' + esc( VE.i18n.bulkAi || 'Auto-translate with AI' ) + '</button>'; }
		if ( VE.autoG ) { autoBtns += '<button type="button" class="trrocket-ve-bulk-gt">' + esc( VE.i18n.bulkGt || 'Auto-translate with Google' ) + '</button>'; }
		// Il traduttore dentro Chrome ed Edge: se questo browser ce l'ha si sa solo
		// qui, a video. Il pulsante nasce nascosto e si mostra da solo piu' sotto.
		autoBtns += '<button type="button" class="trrocket-ve-bulk-br" hidden title="' + esc( VE.i18n.bulkBrowserTip || '' ) + '">'
			+ esc( VE.i18n.bulkBrowser || 'Translate everything in this browser' ) + '</button>';
		// La nota resta sempre nel markup: serve quando non c'e' ne' AI ne' browser,
		// e va nascosta appena una delle due strade esiste.
		var autoBlock = '<div class="trrocket-ve-bulk-auto">' + autoBtns + '</div>'
			+ '<p class="trrocket-ve-bulk-note"' + ( ( VE.aiReady || VE.autoG ) ? ' hidden' : '' ) + '>'
			+ esc( VE.i18n.bulkNoProvider || '' ) + '</p>';
		panel.innerHTML =
			'<div class="trrocket-ve-bulk-head">' + esc( VE.i18n.bulkHead || 'Translate the whole page' ) + '</div>' +
			'<div class="trrocket-ve-bulk-desc">' + esc( VE.i18n.bulkDesc || '' ) + '</div>' +
			'<label class="trrocket-ve-bulk-opt"><input type="checkbox" class="trrocket-ve-bulk-html"> <span>' + esc( VE.i18n.bulkHtml || 'Keep HTML tags' ) + '</span></label>' +
			autoBlock +
			'<div class="trrocket-ve-bulk-prog" hidden><span class="trrocket-ve-bulk-progbar"><span class="trrocket-ve-bulk-progfill"></span></span><span class="trrocket-ve-bulk-progtxt"></span></div>' +
			'<div class="trrocket-ve-bulk-sub">' + esc( VE.i18n.bulkPasteHead || 'Or: copy → translate → paste back' ) + '</div>' +
			'<div class="trrocket-ve-bulk-step"><span>' + esc( VE.i18n.bulkStep1 || '1. Copy the source' ) + '</span><button type="button" class="trrocket-ve-bulk-copy">' + esc( VE.i18n.bulkCopy || 'Copy' ) + '</button><a class="trrocket-ve-bulk-gopen" href="https://translate.google.com/" target="_blank" rel="noopener">Google Translate ↗</a></div>' +
			'<textarea class="trrocket-ve-bulk-srctext" readonly rows="4"></textarea>' +
			'<div class="trrocket-ve-bulk-airow"><span>' + esc( VE.i18n.bulkAiHead || 'Or translate it in ChatGPT or Gemini' ) + '</span>' +
				'<button type="button" class="trrocket-ve-bulk-info" aria-expanded="false" aria-label="' + esc( VE.i18n.bulkAiInfoBtn || '' ) + '" title="' + esc( VE.i18n.bulkAiInfoBtn || '' ) + '">i</button></div>' +
			'<p class="trrocket-ve-bulk-infotext" hidden>' + esc( VE.i18n.bulkAiInfo || '' ) + '</p>' +
			'<div class="trrocket-ve-bulk-aibtns"><button type="button" class="trrocket-ve-bulk-aicopy">' + esc( VE.i18n.bulkAiCopy || 'Copy with a translation prompt' ) + '</button>' +
				'<a class="trrocket-ve-bulk-chat" href="https://chatgpt.com/" target="_blank" rel="noopener">ChatGPT ↗</a>' +
				'<a class="trrocket-ve-bulk-chat" href="https://gemini.google.com/app" target="_blank" rel="noopener">Gemini ↗</a></div>' +
			'<div class="trrocket-ve-bulk-step"><span>' + esc( VE.i18n.bulkStep2 || '2. Paste the translation back' ) + '</span></div>' +
			'<textarea class="trrocket-ve-bulk-dsttext" rows="4" placeholder="' + esc( VE.i18n.bulkPastePh || 'Paste here…' ) + '"></textarea>' +
			'<div class="trrocket-ve-bulk-foot"><span class="trrocket-ve-bulk-msg"></span><button type="button" class="trrocket-ve-bulk-apply">' + esc( VE.i18n.bulkApply || 'Apply translation' ) + '</button></div>';
		document.body.appendChild( panel );

		var htmlCb   = panel.querySelector( '.trrocket-ve-bulk-html' );
		var srcTa    = panel.querySelector( '.trrocket-ve-bulk-srctext' );
		var gopen    = panel.querySelector( '.trrocket-ve-bulk-gopen' );
		var dstTa    = panel.querySelector( '.trrocket-ve-bulk-dsttext' );
		var msg      = panel.querySelector( '.trrocket-ve-bulk-msg' );
		var progBox  = panel.querySelector( '.trrocket-ve-bulk-prog' );
		var progFill = panel.querySelector( '.trrocket-ve-bulk-progfill' );
		var progTxt  = panel.querySelector( '.trrocket-ve-bulk-progtxt' );

		function mode() { return ( htmlCb && htmlCb.checked ) ? 'html' : 'markers'; }
		function unitSource( u, m ) {
			if ( 'block' === u.type ) { return serializeBlock( u.block, true, m ).str; }
			if ( 'attr' === u.type ) { return decode( u.el.getAttribute( 'data-trr-a-' + attrKey( u.attr ) ) || '' ); }
			return decode( u.el.getAttribute( 'data-trr-src' ) || '' );
		}
		function applyFrag( span, v ) {
			if ( '' === v ) { span.textContent = decode( span.getAttribute( 'data-trr-src' ) ); span.classList.add( 'trrocket-ed-untr' ); return; }
			var o = span.textContent, lead = ( o.match( /^\s*/ ) || [ '' ] )[ 0 ], trail = ( o.match( /\s*$/ ) || [ '' ] )[ 0 ];
			span.textContent = lead + v + trail; span.classList.remove( 'trrocket-ed-untr' );
		}
		function applyUnit( u, translated, m, cb ) {
			translated = ( null == translated ) ? '' : String( translated );
			if ( 'block' === u.type ) {
				var srcSer = serializeBlock( u.block, true, m );
				var map = parseBlock( translated, srcSer.slots );
				if ( ! map ) { cb( false ); return; }
				var items = map.map( function ( mm ) { return { src: decode( mm.span.getAttribute( 'data-trr-src' ) ), translation: trimEdge( mm.value ) }; } );
				api( 'trrocket_ve_save_block', { items: JSON.stringify( items ) }, function ( res ) {
					if ( res && res.success ) { map.forEach( function ( mm ) { applyFrag( mm.span, trimEdge( mm.value ) ); } ); cb( true ); } else { cb( false ); }
				} );
			} else if ( 'attr' === u.type ) {
				var asrc = decode( u.el.getAttribute( 'data-trr-a-' + attrKey( u.attr ) ) || '' );
				api( 'trrocket_ve_save', { src: asrc, translation: translated, type: 'attribute', ctx: u.attr }, function ( res ) {
					if ( res && res.success ) { u.el.setAttribute( u.attr, translated ); cb( true ); } else { cb( false ); }
				} );
			} else {
				var s = decode( u.el.getAttribute( 'data-trr-src' ) );
				api( 'trrocket_ve_save', { src: s, translation: trimEdge( translated ) }, function ( res ) {
					if ( res && res.success ) { applyFrag( u.el, trimEdge( translated ) ); cb( true ); } else { cb( false ); }
				} );
			}
		}
		function setProg( d, total ) { progBox.hidden = false; var p = total ? Math.round( d / total * 100 ) : 100; progFill.style.width = p + '%'; progTxt.textContent = d + ' / ' + total; }
		function finish( ok, fail ) { progBox.hidden = true; msg.textContent = ( VE.i18n.bulkDone || '%d translated' ).replace( '%d', String( ok ) ) + ( fail ? ( ' · ' + fail + ' ⚠' ) : '' ); try { updateProgress(); } catch ( e ) {} }

		var running = false;
		function auto( action ) {
			if ( running ) { return; }
			var m = mode();
			var list = navUnits().filter( unitTodo );
			if ( ! list.length ) { msg.textContent = VE.i18n.bulkNothing || ''; return; }
			running = true; msg.textContent = VE.i18n.bulkRun || ''; setProg( 0, list.length );
			var i = 0, ok = 0, fail = 0;
			( function step() {
				if ( i >= list.length ) { running = false; finish( ok, fail ); return; }
				var u = list[ i++ ];
				api( action, { src: unitSource( u, m ) }, function ( res ) {
					if ( res && res.success && res.data && res.data.translation ) {
						applyUnit( u, res.data.translation, m, function ( good ) { good ? ok++ : fail++; setProg( i, list.length ); step(); } );
					} else { fail++; setProg( i, list.length ); step(); }
				} );
			}() );
		}

		var pUnits = null, pMode = 'markers';
		function buildSource() {
			pMode  = mode();
			pUnits = navUnits();
			// Numbered lines ("1. …", "2. …") so the pasted-back translation maps by number,
			// not just by position — survives a translator reordering or dropping blank lines.
			srcTa.value = pUnits.map( function ( u, i ) { return ( i + 1 ) + '. ' + unitSource( u, pMode ).replace( /\r?\n/g, ' ' ); } ).join( '\n' );
			// Point the "Google Translate" link at the actual text (opens it pre-filled via GET).
			// Cap the length so the URL stays valid; longer pages use Copy + paste back.
			if ( gopen ) {
				var t = srcTa.value;
				gopen.href = gtUrl( t.length > 4500 ? t.slice( 0, 4500 ) : t );
			}
		}
		function applyPaste() {
			if ( running ) { return; }
			if ( ! pUnits ) { buildSource(); }
			// Re-map each pasted line to its unit by the leading "N." number (robust if lines
			// were reordered or blanks dropped); un-numbered lines fall back to their order.
			var raw = dstTa.value.replace( /\r/g, '' ).split( '\n' );
			var trans = new Array( pUnits.length ), leftovers = [];
			raw.forEach( function ( line ) {
				// ChatGPT e Gemini rispondono dentro un blocco di codice (lo chiede il
				// prompt, perche' e' l'unico modo di copiare i numeri intatti): se
				// l'utente copia il messaggio intero, le righe ``` arrivano qui. Non
				// sono traduzioni, e senza numero finirebbero su una riga a caso.
				if ( /^\s*```/.test( line ) ) { return; }
				var mm = line.match( /^\s*(\d+)[.)]\s?([\s\S]*)$/ );
				if ( mm ) {
					var k = parseInt( mm[ 1 ], 10 ) - 1;
					if ( k >= 0 && k < pUnits.length && null == trans[ k ] ) { trans[ k ] = mm[ 2 ]; return; }
				}
				if ( '' !== trimEdge( line ) ) { leftovers.push( line ); }
			} );
			for ( var j = 0; j < pUnits.length; j++ ) { if ( null == trans[ j ] && leftovers.length ) { trans[ j ] = leftovers.shift(); } }
			var todo = [];
			for ( var k2 = 0; k2 < pUnits.length; k2++ ) { if ( null != trans[ k2 ] && '' !== trimEdge( trans[ k2 ] ) ) { todo.push( { u: pUnits[ k2 ], line: trans[ k2 ] } ); } }
			if ( ! todo.length ) { return; }
			if ( todo.length !== pUnits.length ) { msg.textContent = VE.i18n.bulkCountWarn || ''; }
			running = true; setProg( 0, todo.length );
			var i = 0, ok = 0, fail = 0;
			( function step() {
				if ( i >= todo.length ) { running = false; finish( ok, fail ); return; }
				var it = todo[ i ]; i++;
				applyUnit( it.u, it.line, pMode, function ( good ) { good ? ok++ : fail++; setProg( i, todo.length ); step(); } );
			}() );
		}

		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault(); e.stopPropagation();
			chiudiAltriPannelli( 'trrocket-ve-bulkpanel' );
			panel.hidden = ! panel.hidden;
			btn.classList.toggle( 'is-open', ! panel.hidden );
			btn.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
			if ( ! panel.hidden ) { msg.textContent = ''; buildSource(); }
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( panel.hidden ) { return; }
			if ( e.target.closest( '#trrocket-ve-bulkpanel' ) || e.target.closest( '.trrocket-ve-bulkbtn' ) ) { return; }
			panel.hidden = true; btn.classList.remove( 'is-open' ); btn.setAttribute( 'aria-expanded', 'false' );
		} );
		if ( htmlCb ) {
			htmlCb.addEventListener( 'change', function () {
				buildSource();
				// Scroll the source box to the first formatted line so the effect of the
				// toggle is visible (plain lines look identical in both modes, which made
				// the checkbox seem broken when the top of the list had no formatting).
				var lines = srcTa.value.split( '\n' );
				var re = ( 'html' === pMode ) ? /<[a-z!\/]/i : /\[\d+\]/;
				for ( var li = 0; li < lines.length; li++ ) {
					if ( re.test( lines[ li ] ) ) {
						srcTa.scrollTop = Math.max( 0, ( srcTa.scrollHeight / lines.length ) * li - 8 );
						break;
					}
				}
			} );
		}
		var aiBtn = panel.querySelector( '.trrocket-ve-bulk-ai' );
		if ( aiBtn ) { aiBtn.addEventListener( 'click', function () { auto( 'trrocket_ve_ai' ); } ); }
		var gtBtn = panel.querySelector( '.trrocket-ve-bulk-gt' );
		if ( gtBtn ) { gtBtn.addEventListener( 'click', function () { auto( 'trrocket_ve_gt' ); } ); }

		// --- il traduttore del browser -------------------------------------
		// Stessa passeggiata di auto(), ma la traduzione la fa il dispositivo:
		// niente chiave, niente costo, e il testo non esce dal computer.
		var brBtn = panel.querySelector( '.trrocket-ve-bulk-br' );
		var noteEl = panel.querySelector( '.trrocket-ve-bulk-note' );

		function autoBrowser() {
			if ( running ) { return; }
			var m = mode();
			var list = navUnits().filter( unitTodo );
			if ( ! list.length ) { msg.textContent = VE.i18n.bulkNothing || ''; return; }
			running = true;
			msg.textContent = VE.i18n.bulkBrowserDl || VE.i18n.bulkRun || '';
			prendiTraduttore().then( function () {
				msg.textContent = VE.i18n.bulkRun || '';
				setProg( 0, list.length );
				var i = 0, ok = 0, fail = 0;
				( function step() {
					if ( i >= list.length ) { running = false; finish( ok, fail ); return; }
					var u = list[ i++ ];
					traduciColBrowser( unitSource( u, m ) ).then( function ( out ) {
						applyUnit( u, out, m, function ( good ) { good ? ok++ : fail++; setProg( i, list.length ); step(); } );
					} ).catch( function () { fail++; setProg( i, list.length ); step(); } );
				}() );
			} ).catch( function () {
				running = false;
				msg.textContent = VE.i18n.bulkBrowserErr || '';
			} );
		}

		if ( brBtn ) {
			statoBrowser().then( function ( stato ) {
				if ( 'no' === stato ) { return; }
				brBtn.hidden = false;
				if ( 'telefono' === stato ) { spegniPerTelefono( brBtn ); return; }
				// C'e' una strada automatica: la nota che dice "metti una chiave"
				// non ha piu' ragione di stare li'.
				if ( noteEl ) { noteEl.hidden = true; }
			} );
			brBtn.addEventListener( 'click', autoBrowser );
		}
		var copyBtn = panel.querySelector( '.trrocket-ve-bulk-copy' );
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				srcTa.select();
				try { document.execCommand( 'copy' ); } catch ( e ) {}
				if ( navigator.clipboard ) { try { navigator.clipboard.writeText( srcTa.value ); } catch ( e2 ) {} }
				var t = copyBtn.textContent; copyBtn.textContent = VE.i18n.bulkCopied || 'Copied'; setTimeout( function () { copyBtn.textContent = t; }, 1500 );
			} );
		}
		var applyBtn = panel.querySelector( '.trrocket-ve-bulk-apply' );
		if ( applyBtn ) { applyBtn.addEventListener( 'click', applyPaste ); }

		// Il testo da copiare si seleziona tutto a ogni clic: e' in sola lettura,
		// serve solo a essere copiato. La casella dove si incolla invece si
		// seleziona al primo clic (cosi' un secondo incolla sostituisce il primo),
		// ma i clic successivi mettono il cursore dove si clicca, per correggere.
		if ( srcTa ) {
			srcTa.addEventListener( 'focus', function () { srcTa.select(); } );
			srcTa.addEventListener( 'click', function () { srcTa.select(); } );
			// Cliccando su un testo gia' selezionato Chrome annulla la selezione
			// al rilascio del tasto, DOPO il click: senza questo il secondo clic
			// lasciava il cursore invece del testo selezionato (visto in prova).
			srcTa.addEventListener( 'mouseup', function ( e ) { e.preventDefault(); } );
		}
		if ( dstTa ) {
			var appenaEntrato = false;
			dstTa.addEventListener( 'focus', function () { dstTa.select(); appenaEntrato = true; } );
			dstTa.addEventListener( 'mouseup', function ( e ) { if ( appenaEntrato ) { e.preventDefault(); appenaEntrato = false; } } );
			dstTa.addEventListener( 'blur', function () { appenaEntrato = false; } );
		}

		// Il prompt per ChatGPT e Gemini. Resta in inglese apposta: e' la lingua in
		// cui questi modelli seguono meglio le istruzioni, qualunque sia la lingua
		// del sito. Chiede la risposta in un blocco di codice perche' solo cosi' il
		// suo pulsante "copia" restituisce i numeri: copiando un elenco numerato
		// gia' impaginato, il browser i numeri li lascia indietro.
		function promptAI() {
			var da = VE.pSrc || VE.srclang || '', a = VE.pDst || VE.lang || '';
			var keep  = ( VE.pKeep && VE.pKeep.length ) ? VE.pKeep : [];
			var gloss = [];
			if ( VE.pGloss && 'object' === typeof VE.pGloss ) {
				Object.keys( VE.pGloss ).forEach( function ( k ) { gloss.push( '"' + k + '" → "' + VE.pGloss[ k ] + '"' ); } );
			}
			// Le regole a trattini, non numerate: numerate come le righe della pagina,
			// un modello potrebbe prenderle per testo da tradurre.
			var r = [];
			function regola( t ) { r.push( '- ' + t ); }
			r.push( 'Translate the numbered lines below from ' + da + ' into ' + a + '. They are the texts of one web page, in page order.' );
			r.push( '' );
			r.push( 'Your answer will be put back into the website automatically, so follow these rules exactly:' );
			regola( 'Every line starts with its number, a dot and a space, like "12. ". Give every line back with the same number, one line each. Do not merge, split, reorder, add or drop lines, even very short ones.' );
			if ( 'html' === pMode ) {
				regola( 'Leave every HTML tag and attribute exactly as it is. Translate only the visible text between the tags.' );
			} else {
				regola( 'Markers in square brackets such as [1] [2] [3] stand for formatting: bold, links, line breaks. Keep every marker exactly as written, all of them and in the same order, around the words they belong to.' );
			}
			regola( 'Keep URLs, e-mail addresses, numbers, prices and code unchanged.' );
			if ( keep.length ) { regola( 'Never translate these names and words, write them exactly as they are: ' + keep.join( ', ' ) + '.' ); }
			if ( gloss.length ) { regola( 'Always translate these terms this way: ' + gloss.join( '; ' ) + '.' ); }
			regola( 'Put the whole answer inside one code block, with nothing before or after it: no introduction, no notes.' );
			if ( VE.pGuide ) {
				r.push( '' );
				r.push( 'House style from the site owner. Follow it, but the rules above always win:' );
				r.push( '"""' );
				r.push( VE.pGuide );
				r.push( '"""' );
			}
			r.push( '' );
			r.push( 'Lines to translate:' );
			r.push( '' );
			r.push( srcTa.value );
			return r.join( '\n' );
		}
		function copiaVecchioModo( testo ) {
			var t = document.createElement( 'textarea' );
			t.value = testo; t.setAttribute( 'readonly', '' );
			t.style.position = 'fixed'; t.style.opacity = '0'; t.style.left = '-9999px';
			document.body.appendChild( t ); t.select();
			try { document.execCommand( 'copy' ); } catch ( e ) {}
			document.body.removeChild( t );
		}
		function copia( testo ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( testo ).catch( function () { copiaVecchioModo( testo ); } );
			} else {
				copiaVecchioModo( testo );
			}
		}
		var aiCopyBtn = panel.querySelector( '.trrocket-ve-bulk-aicopy' );
		function copiaPrompt() {
			if ( ! pUnits ) { buildSource(); }
			copia( promptAI() );
			if ( aiCopyBtn ) {
				var t = aiCopyBtn.getAttribute( 'data-testo' ) || aiCopyBtn.textContent;
				aiCopyBtn.setAttribute( 'data-testo', t );
				aiCopyBtn.textContent = VE.i18n.bulkAiCopied || 'Copied';
				setTimeout( function () { aiCopyBtn.textContent = t; }, 2500 );
			}
		}
		if ( aiCopyBtn ) { aiCopyBtn.addEventListener( 'click', copiaPrompt ); }
		// I link alle due chat copiano anche il prompt: un clic, e nella scheda
		// che si apre basta incollare. Il link si apre comunque, non lo fermiamo.
		[].forEach.call( panel.querySelectorAll( '.trrocket-ve-bulk-chat' ), function ( l ) {
			l.addEventListener( 'click', copiaPrompt );
		} );
		var infoBtn = panel.querySelector( '.trrocket-ve-bulk-info' );
		var infoTxt = panel.querySelector( '.trrocket-ve-bulk-infotext' );
		if ( infoBtn && infoTxt ) {
			infoBtn.addEventListener( 'click', function () {
				infoTxt.hidden = ! infoTxt.hidden;
				infoBtn.setAttribute( 'aria-expanded', infoTxt.hidden ? 'false' : 'true' );
			} );
		}
	}() );

	updateProgress();
}() );
