/* TranslateRocket — switcher customizer live preview. */
( function () {
	'use strict';

	var preview = document.getElementById( 'trr-sw-preview' );
	if ( ! preview ) {
		return;
	}
	// The live-preview <style> element is created here (not printed in PHP) so the
	// plugin never outputs a raw <style> tag in its markup.
	var live = document.getElementById( 'trr-sw-live' );
	if ( ! live ) {
		live = document.createElement( 'style' );
		live.id = 'trr-sw-live';
		document.head.appendChild( live );
	}

	function el( id ) {
		return document.getElementById( id );
	}
	function val( id ) {
		var e = el( id );
		return e ? e.value.trim() : '';
	}
	function setVal( id, v ) {
		var e = el( id );
		if ( e ) {
			e.value = v;
		}
	}

	/* Colours (live). */
	function update() {
		var text = val( 'sw_text_color' );
		var bg   = val( 'sw_bg_color' );
		var bd   = val( 'sw_border_color' );
		// La casella "nessun bordo" vince sul colore, come nel front-end.
		var noBd = !!( document.getElementById( 'sw_no_border' ) || {} ).checked;
		if ( noBd ) { bd = ''; }
		var hv   = val( 'sw_hover_color' );
		var r    = val( 'sw_radius' );

		var bgop  = el( 'sw_bg_opacity' ) ? parseInt( el( 'sw_bg_opacity' ).value, 10 ) : 100;
		var bgEff = ( bgop < 100 ) ? ( toRgba( bg || '#ffffff', bgop / 100 ) || bg ) : bg;
		var box = '';
		if ( bgEff ) { box += 'background:' + bgEff + ';'; }
		if ( noBd )   { box += 'border:0;'; }
		else if ( bd ) { box += 'border:1px solid ' + bd + ';'; }
		if ( r )  { box += 'border-radius:' + ( /^\d+$/.test( r ) ? r + 'px' : r ) + ';'; }

		var sh      = el( 'sw_shadow' ) ? el( 'sw_shadow' ).value : 'none';
		var shadows = { light: '0 1px 3px rgba(0,0,0,.12)', medium: '0 2px 8px rgba(0,0,0,.15)', strong: '0 4px 16px rgba(0,0,0,.2)' };
		if ( shadows[ sh ] ) { box += 'box-shadow:' + shadows[ sh ] + ';'; }

		var w    = el( 'sw_width' ) ? el( 'sw_width' ).value : 'auto';
		var wpx  = val( 'sw_width_px' );
		var wmap = { small: '120px', medium: '180px', large: '260px' };
		var wv   = '';
		if ( 'custom' === w && wpx ) { wv = /^\d+$/.test( wpx ) ? wpx + 'px' : wpx; }
		else if ( wmap[ w ] ) { wv = wmap[ w ]; }
		if ( wv ) { box += 'min-width:' + wv + ';'; }

		var out = '';
		// Mirror css_for() on the front-end exactly: the whole box (bg / border /
		// radius / shadow) goes to the inline switcher AND the dropdown toggle, the
		// menu carries the same surface with bottom-only rounding — so the toggle and
		// the menu items always share one size, shape and colour in the preview too.
		if ( box )  { out += '#trr-sw-preview .trrocket-switcher,#trr-sw-preview .trrocket-switcher-select,#trr-sw-preview .trrocket-dd-toggle{' + box + 'padding:6px 12px;}'; }
		var rpx   = r ? ( /^\d+$/.test( r ) ? r + 'px' : r ) : '';
		var ddbox = '';
		if ( bgEff ) { ddbox += 'background:' + bgEff + ';'; }
		if ( bd )    { ddbox += 'border:1px solid ' + bd + ';border-top:0;'; }
		if ( rpx )   { ddbox += 'border-radius:0 0 ' + rpx + ' ' + rpx + ';'; }
		if ( shadows[ sh ] ) { ddbox += 'box-shadow:' + shadows[ sh ] + ';'; }
		if ( ddbox ) { out += '#trr-sw-preview .trrocket-dd-menu{' + ddbox + '}'; }
		// Gli angoli in basso si appiattiscono SOLO da aperto, come fa il sito
		// (.trrocket-dd.is-open .trrocket-dd-toggle). Prima erano piatti sempre,
		// perche' nell'anteprima il menu era sempre aperto: da chiuso si vedeva un
		// pulsante con due angoli quadrati che sul sito non esistono.
		if ( rpx )   { out += '#trr-sw-preview .trr-prev-dd.is-open .trrocket-dd-toggle{border-bottom-left-radius:0;border-bottom-right-radius:0;}'; }
		if ( text ) { out += '#trr-sw-preview .trrocket-switcher a,#trr-sw-preview .trrocket-switcher .trrocket-current span,#trr-sw-preview .trrocket-switcher-select,#trr-sw-preview .trrocket-dd-toggle,#trr-sw-preview .trrocket-dd-menu a{color:' + text + ';}'; }
		if ( hv )   { out += '#trr-sw-preview .trrocket-switcher a:hover,#trr-sw-preview .trrocket-dd-menu a:hover,#trr-sw-preview .trrocket-dd-toggle:hover{color:' + hv + ';}'; }

		function fv( id ) {
			return el( id ) ? ( parseInt( el( id ).value, 10 ) || 0 ) : 0;
		}
		var tl = fv( 'sw_flag_tl' ), tr = fv( 'sw_flag_tr' ), br = fv( 'sw_flag_br' ), bl = fv( 'sw_flag_bl' );
		if ( tl || tr || br || bl ) {
			out += '#trr-sw-preview .trrocket-flag-svg,#trr-sw-preview .trrocket-flag-wrap svg{border-radius:' + tl + 'px ' + tr + 'px ' + br + 'px ' + bl + 'px;overflow:hidden;}';
		}

		var hoverbg = val( 'sw_hover_bg' );
		if ( hoverbg ) {
			out += '#trr-sw-preview .trrocket-dd-menu a:hover,#trr-sw-preview .trrocket-switcher a:hover,#trr-sw-preview .trr-prev-ul a:hover,#trr-sw-preview .trrocket-dd-toggle:hover{background:' + hoverbg + ';}';
		}
		var fontmap = { system: '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif', arial: 'Arial,Helvetica,sans-serif', helvetica: '"Helvetica Neue",Helvetica,Arial,sans-serif', segoe: '"Segoe UI",Roboto,Helvetica,Arial,sans-serif', verdana: 'Verdana,Geneva,sans-serif', tahoma: 'Tahoma,Geneva,sans-serif', trebuchet: '"Trebuchet MS",Helvetica,sans-serif', lucida: '"Lucida Sans Unicode","Lucida Grande",sans-serif', century: '"Century Gothic","Apple Gothic",sans-serif', impact: 'Impact,Charcoal,sans-serif', georgia: 'Georgia,"Times New Roman",serif', times: '"Times New Roman",Times,serif', palatino: '"Palatino Linotype","Book Antiqua",Palatino,serif', garamond: 'Garamond,Baskerville,"Times New Roman",serif', courier: '"Courier New",Courier,monospace', consolas: 'Consolas,Monaco,"Courier New",monospace' };
		var font = el( 'sw_font_family' ) ? el( 'sw_font_family' ).value : '';
		if ( fontmap[ font ] ) {
			out += '#trr-sw-preview .trrocket-switcher,#trr-sw-preview .trrocket-dd-toggle,#trr-sw-preview .trrocket-dd-menu{font-family:' + fontmap[ font ] + ';}';
		}

		var anim = el( 'sw_animation' ) ? el( 'sw_animation' ).value : 'fade';
		if ( [ 'fade', 'slide', 'scale' ].indexOf( anim ) !== -1 ) {
			// Solo su .is-open: col :hover l'animazione ripartiva ogni volta che il
			// mouse passava sopra al menu gia' aperto.
			out += '#trr-sw-preview .trr-prev-dd.is-open .trrocket-dd-menu{animation:trr-anim-' + anim + ' .2s ease;}';
		}
		var hfx   = el( 'sw_hover_fx' ) ? el( 'sw_hover_fx' ).value : 'none';
		var lk    = '#trr-sw-preview .trrocket-switcher a,#trr-sw-preview .trrocket-dd-menu a';
		var lkh   = '#trr-sw-preview .trrocket-switcher a:hover,#trr-sw-preview .trrocket-dd-menu a:hover';
		if ( 'lift' === hfx ) {
			out += lk + '{transition:transform .15s ease;}' + lkh + '{transform:translateY(-2px);}';
		} else if ( 'grow' === hfx ) {
			out += lk + '{transition:transform .15s ease;display:inline-block;}' + lkh + '{transform:scale(1.08);}';
		} else if ( 'underline' === hfx ) {
			out += lk + '{background-image:linear-gradient(currentColor,currentColor);background-position:0 100%;background-repeat:no-repeat;background-size:0 2px;transition:background-size .2s ease;}' + lkh + '{background-size:100% 2px;}';
		}

		live.textContent = out;
		// La larghezza, il bordo o il testo appena cambiati spostano il fondo del
		// menu: lo spazio va rimisurato dopo che il browser ha riapplicato il CSS.
		setTimeout( spazio, 0 );
	}

	/* -----------------------------------------------------------------------
	   Il menu a tendina si apre e si chiude col clic, come sul sito.
	   Prima il menu era inchiodato aperto da uno stile scritto nel markup: si
	   vedevano i colori, ma non si poteva provare l'apertura — che e' meta' di
	   quello che fa un selettore a tendina.
	   Ora il markup e il CSS sono gli stessi del front-end, quindi qui basta
	   ripetere quello che fa switcher.js sul sito.
	   ----------------------------------------------------------------------- */
	var dd = preview.querySelector( '.trr-prev-dd' );

	// Il menu aperto e' fuori dal flusso (position:absolute), come sul sito:
	// senza fargli spazio uscirebbe dal riquadro tratteggiato e finirebbe sopra
	// ai comandi qui sotto.
	function spazio() {
		if ( ! dd || ! dd.classList.contains( 'is-open' ) ) {
			preview.style.minHeight = '';
			return;
		}
		var menu = dd.querySelector( '.trrocket-dd-menu' );
		if ( ! menu ) { return; }
		var p = preview.getBoundingClientRect();
		var m = menu.getBoundingClientRect();
		preview.style.minHeight = Math.ceil( m.bottom - p.top + 24 ) + 'px';
	}

	function apri( aperto ) {
		if ( ! dd ) { return; }
		dd.classList.toggle( 'is-open', aperto );
		var t = dd.querySelector( '.trrocket-dd-overlay .trrocket-dd-toggle' );
		if ( t ) { t.setAttribute( 'aria-expanded', aperto ? 'true' : 'false' ); }
		spazio();
	}

	preview.addEventListener( 'click', function ( e ) {
		if ( ! dd || ! e.target.closest ) { return; }
		// Solo il pulsante vero: quello dentro all'ancora e' nascosto e serve
		// unicamente a dare la larghezza.
		var t = e.target.closest( '.trrocket-dd-overlay .trrocket-dd-toggle' );
		if ( t && dd.contains( t ) ) {
			apri( ! dd.classList.contains( 'is-open' ) );
			return;
		}
		// Una voce del menu non porta da nessuna parte qui dentro: si chiude, come
		// farebbe la pagina cambiando lingua.
		if ( e.target.closest( '.trrocket-dd-menu a' ) ) { apri( false ); }
	} );

	// Fuori dall'anteprima e Escape chiudono, esattamente come sul sito.
	document.addEventListener( 'click', function ( e ) {
		if ( dd && ! preview.contains( e.target ) ) { apri( false ); }
	} );
	document.addEventListener( 'keyup', function ( e ) {
		if ( 'Escape' === e.key ) { apri( false ); }
	} );

	/* -----------------------------------------------------------------------
	   Tre opzioni valgono SOLO per la tendina: come si apre, la freccia e
	   l'animazione di apertura. Con "inline" o "list" non fanno niente, e un
	   comando che non fa niente ma sembra acceso e' peggio di uno spento: si
	   prova, non succede nulla e si pensa che sia rotto.
	   ----------------------------------------------------------------------- */
	var SOLO_TENDINA = [ 'sw_dd_trigger', 'sw_dd_caret', 'sw_animation' ];

	function soloTendina() {
		var tendina = el( 'sw_type' ) && 'dropdown' === el( 'sw_type' ).value;
		SOLO_TENDINA.forEach( function ( id ) {
			var e = el( id );
			if ( ! e ) { return; }
			e.disabled = ! tendina;
			var riga = e.closest ? e.closest( 'tr' ) : null;
			if ( riga ) { riga.classList.toggle( 'trr-off', ! tendina ); }

			// ⚠️ Un campo disabilitato NON viene inviato dal modulo: salvando con la
			// tendina spenta, chi legge il POST non troverebbe niente e rimetterebbe
			// il valore di partenza (dd_trigger torna "click", la freccia si
			// spegne, l'animazione torna "fade"). Cioe' cambiare tipo cancellerebbe
			// di nascosto delle impostazioni gia' scelte. Finche' e' spento il
			// valore viaggia in un campo nascosto.
			var cella = e.parentNode;
			if ( ! cella ) { return; }
			var eco = cella.querySelector( 'input.trr-eco[data-per="' + id + '"]' );
			var serve = ! tendina && ( 'checkbox' !== e.type || e.checked );
			if ( serve && ! eco ) {
				eco = document.createElement( 'input' );
				eco.type = 'hidden';
				eco.className = 'trr-eco';
				eco.setAttribute( 'data-per', id );
				eco.name = e.name;
				cella.appendChild( eco );
			}
			if ( serve ) { eco.value = ( 'checkbox' === e.type ) ? '1' : e.value; }
			else if ( eco && eco.parentNode ) { eco.parentNode.removeChild( eco ); }
		} );
		// Niente scritte aggiunte qui: le tre righe dicono gia' nel loro testo che
		// valgono solo per la tendina, e quel testo passa dalle traduzioni. Una
		// frase inglese scritta a mano nel JavaScript non si tradurrebbe.
	}

	/* Layout / show / names (live). */
	function layout() {
		var type = el( 'sw_type' ) ? el( 'sw_type' ).value : 'inline';
		var show = el( 'sw_show' ) ? el( 'sw_show' ).value : 'both';
		var cur  = el( 'sw_current' ) ? el( 'sw_current' ).value : 'show';
		var eng  = el( 'sw_english' ) ? el( 'sw_english' ).checked : false;

		var ul = preview.querySelector( '.trr-prev-ul' );

		if ( ul ) {
			ul.style.display = ( 'dropdown' === type ) ? 'none' : '';
			if ( 'list' === type ) {
				ul.classList.add( 'trrocket-vertical' );
			} else {
				ul.classList.remove( 'trrocket-vertical' );
			}
		}
		if ( dd ) {
			var eraTendina = 'inline-block' === dd.style.display;
			dd.style.display = ( 'dropdown' === type ) ? 'inline-block' : 'none';
			// Scegliendo "dropdown" si apre da solo, se no i colori del menu non si
			// vedrebbero finche' non ci si clicca sopra. Poi comanda il clic.
			if ( 'dropdown' === type && ! eraTendina ) { apri( true ); }
			if ( 'dropdown' !== type ) { apri( false ); }
		}

		Array.prototype.forEach.call( preview.querySelectorAll( '.trrocket-flag-wrap' ), function ( e ) {
			e.style.display = ( 'name' === show ) ? 'none' : '';
		} );
		Array.prototype.forEach.call( preview.querySelectorAll( '.trrocket-name' ), function ( e ) {
			e.style.display = ( 'flag' === show ) ? 'none' : '';
			e.textContent   = eng ? ( e.getAttribute( 'data-en' ) || '' ) : ( e.getAttribute( 'data-native' ) || '' );
		} );
		Array.prototype.forEach.call( preview.querySelectorAll( '.trr-prev-cur' ), function ( e ) {
			e.style.display = ( 'hide' === cur ) ? 'none' : '';
		} );
		var caretEl = preview.querySelector( '.trr-prev-dd .trrocket-dd-caret' );
		if ( caretEl ) {
			caretEl.style.display = ( el( 'sw_dd_caret' ) && ! el( 'sw_dd_caret' ).checked ) ? 'none' : '';
		}
		soloTendina();
		applyTextStyle();
	}

	/* Bold text + flag/name divider (live). */
	var divMap = { pipe: '|', bullet: '•', dot: '·', slash: '/', dash: '–' };
	function applyTextStyle() {
		var bold = el( 'sw_font_weight' ) && el( 'sw_font_weight' ).checked;
		Array.prototype.forEach.call( preview.querySelectorAll( '.trrocket-name' ), function ( n ) {
			n.style.fontWeight = bold ? '700' : '';
		} );
		Array.prototype.forEach.call( preview.querySelectorAll( '.trr-sep' ), function ( s ) {
			if ( s.parentNode ) { s.parentNode.removeChild( s ); }
		} );
		var dv   = el( 'sw_divider' ) ? el( 'sw_divider' ).value : 'none';
		var show = el( 'sw_show' ) ? el( 'sw_show' ).value : 'both';
		if ( divMap[ dv ] && 'both' === show ) {
			Array.prototype.forEach.call( preview.querySelectorAll( '.trrocket-flag-wrap' ), function ( f ) {
				var sep = document.createElement( 'span' );
				sep.className = 'trr-sep';
				sep.textContent = divMap[ dv ];
				sep.style.opacity = '0.45';
				sep.style.margin = '0 3px';
				f.parentNode.insertBefore( sep, f.nextSibling );
			} );
		}
	}

	function toRgba( c, a ) {
		c = ( c || '' ).trim();
		var m = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.exec( c );
		if ( ! m ) { return ''; }
		var h = m[ 1 ];
		if ( 3 === h.length ) { h = h[ 0 ] + h[ 0 ] + h[ 1 ] + h[ 1 ] + h[ 2 ] + h[ 2 ]; }
		return 'rgba(' + parseInt( h.substr( 0, 2 ), 16 ) + ',' + parseInt( h.substr( 2, 2 ), 16 ) + ',' + parseInt( h.substr( 4, 2 ), 16 ) + ',' + a + ')';
	}

	function syncSwatches() {
		Array.prototype.forEach.call( document.querySelectorAll( '.trr-color-pick' ), function ( pick ) {
			var txt = el( pick.getAttribute( 'data-for' ) );
			if ( txt && /^#[0-9a-fA-F]{6}$/.test( txt.value ) ) { pick.value = txt.value; }
		} );
	}

	/* Presets carry all their values as data-* attributes (rendered in PHP). */
	Array.prototype.forEach.call( document.querySelectorAll( '.trr-preset' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var d = this.dataset || {};
			setVal( 'sw_text_color', d.text || '' );
			setVal( 'sw_bg_color', d.bg || '' );
			setVal( 'sw_border_color', d.border || '' );
			setVal( 'sw_hover_color', d.hover || '' );
			setVal( 'sw_hover_bg', d.hoverbg || '' );
			setVal( 'sw_radius', d.radius || '' );
			if ( el( 'sw_shadow' ) ) { el( 'sw_shadow' ).value = d.shadow || 'none'; }
			if ( el( 'sw_bg_opacity' ) ) {
				el( 'sw_bg_opacity' ).value = d.opacity || '100';
				var ov = el( 'sw_bg_opacity_val' );
				if ( ov ) { ov.textContent = ( d.opacity || '100' ) + '%'; }
			}
			// Presets that ship an animation / hover effect carry it too.
			if ( d.anim && el( 'sw_animation' ) ) { el( 'sw_animation' ).value = d.anim; }
			if ( d.hoverfx && el( 'sw_hover_fx' ) ) { el( 'sw_hover_fx' ).value = d.hoverfx; }
			syncSwatches();
			update();
		} );
	} );

	[ 'sw_text_color', 'sw_bg_color', 'sw_border_color', 'sw_hover_color', 'sw_hover_bg', 'sw_radius', 'sw_width_px' ].forEach( function ( id ) {
		var e = el( id );
		if ( e ) {
			e.addEventListener( 'input', update );
		}
	} );

	/* Native colour swatch kept in sync with its text field. */
	Array.prototype.forEach.call( document.querySelectorAll( '.trr-color-pick' ), function ( pick ) {
		var txt = el( pick.getAttribute( 'data-for' ) );
		if ( ! txt ) {
			return;
		}
		pick.addEventListener( 'input', function () {
			txt.value = pick.value;
			update();
		} );
		txt.addEventListener( 'input', function () {
			if ( /^#[0-9a-fA-F]{6}$/.test( txt.value ) ) {
				pick.value = txt.value;
			}
		} );
	} );
	[ 'sw_type', 'sw_show', 'sw_current', 'sw_dd_caret' ].forEach( function ( id ) {
		var e = el( id );
		if ( e ) {
			e.addEventListener( 'change', layout );
		}
	} );
	var engEl = el( 'sw_english' );
	if ( engEl ) {
		engEl.addEventListener( 'change', layout );
	}
	// "No border" changes the box, not the layout: same refresh as the colours.
	var noBdEl = el( 'sw_no_border' );
	if ( noBdEl ) {
		noBdEl.addEventListener( 'change', update );
	}
	// Se si cambia una di queste mentre e' spenta non si puo' (e' disabilitata),
	// ma il valore puo' cambiare da un preset: l'eco si riallinea.
	SOLO_TENDINA.forEach( function ( id ) {
		var e = el( id );
		if ( e ) { e.addEventListener( 'change', soloTendina ); }
	} );
	var shEl = el( 'sw_shadow' );
	if ( shEl ) {
		shEl.addEventListener( 'change', update );
	}
	[ 'sw_animation', 'sw_hover_fx' ].forEach( function ( id ) {
		var e = el( id );
		if ( e ) {
			e.addEventListener( 'change', function () {
				update();
				// Si rigioca l'animazione di apertura, cosi' la scelta si vede.
				// Prima richiudeva da sola dopo 900ms: adesso che il menu si apre e
				// si chiude davvero, richiuderlo vorrebbe dire chiudere quello che
				// l'utente aveva aperto apposta. Resta aperto.
				if ( dd ) {
					dd.classList.remove( 'is-open' );
					void dd.offsetWidth;
					apri( true );
				}
			} );
		}
	} );
	var fontEl = el( 'sw_font_family' );
	if ( fontEl ) {
		fontEl.addEventListener( 'change', update );
	}
	var fwEl = el( 'sw_font_weight' );
	if ( fwEl ) {
		fwEl.addEventListener( 'change', applyTextStyle );
	}
	var dvEl = el( 'sw_divider' );
	if ( dvEl ) {
		dvEl.addEventListener( 'change', applyTextStyle );
	}
	var opEl = el( 'sw_bg_opacity' );
	if ( opEl ) {
		opEl.addEventListener( 'input', function () {
			var v = el( 'sw_bg_opacity_val' );
			if ( v ) { v.textContent = opEl.value + '%'; }
			update();
		} );
	}
	var cornerIds = [ 'sw_flag_tl', 'sw_flag_tr', 'sw_flag_br', 'sw_flag_bl' ];
	var linkBtn   = el( 'sw_flag_link' );
	var linked    = true;
	function setLinkUI() {
		if ( linkBtn ) {
			linkBtn.classList.toggle( 'is-linked', linked );
			linkBtn.setAttribute( 'aria-pressed', linked ? 'true' : 'false' );
		}
	}
	if ( linkBtn ) {
		linkBtn.addEventListener( 'click', function () {
			linked = ! linked;
			setLinkUI();
			if ( linked && el( 'sw_flag_tl' ) ) {
				var v = el( 'sw_flag_tl' ).value;
				cornerIds.forEach( function ( c ) { if ( el( c ) ) { el( c ).value = v; } } );
				update();
			}
		} );
	}
	cornerIds.forEach( function ( id ) {
		var e = el( id );
		if ( ! e ) {
			return;
		}
		e.addEventListener( 'input', function () {
			if ( linked ) {
				var v = this.value;
				cornerIds.forEach( function ( c ) { if ( c !== id && el( c ) ) { el( c ).value = v; } } );
			}
			update();
		} );
	} );
	setLinkUI();
	var wEl = el( 'sw_width' );
	if ( wEl ) {
		wEl.addEventListener( 'change', update );
	}

	var fpEl = document.getElementById( 'sw_placement' );
	function toggleCustomPos() {
		var v  = fpEl ? fpEl.value : 'manual';
		var cp = document.getElementById( 'sw-custom-pos' );
		var mh = document.getElementById( 'sw-manual-hint' );
		if ( cp ) { cp.style.display = ( 'custom' === v ) ? '' : 'none'; }
		if ( mh ) { mh.style.display = ( 'manual' === v ) ? '' : 'none'; }
	}
	if ( fpEl ) {
		fpEl.addEventListener( 'change', toggleCustomPos );
	}
	toggleCustomPos();

	/* Re-draw the preset thumbnails to match the selected layout type, so each
	   preset previews how it will actually look (dropdown / inline / list). */
	// SVG flags so both preview rows look identical on every OS (emoji flags render
	// as "IT"/"GB" letter pairs on Windows, which looked inconsistent).
	var FLAG_IT = '<svg class="trrocket-flag-svg" width="18" height="12" viewBox="0 0 30 20"><rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#009246"/><rect x="20" width="10" height="20" fill="#ce2b37"/></svg>';
	var FLAG_EN = '<svg class="trrocket-flag-svg" width="18" height="12" viewBox="0 0 30 20"><rect width="30" height="20" fill="#012169"/><path d="M0,0 L30,20 M30,0 L0,20" stroke="#fff" stroke-width="4"/><path d="M0,0 L30,20 M30,0 L0,20" stroke="#C8102E" stroke-width="1.6"/><rect x="12" width="6" height="20" fill="#fff"/><rect y="7" width="30" height="6" fill="#fff"/><rect x="13.2" width="3.6" height="20" fill="#C8102E"/><rect y="8.2" width="30" height="3.6" fill="#C8102E"/></svg>';
	function renderPresets() {
		var type = el( 'sw_type' ) ? el( 'sw_type' ).value : 'inline';
		var it = '<span style="display:inline-flex;align-items:center;gap:5px;white-space:nowrap">' + FLAG_IT + ' Italiano</span>';
		var en = '<span style="display:inline-flex;align-items:center;gap:5px;white-space:nowrap">' + FLAG_EN + ' English</span>';
		Array.prototype.forEach.call( document.querySelectorAll( '.trr-preset-chip' ), function ( chip ) {
			if ( 'dropdown' === type ) {
				chip.style.display = 'inline-flex';
				chip.style.flexDirection = 'row';
				chip.style.alignItems = 'center';
				chip.style.gap = '8px';
				chip.innerHTML = it + '<span style="opacity:.55;margin-left:auto">▾</span>';
			} else if ( 'list' === type ) {
				chip.style.display = 'flex';
				chip.style.flexDirection = 'column';
				chip.style.alignItems = 'flex-start';
				chip.style.gap = '5px';
				chip.innerHTML = it + en;
			} else {
				chip.style.display = 'inline-flex';
				chip.style.flexDirection = 'row';
				chip.style.alignItems = 'center';
				chip.style.gap = '12px';
				chip.innerHTML = it + en;
			}
		} );
	}
	var typeElP = el( 'sw_type' );
	if ( typeElP ) {
		typeElP.addEventListener( 'change', renderPresets );
	}

	/* Preview background swatches — check the switcher against different backdrops. */
	var bgRow = document.querySelector( '.trr-sw-bgrow' );
	if ( bgRow ) {
		var imgBg = 'linear-gradient(135deg,#667eea,#764ba2 42%,#f093fb)';
		var setBg = function ( v, srcEl ) {
			preview.style.background = ( 'img' === v ) ? imgBg : v;
			Array.prototype.forEach.call( bgRow.querySelectorAll( '.trr-sw-bg' ), function ( b ) { b.classList.remove( 'is-on' ); } );
			if ( srcEl && srcEl.classList ) { srcEl.classList.add( 'is-on' ); }
		};
		Array.prototype.forEach.call( bgRow.querySelectorAll( '.trr-sw-bg' ), function ( b ) {
			b.addEventListener( 'click', function () { setBg( b.getAttribute( 'data-bg' ), b ); } );
		} );
		var bgPick = bgRow.querySelector( '.trr-sw-bg-pick' );
		if ( bgPick ) {
			bgPick.addEventListener( 'input', function () { setBg( bgPick.value, null ); } );
		}
	}

	update();
	layout();
	renderPresets();
}() );
