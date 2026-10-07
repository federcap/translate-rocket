/* TranslateRocket — switcher customizer live preview. */
( function () {
	'use strict';

	var preview = document.getElementById( 'trr-sw-preview' );
	if ( ! preview ) {
		return;
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

	/* -----------------------------------------------------------------------
	   The preview is the site's own switcher (Switcher::preview_parts()), in a
	   frame so the dashboard's styles cannot touch it. Every change in the form
	   asks the server to draw it again with the values not saved yet: the same
	   code as the site, so it cannot drift from it any more (it used to be a copy
	   drawn here by hand: names cut short in the dropdown, layout changes shown
	   only after saving — Federico, 24/9/2026).
	   ----------------------------------------------------------------------- */
	var CFG   = window.TRRocketSwPreview || {};
	var frame = el( 'trr-sw-frame' );
	var form  = document.querySelector( 'input[name="sw_profile"]' );
	form      = form ? form.form : null;
	var view  = 'desktop';
	var bgNow = '';
	var timer = null;
	var seq   = 0;
	var openAfter = false;

	function doc() {
		try { return frame && frame.contentDocument; } catch ( e ) { return null; }
	}

	// The frame is as tall as the switcher, and grows while the dropdown is open.
	function fit() {
		var d = doc();
		if ( ! d || ! d.body ) { return; }
		var root = d.getElementById( 'trr-root' );
		var h    = root ? root.getBoundingClientRect().bottom : 0;
		Array.prototype.forEach.call( d.querySelectorAll( '.trrocket-dd-menu' ), function ( m ) {
			var r = m.getBoundingClientRect();
			if ( r.height > 0 && 'hidden' !== d.defaultView.getComputedStyle( m ).visibility ) { h = Math.max( h, r.bottom ); }
		} );
		frame.style.height = Math.max( 90, Math.ceil( h + 24 ) ) + 'px';
	}

	function wire() {
		var d = doc();
		if ( ! d || ! d.body || d.body.getAttribute( 'data-trr-wired' ) ) { return; }
		d.body.setAttribute( 'data-trr-wired', '1' );
		var later = function () { fit(); setTimeout( fit, 260 ); };
		d.addEventListener( 'click', later );
		d.addEventListener( 'mouseover', later );
		d.addEventListener( 'mouseout', later );
		d.addEventListener( 'keyup', later );
		if ( bgNow ) { d.body.style.background = bgNow; }
		fit();
	}

	function openDropdown() {
		var d = doc();
		var t = d && d.querySelector( '.trrocket-dd-toggle' );
		var w = d && d.querySelector( '.trrocket-dd' );
		if ( t && w && ! w.classList.contains( 'is-open' ) ) { t.click(); }
		setTimeout( fit, 0 );
		setTimeout( fit, 260 );
	}

	// The scrolling switcher sets itself up when its page loads: it gets a fresh page.
	function reload( parts ) {
		var d = doc();
		var live = d.getElementById( 'trr-live' );
		var root = d.getElementById( 'trr-root' );
		if ( live ) { live.textContent = parts.css || ''; }
		root.innerHTML = parts.html;
		root.setAttribute( 'data-type', parts.type || '' );
		d.body.removeAttribute( 'data-trr-wired' );
		d.body.style.background = '';
		frame.srcdoc = '<!DOCTYPE html>' + d.documentElement.outerHTML;
	}

	function draw( parts, wasOpen ) {
		var d = doc();
		if ( ! d || ! d.getElementById( 'trr-root' ) ) { return; }
		var root     = d.getElementById( 'trr-root' );
		var live     = d.getElementById( 'trr-live' );
		var prevType = root.getAttribute( 'data-type' ) || '';
		if ( 'scroll' === parts.type && parts.html ) {
			reload( parts );
			return;
		}
		if ( live ) { live.textContent = parts.css || ''; }
		if ( parts.html ) {
			root.innerHTML = parts.html;
		} else {
			root.innerHTML = '<p class="trr-empty"></p>';
			root.firstChild.textContent = CFG.empty || '';
		}
		root.setAttribute( 'data-type', parts.type || '' );
		// Switching to the dropdown opens it, or its menu would stay unseen; after that
		// the clicks decide. An open menu stays open while colours change.
		if ( 'dropdown' === parts.type && ( wasOpen || openAfter || 'dropdown' !== prevType ) ) {
			openDropdown();
		}
		openAfter = false;
		fit();
	}

	function refresh() {
		if ( ! form || ! frame || ! CFG.ajaxurl ) { return; }
		var d       = doc();
		var wasOpen = !! ( d && d.querySelector( '.trrocket-dd.is-open' ) );
		var fd      = new FormData( form );
		// Without the save nonce: the preview only draws, it never saves.
		fd.delete( 'trrocket_switcher_nonce' );
		fd.delete( '_wp_http_referer' );
		fd.append( 'action', 'trrocket_sw_preview' );
		fd.append( '_ajax_nonce', CFG.nonce );
		fd.append( 'view', view );
		var mine = ++seq;
		fetch( CFG.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				// Only the answer to the latest change counts.
				if ( mine !== seq || ! r || ! r.success || ! r.data ) { return; }
				draw( r.data, wasOpen );
			} )
			.catch( function () {} );
	}

	// Colours and sliders fire at every step: one request when the hand stops.
	function update() {
		clearTimeout( timer );
		timer = setTimeout( refresh, 180 );
	}

	if ( frame ) {
		frame.addEventListener( 'load', wire );
		wire();
	}
	if ( form ) {
		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.trr-sw-view' ), function ( b ) {
		b.addEventListener( 'click', function () {
			view = b.getAttribute( 'data-view' ) || 'desktop';
			Array.prototype.forEach.call( document.querySelectorAll( '.trr-sw-view' ), function ( o ) {
				var on = o === b;
				o.classList.toggle( 'is-on', on );
				o.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			preview.classList.toggle( 'is-phone', 'phone' === view );
			refresh();
		} );
	} );

	/* -----------------------------------------------------------------------
	   Tre opzioni valgono SOLO per la tendina: come si apre, la freccia e
	   l'animazione di apertura. Con "inline" o "list" non fanno niente, e un
	   comando che non fa niente ma sembra acceso e' peggio di uno spento: si
	   prova, non succede nulla e si pensa che sia rotto.
	   ----------------------------------------------------------------------- */
	var SOLO_TENDINA = [ 'sw_dd_trigger', 'sw_dd_caret', 'sw_animation' ];

	/* 7/10/2026 (Federico styled «header» and the menu kept showing Default): one click moves this
	   profile into the header menu and saves. */
	Array.prototype.forEach.call( document.querySelectorAll( '.trr-sw-take-menu' ), function ( b ) {
		b.addEventListener( 'click', function () {
			var sel = el( 'sw_placement' );
			if ( ! sel || ! form ) { return; }
			sel.value = 'menu';
			sel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			if ( form.requestSubmit ) { form.requestSubmit(); } else { form.submit(); }
		} );
	} );

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

	/* Layout / show / names: the preview follows by itself (see update()). */
	function layout() {
		soloTendina();
		update();
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
			// 8/10/2026 (Federico: «clicking a preset changes nothing»): values set by script fire no
			// event, so the «your site, live» card (header menu / spot) never redrew. Tell the form.
			if ( this.form ) { this.form.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }
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
				// Si rigioca l'animazione di apertura, cosi' la scelta si vede:
				// il menu ridisegnato si apre da solo.
				openAfter = true;
			} );
		}
	} );
	var fontEl = el( 'sw_font_family' );
	if ( fontEl ) {
		fontEl.addEventListener( 'change', update );
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
		var mp = document.getElementById( 'sw-menu-pick' );
		if ( mp ) { mp.style.display = ( 'menu' === v ) ? '' : 'none'; }
		var sp = document.getElementById( 'sw-spot-pick' );
		if ( sp ) { sp.style.display = ( 'spot' === v ) ? '' : 'none'; }
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
			bgNow = ( 'img' === v ) ? imgBg : v;
			var d = doc();
			if ( d && d.body ) { d.body.style.background = bgNow; }
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

	soloTendina();
	renderPresets();

	// Changing profile, adding one, deleting one or saving reloads the page, and the browser
	// went back to the very top: you had to scroll down again every time (Federico,
	// 24/9/2026). Remember where the profile bar was on screen and put it back there.
	var bar = el( 'trr-sw-profiles' );
	var KEY = 'trrSwPos';
	function remember() {
		if ( ! bar ) { return; }
		try { sessionStorage.setItem( KEY, String( Math.round( bar.getBoundingClientRect().top ) ) ); } catch ( e ) {}
	}
	if ( bar ) {
		Array.prototype.forEach.call( bar.querySelectorAll( 'a.button' ), function ( a ) {
			a.addEventListener( 'click', remember );
		} );
	}
	Array.prototype.forEach.call( document.querySelectorAll( '.trrocket-wrap form[method="post"]' ), function ( f ) {
		f.addEventListener( 'submit', function ( e ) {
			// «Delete this profile?» answered No: nothing reloads, nothing to remember.
			if ( ! e.defaultPrevented ) { remember(); }
		} );
	} );
	var was = null;
	try { was = sessionStorage.getItem( KEY ); sessionStorage.removeItem( KEY ); } catch ( e ) {}
	if ( bar && null !== was && '' !== was && ! isNaN( +was ) ) {
		var go = function () {
			window.scrollTo( 0, Math.max( 0, window.pageYOffset + bar.getBoundingClientRect().top - ( +was ) ) );
		};
		go();
		// Once more after images and fonts, which can still change the height above it.
		window.addEventListener( 'load', go );
	}
}() );

/* 6/10/2026: «In a spot I choose on my page» — the picker opens the home page in a frame (or a tab),
   and sends back the chosen element and side. */
( function () {
	var open = document.getElementById( 'sw-spot-open' );
	var modal = document.getElementById( 'sw-picker' );
	var frame = document.getElementById( 'sw-picker-frame' );
	var text = document.getElementById( 'sw-spot-text' );
	var sel = document.getElementById( 'sw_spot_selector' );
	var where = document.getElementById( 'sw_spot_where' );
	var label = document.getElementById( 'sw_spot_label' );
	if ( ! open || ! modal || ! frame || ! text ) { return; }
	function show() {
		text.textContent = sel.value ? ( label.value || sel.value ) + ' — ' + text.getAttribute( 'data-' + ( 'start' === where.value ? 'start' : 'end' ) ) : text.getAttribute( 'data-none' );
	}
	show();
	function close() {
		modal.hidden = true;
		frame.removeAttribute( 'src' );
		document.body.style.overflow = '';
	}
	open.addEventListener( 'click', function () {
		frame.src = open.getAttribute( 'data-url' );
		modal.hidden = false;
		document.body.style.overflow = 'hidden';
	} );
	document.getElementById( 'sw-picker-close' ).addEventListener( 'click', close );
	Array.prototype.forEach.call( modal.querySelectorAll( '.trr-sw-picker-views button' ), function ( b ) {
		b.addEventListener( 'click', function () {
			frame.style.width = b.getAttribute( 'data-w' );
			Array.prototype.forEach.call( modal.querySelectorAll( '.trr-sw-picker-views button' ), function ( o ) { o.classList.toggle( 'is-on', o === b ); } );
		} );
	} );
	window.addEventListener( 'message', function ( e ) {
		if ( e.origin !== window.location.origin || ! e.data || ! e.data.trrocketSpot ) { return; }
		if ( ! e.data.cancel ) {
			sel.value = String( e.data.selector || '' ).slice( 0, 300 );
			where.value = 'start' === e.data.where ? 'start' : 'end';
			label.value = String( e.data.label || '' ).slice( 0, 120 );
			show();
		}
		close();
	} );
}() );

/* 6/10/2026 (Federico: «the live preview never matched what was published»): with the languages in the
   header menu (or in a spot) the theme draws them, so the preview is the real home page, drawn with the
   settings in the form before saving (Admin::ajax_switcher_try + Switcher::trying()). */
( function () {
	var card  = document.getElementById( 'trr-sw-site' );
	var frame = document.getElementById( 'trr-sw-site-frame' );
	var CFG   = window.TRRocketSwPreview || {};
	var input = document.querySelector( 'input[name="sw_profile"]' );
	var form  = input ? input.form : null;
	if ( ! card || ! frame || ! form || ! CFG.ajaxurl ) { return; }
	var wrap  = frame.parentNode;
	var frame2 = document.getElementById( 'trr-sw-site-frame2' );
	var foot   = document.getElementById( 'trr-sw-site-foot' );
	var footH  = document.getElementById( 'trr-sw-site-foot-h' );
	var phone = false;
	var timer = null;
	var seq   = 0;
	function where() {
		var p = form.querySelector( '[name="sw_placement"]' );
		return p ? p.value : '';
	}
	function size( f ) {
		if ( ! f ) { return; }
		var w = wrap.clientWidth || 800;
		if ( phone ) {
			var sp = Math.min( 1, w / 390 );
			f.style.width = '390px';
			f.style.height = Math.round( 560 / sp ) + 'px';
			f.style.transform = 'scale(' + sp + ')';
		} else {
			var s = w / 1280;
			f.style.width = '1280px';
			f.style.height = Math.round( 300 / s ) + 'px';
			f.style.transform = 'scale(' + s + ')';
		}
	}
	function fit() {
		size( frame );
		size( frame2 );
	}
	function footerOn() {
		var c = form.querySelector( '[name="sw_footer_row"]' );
		return !! ( c && c.checked );
	}
	// The second view shows the end of the same page, where the row of languages is.
	if ( frame2 ) {
		frame2.addEventListener( 'load', function () {
			try {
				var d = frame2.contentDocument;
				frame2.contentWindow.scrollTo( 0, Math.max( d.documentElement.scrollHeight, d.body.scrollHeight ) );
			} catch ( e ) {}
		} );
	}
	// 7/10/2026 (Federico: «spunta bianco nell'attesa»): la scheda dice che sta caricando
	frame.addEventListener( 'load', function () { wrap.classList.remove( 'is-loading' ); } );
	function load() {
		var on = 'menu' === where() || 'spot' === where() || footerOn();
		card.hidden = ! on;
		if ( on ) { wrap.classList.add( 'is-loading' ); }
		if ( foot ) { foot.hidden = ! footerOn(); }
		if ( footH ) { footH.hidden = ! footerOn(); }
		if ( ! on ) { return; }
		fit();
		var fd = new FormData( form );
		fd.delete( 'trrocket_switcher_nonce' );
		fd.delete( '_wp_http_referer' );
		fd.append( 'action', 'trrocket_sw_try' );
		fd.append( '_ajax_nonce', CFG.nonce );
		var mine = ++seq;
		fetch( CFG.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( r ) {
				if ( mine === seq && r && r.success && r.data && r.data.url ) {
					frame.src = r.data.url;
					if ( frame2 && footerOn() ) { frame2.src = r.data.url + '&trr_foot=1'; }
				}
			} )
			.catch( function () {} );
	}
	function later() {
		clearTimeout( timer );
		timer = setTimeout( load, 700 );
	}
	form.addEventListener( 'change', later );
	form.addEventListener( 'input', later );
	window.addEventListener( 'resize', fit );
	Array.prototype.forEach.call( document.querySelectorAll( '.trr-sw-view' ), function ( b ) {
		b.addEventListener( 'click', function () {
			phone = 'phone' === b.getAttribute( 'data-view' );
			card.classList.toggle( 'is-phone', phone );
			fit();
		} );
	} );
	load();
}() );
