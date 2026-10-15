/* TranslateRocket — «Languages» on every block (1.8.6, Federico: «contenuti diversi per lingua»).
   Show a block everywhere, only in some languages, or hide it in some. It writes the trrocket-only-xx /
   trrocket-hide-xx classes the site already understands (Frontend\LanguageOnly): no new markup, nothing
   to migrate, and the same works in the Site Editor and in Navigation links. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.hooks || ! wp.compose || ! wp.blockEditor || ! wp.components || ! wp.element ) {
		return;
	}
	var data = window.trrocketBlockLangs;
	if ( ! data || ! data.langs || data.langs.length < 2 ) {
		return;
	}
	var el       = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var IC       = wp.blockEditor.InspectorControls;
	var C        = wp.components;
	var t        = data.i18n || {};
	var ONLY     = 'trrocket-only-';
	var HIDE     = 'trrocket-hide-';

	function parse( cls ) {
		var out = { only: [], hide: [], rest: [] };
		String( cls || '' ).split( /\s+/ ).forEach( function ( c ) {
			if ( ! c ) {
				return;
			}
			if ( 0 === c.indexOf( ONLY ) ) {
				out.only.push( c.slice( ONLY.length ) );
			} else if ( 0 === c.indexOf( HIDE ) ) {
				out.hide.push( c.slice( HIDE.length ) );
			} else {
				out.rest.push( c );
			}
		} );
		return out;
	}

	function build( rest, mode, list ) {
		var out = rest.slice();
		if ( 'all' !== mode ) {
			list.forEach( function ( c ) {
				out.push( ( 'only' === mode ? ONLY : HIDE ) + c );
			} );
		}
		var s = out.join( ' ' ).trim();
		return s ? s : undefined;
	}

	function supports( name ) {
		return ! wp.blocks || ! wp.blocks.hasBlockSupport || wp.blocks.hasBlockSupport( name, 'customClassName', true );
	}

	var withLanguages = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! props.isSelected || ! supports( props.name ) ) {
				return el( BlockEdit, props );
			}
			var p    = parse( props.attributes.className );
			var mode = p.only.length ? 'only' : ( p.hide.length ? 'hide' : 'all' );
			var list = 'only' === mode ? p.only : ( 'hide' === mode ? p.hide : [] );
			function set( m, l ) {
				props.setAttributes( { className: build( p.rest, l.length ? m : 'all', l ) } );
			}
			var boxes = 'all' === mode ? null : data.langs.map( function ( L ) {
				return el( C.CheckboxControl, {
					key: L.code,
					label: L.label,
					checked: list.indexOf( L.code ) >= 0,
					onChange: function ( on ) {
						var l = list.filter( function ( x ) { return x !== L.code; } );
						if ( on ) {
							l.push( L.code );
						}
						set( mode, l );
					},
				} );
			} );
			var panel = el(
				IC,
				null,
				el(
					C.PanelBody,
					{ title: t.title || 'Languages', initialOpen: 'all' !== mode, className: 'trrocket-block-langs' },
					el( C.RadioControl, {
						label: t.label || '',
						help: t.help || '',
						selected: mode,
						options: [
							{ label: t.all || 'In every language', value: 'all' },
							{ label: t.only || 'Only in these languages', value: 'only' },
							{ label: t.hide || 'Hidden in these languages', value: 'hide' },
						],
						onChange: function ( m ) {
							set( m, 'all' === m ? [] : ( list.length ? list : [ data.langs[ 0 ].code ] ) );
						},
					} ),
					boxes
				)
			);
			return el( Fragment, null, el( BlockEdit, props ), panel );
		};
	}, 'withTranslateRocketLanguages' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'translaterocket/languages', withLanguages );
}( window.wp ) );
