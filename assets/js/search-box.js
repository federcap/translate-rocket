/* TranslateRocket — search box (1.8.8): open/close, suggestions while typing, the visitor's filters. */
( function () {
	if ( window.trrocketSearch ) {
		return;
	}
	window.trrocketSearch = 1;

	function shut( box ) {
		var p = box.querySelector( '.trrocket-search-panel' ),
			o = box.querySelector( '.trrocket-search-open' );
		if ( p ) {
			p.hidden = true;
		}
		if ( o ) {
			o.setAttribute( 'aria-expanded', 'false' );
		}
		hideList( box );
	}

	function hideList( box ) {
		var l = box.querySelector( '.trrocket-search-suggest' ),
			i = box.querySelector( 'input[type=search]' );
		if ( l ) {
			l.hidden = true;
		}
		if ( i ) {
			i.setAttribute( 'aria-expanded', 'false' );
			i.removeAttribute( 'aria-activedescendant' );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var o = e.target.closest( '.trrocket-search-open' );
		if ( o ) {
			e.preventDefault();
			var b = o.closest( '.trrocket-search' ),
				p = b.querySelector( '.trrocket-search-panel' ),
				aperto = p.hidden;
			document.querySelectorAll( '.trrocket-search' ).forEach( shut );
			if ( aperto ) {
				p.hidden = false;
				o.setAttribute( 'aria-expanded', 'true' );
				var i = p.querySelector( 'input[type=search]' );
				if ( i ) {
					i.focus();
				}
			}
			return;
		}
		if ( e.target.closest( '.trrocket-search-close' ) ) {
			shut( e.target.closest( '.trrocket-search' ) );
			return;
		}
		var d = e.target.closest( '.trrocket-search--overlay .trrocket-search-panel' );
		if ( d && e.target === d ) {
			shut( d.closest( '.trrocket-search' ) );
			return;
		}
		if ( ! e.target.closest( '.trrocket-search' ) ) {
			document.querySelectorAll( '.trrocket-search--icon' ).forEach( shut );
			document.querySelectorAll( '.trrocket-search' ).forEach( hideList );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			document.querySelectorAll( '.trrocket-search' ).forEach( shut );
		}
	} );

	// The visitor's filters become the search's own parameters.
	document.addEventListener( 'submit', function ( e ) {
		var f = e.target.closest( '.trrocket-search-form' );
		if ( ! f ) {
			return;
		}
		var i = f.querySelector( 'input[type=search]' );
		if ( i && '' === i.value.trim() ) {
			e.preventDefault();
			i.focus();
			return;
		}
		var tipo = f.querySelector( 'input[name=trr_type]:checked' );
		if ( tipo && '' !== tipo.value ) {
			f.querySelectorAll( '.trrocket-search-owner-type' ).forEach( function ( h ) {
				h.disabled = true;
			} );
			var h = document.createElement( 'input' );
			h.type = 'hidden';
			h.name = 'post_type';
			h.value = tipo.value;
			f.appendChild( h );
		}
		f.querySelectorAll( 'input[name=trr_type]' ).forEach( function ( r ) {
			r.disabled = true;
		} );
		var tutte = f.querySelector( '.trrocket-search-all' ),
			lingua = f.querySelector( '.trrocket-search-lang' ),
			box = f.closest( '.trrocket-search' ),
			predef = box && '1' === box.getAttribute( 'data-all' );
		if ( tutte && lingua ) {
			// trr_all when the owner searches one language and the visitor ticks «every language»; trr_one the other way.
			lingua.disabled = predef ? tutte.checked : ! tutte.checked;
		}
	} );

	// Suggestions while typing: 3 letters, a short pause, the answers kept for the page's life.
	var memoria = {};
	function suggest( box, input ) {
		var url = box.getAttribute( 'data-suggest' ),
			list = box.querySelector( '.trrocket-search-suggest' ),
			q = input.value.trim();
		if ( ! url || ! list ) {
			return;
		}
		if ( q.length < 3 ) {
			box.classList.remove( 'trrocket-search--loading' );
			hideList( box );
			return;
		}
		var f = box.querySelector( '.trrocket-search-form' ),
			tipo = f && f.querySelector( 'input[name=trr_type]:checked' ),
			tutte = f && f.querySelector( '.trrocket-search-all' ),
			all = tutte ? ( tutte.checked ? '1' : '0' ) : box.getAttribute( 'data-all' ),
			types = tipo && '' !== tipo.value ? tipo.value : ( box.getAttribute( 'data-types' ) || '' ),
			key = [ q, box.getAttribute( 'data-lang' ), types, all ].join( '|' );
		var draw = function ( items ) {
			list.innerHTML = '';
			if ( ! items || ! items.length ) {
				hideList( box );
				return;
			}
			items.forEach( function ( it, n ) {
				var li = document.createElement( 'li' ),
					a = document.createElement( 'a' ),
					k = document.createElement( 'span' );
				li.setAttribute( 'role', 'option' );
				li.id = list.id + '-' + n;
				a.href = it.u;
				a.textContent = it.t;
				a.tabIndex = -1;
				k.className = 'trrocket-search-kind';
				k.textContent = it.k || '';
				a.appendChild( k );
				li.appendChild( a );
				list.appendChild( li );
			} );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		};
		if ( memoria[ key ] ) {
			draw( memoria[ key ] );
			return;
		}
		// 17/10/2026 (screenshots for Federico): on a slow host the answer takes a while and nothing showed
		// that it was coming — a small wheel in the field while it travels.
		box.classList.add( 'trrocket-search--loading' );
		var fine = function () {
			box.classList.remove( 'trrocket-search--loading' );
		};
		var dati = 'q=' + encodeURIComponent( q ) + '&lang=' + encodeURIComponent( box.getAttribute( 'data-lang' ) || '' ) + '&types=' + encodeURIComponent( types ) + '&all=' + all + '&n=' + ( box.getAttribute( 'data-count' ) || '6' );
		// 1.8.10: the light endpoint (suggest.php) answers in a fraction of the time of the REST one, because it
		// starts only a slice of WordPress. If a host blocks it, or it errs, the REST one takes over for the visit.
		var leggero = box.getAttribute( 'data-light' );
		var usaLeggero = leggero && ! window.trrNoLight;
		var chiama = function ( u ) {
			return fetch( u + ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + dati, { credentials: 'same-origin' } ).then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'http ' + r.status );
				}
				return r.json();
			} );
		};
		( usaLeggero ? chiama( leggero ).catch( function () { window.trrNoLight = true; return chiama( url ); } ) : chiama( url ) )
			.then( function ( items ) {
				if ( ! Array.isArray( items ) ) {
					items = [];
				}
				memoria[ key ] = items;
				if ( input.value.trim() === q ) {
					fine();
					draw( items );
				}
			} )
			.catch( function () {
				fine();
				hideList( box );
			} );
	}

	var attesa;
	document.addEventListener( 'input', function ( e ) {
		var input = e.target.closest( '.trrocket-search input[type=search]' );
		if ( ! input ) {
			return;
		}
		var box = input.closest( '.trrocket-search' );
		if ( ! box.hasAttribute( 'data-suggest' ) ) {
			return;
		}
		clearTimeout( attesa );
		attesa = setTimeout( function () {
			suggest( box, input );
		}, 250 );
	} );

	// Arrows move in the suggestions, Enter opens the chosen one.
	document.addEventListener( 'keydown', function ( e ) {
		var input = e.target.closest( '.trrocket-search input[type=search]' );
		if ( ! input || ( 'ArrowDown' !== e.key && 'ArrowUp' !== e.key && 'Enter' !== e.key ) ) {
			return;
		}
		var box = input.closest( '.trrocket-search' ),
			list = box.querySelector( '.trrocket-search-suggest' );
		if ( ! list || list.hidden ) {
			return;
		}
		var opts = Array.prototype.slice.call( list.querySelectorAll( '[role=option]' ) ),
			cur = opts.findIndex( function ( o ) {
				return o.classList.contains( 'is-active' );
			} );
		if ( 'Enter' === e.key ) {
			if ( cur >= 0 ) {
				e.preventDefault();
				window.location.href = opts[ cur ].querySelector( 'a' ).href;
			}
			return;
		}
		e.preventDefault();
		cur = 'ArrowDown' === e.key ? Math.min( opts.length - 1, cur + 1 ) : Math.max( 0, cur - 1 );
		opts.forEach( function ( o, n ) {
			o.classList.toggle( 'is-active', n === cur );
			o.setAttribute( 'aria-selected', n === cur ? 'true' : 'false' );
		} );
		input.setAttribute( 'aria-activedescendant', opts[ cur ].id );
	} );

	// Changing a filter refreshes the suggestions.
	document.addEventListener( 'change', function ( e ) {
		if ( ! e.target.closest( '.trrocket-search-filters' ) ) {
			return;
		}
		var box = e.target.closest( '.trrocket-search' ),
			input = box && box.querySelector( 'input[type=search]' );
		if ( box && input && box.hasAttribute( 'data-suggest' ) ) {
			suggest( box, input );
		}
	} );
}() );
