/* TranslateRocket — in-editor translation panel (vanilla JS, AJAX). */
( function () {
	'use strict';

	var root = document.getElementById( 'trrocket-panel' );
	if ( ! root || typeof window.TRRocketPanel === 'undefined' ) {
		return;
	}

	var CFG   = window.TRRocketPanel;
	var post  = root.getAttribute( 'data-post' );
	var hasAI = root.getAttribute( 'data-ai' ) === '1';
	var langs = [];
	try {
		langs = JSON.parse( root.getAttribute( 'data-langs' ) || '[]' );
	} catch ( e ) {
		langs = [];
	}
	var editUrls = {};
	try {
		editUrls = JSON.parse( root.getAttribute( 'data-edit-urls' ) || '{}' );
	} catch ( e ) {
		editUrls = {};
	}
	var cur   = langs.length ? langs[ 0 ].code : '';
	var dirty = false;

	function t( key ) {
		return ( CFG.i18n && CFG.i18n[ key ] ) || key;
	}

	function esc( s ) {
		return ( s == null ? '' : String( s ) )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function typeKey( s ) {
		return s.type + ( s.ctx ? '/' + s.ctx : '' );
	}

	// Turn a phrase into a URL slug (lowercase, accent-free, dash-separated).
	function slugify( s ) {
		s = String( s == null ? '' : s ).replace( /<[^>]*>/g, ' ' ).replace( /&[a-z]+;/g, ' ' ).toLowerCase();
		if ( s.normalize ) {
			s = s.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ); // strip accents (à -> a).
		}
		return s.replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
	}

	// Grow a translation textarea to fit its content (no inner scrollbar).
	function autoGrow( el ) {
		if ( ! el || 'TEXTAREA' !== el.tagName ) { return; }
		el.style.height = 'auto';
		el.style.height = ( el.scrollHeight + 2 ) + 'px';
	}

	// TranslatePress-imported strings often carry inline HTML (<a>, <strong>, <br>…)
	// that Google Translate mangles. We swap each tag for a numbered marker [1],[2]…
	// before sending it over, then restore the tags from the same source when the
	// translated text is pasted back.
	function protectTags( text ) {
		var i = 0;
		return String( text == null ? '' : text ).replace( /<[^>]+>/g, function () {
			i++;
			return '[' + i + ']';
		} );
	}

	function restoreTags( translated, source ) {
		var tags = String( source == null ? '' : source ).match( /<[^>]+>/g );
		if ( ! tags || ! tags.length ) {
			return translated;
		}
		// Tolerate the spacing / bracket variants Google sometimes introduces.
		return String( translated == null ? '' : translated ).replace( /[\[\{【]\s*(\d+)\s*[\]\}】]/g, function ( m, n ) {
			var idx = parseInt( n, 10 ) - 1;
			return ( idx >= 0 && idx < tags.length ) ? tags[ idx ] : m;
		} );
	}

	// A per-phrase button that fills this row's field with Google's free translation
	// (server-side, same page). Inline HTML is protected as numbered markers and
	// restored on the way back.
	function gtBtn( text ) {
		return '<button type="button" class="trr-gt" data-src="' + esc( text ) + '" title="' +
			esc( t( 'gt_tip' ) ) + '"><span class="dashicons dashicons-translation"></span></button>';
	}

	// Google uses slightly different codes for a few languages (for the link).
	function gtCode( code ) {
		var m = { 'pt-br': 'pt', zh: 'zh-CN', 'zh-tw': 'zh-TW' };
		return m[ code ] || code;
	}

	// The lawful per-phrase helper: a link that opens Google Translate prefilled —
	// the admin translates on Google's own site (no automated endpoint).
	function gtLink( text ) {
		var url = 'https://translate.google.com/?sl=' + encodeURIComponent( gtCode( CFG.source || 'auto' ) ) +
			'&tl=' + encodeURIComponent( gtCode( cur ) ) +
			'&text=' + encodeURIComponent( protectTags( text ) ) + '&op=translate';
		return '<a class="trr-gt" href="' + esc( url ) + '" target="_blank" rel="noopener noreferrer" title="' +
			esc( t( 'gt_open' ) ) + '"><span class="dashicons dashicons-translation"></span></a>';
	}

	// One-click auto-fill only when the owner has opted in; otherwise the link.
	function gtCtl( text ) {
		return CFG.autoG ? gtBtn( text ) : gtLink( text );
	}

	function filterOptions( strings ) {
		var seen = {}, opts = '<option value="">' + esc( t( 'all_types' ) ) + '</option>';
		strings.forEach( function ( s ) {
			var k = typeKey( s );
			if ( ! seen[ k ] ) {
				seen[ k ] = 1;
				opts += '<option value="' + esc( k ) + '">' + esc( k ) + '</option>';
			}
		} );
		return opts;
	}

	function manualTable( title, rows, blockId ) {
		var h = '<div class="trr-block" data-block="' + blockId + '"><h4 class="trr-block-title">' +
			esc( title ) + ' <span class="trr-count">(' + rows.length + ')</span></h4>';
		if ( ! rows.length ) {
			return h + '<p class="description">—</p></div>';
		}
		h += '<table class="widefat striped trr-manual"><tbody>';
		rows.forEach( function ( s ) {
			var k = typeKey( s );
			h += '<tr class="trr-row' + ( s.done ? '' : ' trr-missing' ) + '" data-type="' + esc( k ) + '" data-src="' + esc( s.o ) + '">' +
				'<td style="width:50%">' + ( s.done ? '' : '<span class="trr-dot">●</span> ' ) + esc( s.o ) +
				'<div class="trr-type">' + esc( k ) + '</div></td>' +
				'<td class="trr-gt-cell">' + gtCtl( s.o ) + '</td>' +
				'<td><textarea class="large-text trr-tr" rows="1" data-id="' + s.id + '" data-src="' + esc( s.o ) + '">' + esc( s.t ) + '</textarea></td></tr>';
		} );
		return h + '</tbody></table></div>';
	}

	function info( text ) {
		return '<button type="button" class="trr-info" aria-label="info" data-info="' + esc( text ) + '"><span class="dashicons dashicons-info-outline"></span></button>';
	}

	function seoRow( label, infotext, s, seoKey ) {
		var missing = ! s || ! s.done;
		var seoAttr = seoKey ? ' data-seo="' + esc( seoKey ) + '"' : '';
		var right = s
			? '<textarea class="large-text trr-tr" rows="1" data-id="' + s.id + '" data-src="' + esc( s.o ) + '"' + seoAttr + '>' + esc( s.t ) + '</textarea>'
			: '<span class="description">' + esc( t( 'not_detected' ) ) + '</span>';
		return '<tr class="' + ( missing && s ? 'trr-missing' : '' ) + '"><td style="width:50%">' +
			( missing && s ? '<span class="trr-dot">●</span> ' : '' ) +
			'<strong>' + esc( label ) + '</strong> ' + info( infotext ) +
			( s ? '<div class="trr-type">' + esc( s.o ) + '</div>' : '' ) +
			'</td><td class="trr-gt-cell">' + ( s ? gtCtl( s.o ) : '' ) + '</td><td>' + right + '</td></tr>';
	}

	function seoSection( strings, slug ) {
		var title = null, desc = null;
		strings.forEach( function ( s ) {
			if ( s.type === 'meta' && s.ctx === 'title' ) {
				title = s;
			} else if ( s.type === 'meta' && s.ctx === 'description' ) {
				desc = s;
			}
		} );
		var h = '<div class="trr-block"><h4 class="trr-block-title">' + esc( t( 'seo_block' ) ) + '</h4>' +
			'<table class="widefat striped"><tbody>';
		h += '<tr><td style="width:50%"><strong>' + esc( t( 'slug_label' ) ) + '</strong> ' + info( t( 'slug_info' ) ) +
			'<div class="trr-type">/' + esc( cur ) + '/…</div></td>' +
			'<td class="trr-gt-cell"></td>' +
			'<td><input type="text" id="trr-slug" class="large-text" value="' + esc( slug == null ? '' : slug ) + '">' +
			' <button type="button" class="button button-small trr-slug-suggest" data-fb="' + esc( title ? ( title.t || title.o ) : '' ) + '">' + esc( t( 'slug_suggest' ) ) + '</button></td></tr>';
		h += seoRow( t( 'seo_title' ), t( 'seo_title_info' ), title, 'title' );
		h += seoRow( t( 'seo_desc' ), t( 'seo_desc_info' ), desc, 'desc' );
		h += '</tbody></table><p class="description">' + esc( t( 'kw_note' ) ) + ' ' + info( t( 'kw_info' ) ) + '</p></div>';
		return h;
	}

	function applyFilter() {
		var sel      = document.getElementById( 'trr-filter' );
		var val      = sel ? sel.value : '';
		var missEl   = document.getElementById( 'trr-onlymiss' );
		var onlyMiss = missEl ? missEl.checked : false;
		Array.prototype.forEach.call( root.querySelectorAll( '.trr-block' ), function ( b ) {
			var any = false;
			Array.prototype.forEach.call( b.querySelectorAll( '.trr-row' ), function ( tr ) {
				var typeOk = ! val || tr.getAttribute( 'data-type' ) === val;
				var missOk = ! onlyMiss || tr.className.indexOf( 'trr-missing' ) !== -1;
				var show   = typeOk && missOk;
				tr.style.display = show ? '' : 'none';
				if ( show ) {
					any = true;
				}
			} );
			b.style.display = any ? '' : 'none';
		} );
	}

	function api( action, extra ) {
		var fd = new FormData();
		fd.append( 'action', 'trrocket_panel' );
		fd.append( '_ajax_nonce', CFG.nonce );
		fd.append( 'post', post );
		fd.append( 'lang', cur );
		fd.append( 'do', action );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				fd.append( k, extra[ k ] );
			} );
		}
		return fetch( CFG.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function setMsg( s ) {
		var m = document.getElementById( 'trr-msg' );
		if ( m ) {
			m.textContent = s || '';
		}
	}

	function markDirty() {
		dirty = true;
		var el = document.getElementById( 'trr-dirty' );
		if ( el ) {
			el.textContent = t( 'unsaved' );
		}
	}

	function setExport( all ) {
		var ta = document.getElementById( 'trr-export' );
		if ( ! ta ) {
			return;
		}
		ta.value = all ? root._allExp : root._missExp;
		root._curIds = all ? root._allIds : root._missIds;
	}

	// A translation-progress bar whose fill goes red -> yellow -> green with the %.
	function progressBar( done, total ) {
		var pct = total > 0 ? Math.round( done / total * 100 ) : 100;
		var hue = Math.round( pct * 1.2 ); // 0% = red(0), 100% = green(120).
		return '<div class="trr-prog">' +
			'<div class="trr-prog-bar"><div class="trr-prog-fill" style="width:' + pct + '%;background:hsl(' + hue + ',72%,45%)"></div></div>' +
			'<div class="trr-prog-label">' + pct + '% · ' + done + '/' + total + ' ' + esc( t( 'done' ) ) + '</div>' +
			'</div>';
	}

	function render( data ) {
		var st = data.stats || { total: 0, done: 0, missing: 0 };
		var html = '<div class="trr-tabs">';
		langs.forEach( function ( l ) {
			html += '<button type="button" class="button button-small trr-tab' +
				( l.code === cur ? ' button-primary' : '' ) + '" data-l="' + esc( l.code ) + '">' +
				esc( l.label ) + '</button> ';
		} );
		html += '</div>';

		if ( data.excluded ) {
			html += '<div class="notice notice-info inline" style="margin:10px 0;padding:8px 12px"><p>' + esc( data.excluded_msg ) + '</p></div>';
			root.innerHTML = html;
			wire();
			dirty = false;
			return;
		}

		html += progressBar( st.total - st.missing, st.total );

		var veUrl = editUrls[ cur ] || '';
		html += '<div class="trr-actions">';
		if ( hasAI ) {
			html += '<button type="button" class="button button-primary button-small" id="trr-ai">✨ ' + esc( t( 'ai' ) ) + '</button>';
		}
		if ( CFG.autoG ) {
			html += '<button type="button" class="button button-small" id="trr-gtall">🌐 ' + esc( t( 'gtall' ) ) + '</button>';
		}
		if ( veUrl ) {
			html += '<a class="button button-small" id="trr-ve" href="' + esc( veUrl ) + '" target="_blank" rel="noopener">👁 ' + esc( t( 'visual' ) ) + '</a>';
		}
		html += '<button type="button" class="button button-small" id="trr-reset" style="color:#b32d2e">🗑 ' + esc( t( 'reset' ) ) + '</button>';
		html += '<span id="trr-msg" class="trr-msg"></span>';
		html += '</div>';

		// Copy-paste block.
		html += '<div class="trr-cp"><strong>' + esc( t( 'cp_title' ) ) + '</strong> ' +
			'<a class="button button-secondary trr-gopen" href="https://translate.google.com/?sl=' + encodeURIComponent( gtCode( CFG.source || 'auto' ) ) + '&tl=' + encodeURIComponent( gtCode( cur ) ) + '&op=translate" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-translation"></span> ' + esc( t( 'open_g' ) ) + '</a>' +
			'<label style="display:block;margin:6px 0"><input type="checkbox" id="trr-all"> ' + esc( t( 'redo_all' ) ) + '</label>' +
			'<textarea id="trr-export" readonly rows="5" class="large-text code" onclick="this.select()"></textarea>' +
			'<textarea id="trr-import" rows="5" class="large-text code" placeholder="1. ...&#10;2. ..."></textarea>' +
			'<button type="button" class="button" id="trr-import-btn">' + esc( t( 'import' ) ) + '</button></div>';

		// Filter + structured SEO block + page-content block.
		var strings = data.strings || [];
		var content = strings.filter( function ( s ) { return s.type !== 'meta'; } );
		html += '<p style="margin:10px 0">' +
			'<label>' + esc( t( 'filter_label' ) ) + ' <select id="trr-filter">' + filterOptions( content ) + '</select></label>' +
			' &nbsp; <label><input type="checkbox" id="trr-onlymiss"> ' + esc( t( 'only_missing' ) ) + '</label>' +
			'</p>';
		html += seoSection( strings, data.slug );
		html += manualTable( t( 'content_block' ), content, 'content' );
		html += '<div class="trr-savebar"><button type="button" class="button button-primary" id="trr-save">' + esc( t( 'save' ) ) + '</button> <span id="trr-dirty" class="trr-dirty"></span></div>';

		root.innerHTML = html;

		// Precompute export lists (missing + all).
		var missExp = '', allExp = '', missIds = [], allIds = [], iM = 1, iA = 1;
		( data.strings || [] ).forEach( function ( s ) {
			allExp += iA + '. ' + s.o + '\n';
			allIds.push( s.id );
			iA++;
			if ( ! s.done ) {
				missExp += iM + '. ' + s.o + '\n';
				missIds.push( s.id );
				iM++;
			}
		} );
		root._missExp = missExp;
		root._allExp = allExp;
		root._missIds = missIds;
		root._allIds = allIds;

		setExport( false );
		wire();
		dirty = false;
	}

	function wire() {
		Array.prototype.forEach.call( root.querySelectorAll( '.trr-tab' ), function ( b ) {
			b.addEventListener( 'click', function () {
				cur = this.getAttribute( 'data-l' );
				load();
			} );
		} );
		var all = document.getElementById( 'trr-all' );
		if ( all ) {
			all.addEventListener( 'change', function () {
				setExport( this.checked );
			} );
		}
		var filter = document.getElementById( 'trr-filter' );
		if ( filter ) {
			filter.addEventListener( 'change', applyFilter );
		}
		var onlymiss = document.getElementById( 'trr-onlymiss' );
		if ( onlymiss ) {
			onlymiss.addEventListener( 'change', applyFilter );
		}
		bind( 'trr-import-btn', doImport );
		bind( 'trr-save', doSave );
		bind( 'trr-ai', doAI );
		bind( 'trr-gtall', doGTAll );
		bind( 'trr-reset', doReset );

		Array.prototype.forEach.call( root.querySelectorAll( '.trr-tr' ), function ( inp ) {
			inp.addEventListener( 'input', function () { markDirty(); autoGrow( inp ); } );
			autoGrow( inp );
		} );
		var slugEl = document.getElementById( 'trr-slug' );
		if ( slugEl ) {
			slugEl.addEventListener( 'input', markDirty );
		}
		var slugSug = root.querySelector( '.trr-slug-suggest' );
		if ( slugSug ) {
			slugSug.addEventListener( 'click', function () {
				var tEl = root.querySelector( '.trr-tr[data-seo="title"]' );
				var src = ( tEl && tEl.value.trim() ) || this.getAttribute( 'data-fb' ) || '';
				if ( slugEl && src ) {
					slugEl.value = slugify( src );
					markDirty();
				}
			} );
		}
	}

	function bind( id, fn ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.addEventListener( 'click', fn );
		}
	}

	function load() {
		root.innerHTML = '<p class="description">' + esc( t( 'loading' ) ) + '</p>';
		api( 'load' ).then( function ( r ) {
			if ( r && r.success ) {
				render( r.data );
			} else {
				root.innerHTML = '<p>' + esc( t( 'error' ) ) + '</p>';
			}
		} ).catch( function () {
			root.innerHTML = '<p>' + esc( t( 'error' ) ) + '</p>';
		} );
	}

	function doImport() {
		var text = document.getElementById( 'trr-import' ).value;
		if ( ! text.trim() ) {
			setMsg( t( 'empty' ) );
			return;
		}
		setMsg( t( 'working' ) );
		api( 'import', { text: text, cp_ids: ( root._curIds || [] ).join( ',' ) } ).then( function ( r ) {
			if ( r && r.success ) {
				render( r.data );
			}
		} );
	}

	function doSave() {
		var items = [];
		Array.prototype.forEach.call( root.querySelectorAll( '.trr-tr' ), function ( inp ) {
			items.push( { id: inp.getAttribute( 'data-id' ), text: restoreTags( inp.value, inp.getAttribute( 'data-src' ) || '' ) } );
		} );
		var slugEl = document.getElementById( 'trr-slug' );
		setMsg( t( 'working' ) );
		api( 'save', { items: JSON.stringify( items ), slug: slugEl ? slugEl.value : '' } ).then( function ( r ) {
			if ( r && r.success ) {
				render( r.data );
				setMsg( t( 'saved' ) );
			}
		} );
	}

	function doAI() {
		setMsg( t( 'working' ) );
		api( 'ai' ).then( function ( r ) {
			if ( r && r.success ) {
				render( r.data );
			}
		} );
	}

	// Bulk-translate every missing string on this page with the free Google engine.
	function doGTAll() {
		setMsg( t( 'working' ) );
		api( 'gt_all' ).then( function ( r ) {
			if ( r && r.success ) {
				render( r.data );
			}
		} );
	}

	function doReset() {
		if ( ! window.confirm( t( 'reset_confirm' ) ) ) {
			return;
		}
		setMsg( t( 'working' ) );
		api( 'reset' ).then( function ( r ) {
			if ( r && r.success ) {
				render( r.data );
			}
		} );
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	/* Click a block in the editor -> jump to its text in this panel. */
	var trrDecoder = document.createElement( 'textarea' );
	function normTxt( s ) {
		s = ( s || '' ).replace( /<[^>]*>/g, ' ' );
		// Decode HTML entities so "B&amp;B" (from getBlockContent) and "B&B" (from
		// the row) normalise the same way and match.
		trrDecoder.innerHTML = s;
		s = trrDecoder.value;
		return s.replace( /\s+/g, ' ' ).trim().toLowerCase();
	}

	// Block text from the editor DOM (works for dynamic blocks like Spectra, whose
	// getBlockContent() is empty); falls back to the saved content.
	function getBlockText( block ) {
		var fr  = document.querySelector( 'iframe[name="editor-canvas"]' );
		var doc = ( fr && fr.contentDocument ) ? fr.contentDocument : document;
		var el  = doc.querySelector( '[data-block="' + block.clientId + '"]' );
		if ( el && el.textContent ) {
			return normTxt( el.textContent );
		}
		try {
			return normTxt( wp.blocks.getBlockContent( block ) );
		} catch ( e ) {
			return '';
		}
	}

	var currentActive = null;

	function getEditorContext() {
		var fr  = document.querySelector( 'iframe[name="editor-canvas"]' );
		var win = ( fr && fr.contentWindow ) ? fr.contentWindow : window;
		if ( ! win.getSelection ) {
			return '';
		}
		var sel = win.getSelection();
		if ( ! sel || ! sel.anchorNode ) {
			return '';
		}
		// The text node under the caret/selection maps to exactly one segment
		// (the engine splits a block at inline tags = separate text nodes). This is
		// far more reliable than the highlighted word, which may be a short common
		// word ("and") that matches several segments.
		var nodeText = sel.anchorNode.textContent || '';
		// Only prefer the selection if it actually spans more than that node.
		var selected = String( sel ).replace( /\s+/g, ' ' ).trim();
		if ( selected.length > nodeText.replace( /\s+/g, ' ' ).trim().length ) {
			return selected;
		}
		return nodeText;
	}

	function highlightRow( row ) {
		if ( ! row || row === currentActive ) {
			return; // already the active row -> don't keep re-scrolling
		}
		Array.prototype.forEach.call( root.querySelectorAll( '.trr-row.trr-active' ), function ( r ) {
			r.classList.remove( 'trr-active' );
		} );
		row.classList.add( 'trr-active' );
		currentActive = row;
		row.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		row.classList.remove( 'trr-jump' );
		void row.offsetWidth; // restart the flash
		row.classList.add( 'trr-jump' );
	}

	function jumpToBlock( block ) {
		if ( ! block || ! window.wp || ! wp.blocks ) {
			return;
		}
		var blockText = getBlockText( block );
		if ( blockText.length < 2 ) {
			return;
		}
		var ctx = normTxt( getEditorContext() );
		// Rows that are segments of THIS block (a block can hold many strings).
		var inBlock = [];
		Array.prototype.forEach.call( root.querySelectorAll( '.trr-row[data-src]' ), function ( r ) {
			if ( r.offsetParent === null ) {
				return; // hidden (other tab / filtered out)
			}
			var src = normTxt( r.getAttribute( 'data-src' ) );
			if ( src.length < 2 ) {
				return;
			}
			if ( src === blockText || blockText.indexOf( src ) !== -1 || src.indexOf( blockText ) !== -1 ) {
				inBlock.push( { row: r, src: src } );
			}
		} );
		if ( ! inBlock.length ) {
			return;
		}
		// Pick the segment under the cursor/selection (caret text node or selection).
		var hit = null, contains = null, within = null;
		if ( ctx.length >= 2 ) {
			for ( var i = 0; i < inBlock.length; i++ ) {
				var src = inBlock[ i ].src;
				if ( src === ctx ) {
					hit = inBlock[ i ].row;
					break;
				}
				if ( ! contains && src.indexOf( ctx ) !== -1 ) {
					contains = inBlock[ i ].row; // caret text is part of this segment
				}
				if ( ! within && ctx.indexOf( src ) !== -1 ) {
					within = inBlock[ i ].row; // segment is part of the caret text
				}
			}
		}
		highlightRow( hit || contains || within || inBlock[ 0 ].row );
	}

	function setupBlockSync() {
		if ( ! window.wp || ! wp.data || 'function' !== typeof wp.data.subscribe || ! wp.data.select( 'core/block-editor' ) ) {
			return; // classic editor / no block editor
		}
		var last = '';
		function syncNow() {
			var ed    = wp.data.select( 'core/block-editor' );
			var block = ed && ed.getSelectedBlock();
			if ( ! block ) {
				return;
			}
			var key = block.clientId + '|' + normTxt( getEditorContext() );
			if ( key === last ) {
				return;
			}
			last = key;
			jumpToBlock( block );
		}
		wp.data.subscribe( syncNow );
		// Caret/selection moves inside the canvas iframe don't always change state.
		var attach = function () {
			var fr  = document.querySelector( 'iframe[name="editor-canvas"]' );
			var doc = ( fr && fr.contentDocument ) ? fr.contentDocument : document;
			doc.addEventListener( 'selectionchange', syncNow );
			doc.addEventListener( 'mouseup', syncNow );
			doc.addEventListener( 'keyup', syncNow );
		};
		attach();
		setTimeout( attach, 1500 ); // the iframe canvas can mount late
	}

	/* Reverse: click a source string here -> select & scroll to its block above. */
	function findBlock( blocks, txt, partial ) {
		var hit = null;
		( function walk( list ) {
			for ( var i = 0; i < list.length && ! hit; i++ ) {
				var bk = list[ i ];
				if ( bk.innerBlocks && bk.innerBlocks.length ) {
					walk( bk.innerBlocks );
				} else {
					var bt = getBlockText( bk );
					if ( ! bt ) {
						continue;
					}
					if ( ! partial && bt === txt ) {
						hit = bk.clientId;
					} else if ( partial && ( bt.indexOf( txt ) !== -1 || txt.indexOf( bt ) !== -1 ) ) {
						hit = bk.clientId;
					}
				}
			}
		} )( blocks );
		return hit;
	}

	function selectPhraseInEditor( el, raw, fr ) {
		try {
			var doc    = fr.contentDocument;
			var want   = ( raw || '' ).toLowerCase();
			var walker = doc.createTreeWalker( el, NodeFilter.SHOW_TEXT, null );
			var node;
			while ( ( node = walker.nextNode() ) ) {
				var i = node.textContent.toLowerCase().indexOf( want );
				if ( -1 !== i ) {
					var range = doc.createRange();
					range.setStart( node, i );
					range.setEnd( node, Math.min( node.textContent.length, i + want.length ) );
					var sel = fr.contentWindow.getSelection();
					sel.removeAllRanges();
					sel.addRange( range );
					return;
				}
			}
		} catch ( e ) {}
	}

	function jumpToEditorBlock( src ) {
		var txt = normTxt( src );
		if ( txt.length < 2 || ! window.wp || ! wp.data || ! wp.blocks ) {
			return;
		}
		var ed = wp.data.select( 'core/block-editor' );
		if ( ! ed ) {
			return;
		}
		var blocks = ed.getBlocks();
		var id = findBlock( blocks, txt, false ) || findBlock( blocks, txt, true );
		if ( ! id ) {
			return;
		}
		wp.data.dispatch( 'core/block-editor' ).selectBlock( id );
		setTimeout( function () {
			var fr  = document.querySelector( 'iframe[name="editor-canvas"]' );
			var doc = ( fr && fr.contentDocument ) ? fr.contentDocument : document;
			var el  = doc.querySelector( '[data-block="' + id + '"]' );
			if ( el && el.scrollIntoView ) {
				el.scrollIntoView( { block: 'center', behavior: 'smooth' } );
			}
			// Highlight the exact phrase inside the block, not just select the block.
			if ( el && fr && fr.contentWindow ) {
				selectPhraseInEditor( el, src, fr );
			}
		}, 80 );
	}

	root.addEventListener( 'click', function ( e ) {
		if ( ! e.target || ! e.target.closest ) {
			return;
		}
		// Google icon -> fill this row's field inline with Google's free translation.
		var gt = e.target.closest( 'button.trr-gt' );
		if ( gt ) {
			e.preventDefault();
			var grow = gt.closest( 'tr' );
			var gta  = grow ? grow.querySelector( '.trr-tr' ) : null;
			var gsrc = gt.getAttribute( 'data-src' ) || ( gta && gta.getAttribute( 'data-src' ) ) || '';
			if ( ! gta || ! gsrc ) {
				return;
			}
			gt.disabled = true;
			gt.classList.add( 'trr-gt-busy' );
			setMsg( t( 'working' ) );
			api( 'gt', { text: protectTags( gsrc ) } ).then( function ( r ) {
				gt.disabled = false;
				gt.classList.remove( 'trr-gt-busy' );
				if ( r && r.success && r.data && r.data.translation ) {
					gta.value = restoreTags( r.data.translation, gsrc );
					autoGrow( gta );
					markDirty();
					setMsg( '' );
				} else {
					setMsg( ( r && r.data && r.data.error ) ? r.data.error : t( 'error' ) );
				}
			} ).catch( function () {
				gt.disabled = false;
				gt.classList.remove( 'trr-gt-busy' );
				setMsg( t( 'error' ) );
			} );
			return;
		}
		var cell = e.target.closest( '.trr-manual .trr-row td:first-child' );
		if ( ! cell ) {
			return;
		}
		var row = cell.closest( '.trr-row' );
		if ( row ) {
			jumpToEditorBlock( row.getAttribute( 'data-src' ) );
		}
	} );

	// When a translated phrase is pasted in, turn the numbered markers back into the
	// original HTML tags automatically.
	root.addEventListener( 'paste', function ( e ) {
		var inp = e.target;
		if ( ! inp || ! inp.classList || ! inp.classList.contains( 'trr-tr' ) ) {
			return;
		}
		var src = inp.getAttribute( 'data-src' ) || '';
		if ( ! /<[^>]+>/.test( src ) ) {
			return; // source has no tags — nothing to restore.
		}
		setTimeout( function () {
			var restored = restoreTags( inp.value, src );
			if ( restored !== inp.value ) {
				inp.value = restored;
				markDirty();
			}
			autoGrow( inp );
		}, 0 );
	} );

	load();
	setupBlockSync();
}() );
