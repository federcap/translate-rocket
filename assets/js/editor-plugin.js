/* TranslateRocket — "Translate" launcher in the block editor header. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.editPost ) {
		return;
	}
	var data = window.trrocketEditor;
	if ( ! data || ! data.langs || ! data.langs.length ) {
		return;
	}

	var el       = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var i18n     = data.i18n || {};
	var title    = i18n.title || 'TranslateRocket';

	var icon = el( 'span', { style: { fontSize: '18px', lineHeight: 1 } }, '🚀' );

	function langLink( L ) {
		var label = i18n.translateIn ? i18n.translateIn.replace( '%s', L.label ) : L.label;
		return el(
			'a',
			{
				key: L.code,
				href: L.url,
				target: '_blank',
				rel: 'noopener',
				style: {
					display: 'flex',
					alignItems: 'center',
					gap: '8px',
					padding: '9px 11px',
					margin: '6px 0',
					border: '1px solid #e0e0e0',
					borderRadius: '6px',
					textDecoration: 'none',
					color: '#1e1e1e',
					fontWeight: 600,
					background: '#fff',
				},
			},
			[
				el( 'span', { key: 'f', style: { fontSize: '16px' } }, L.flag || '🌐' ),
				el( 'span', { key: 'l' }, label ),
			]
		);
	}

	function Panel() {
		return el( 'div', { style: { padding: '14px' } }, [
			el( 'p', { key: 'd', style: { margin: '0 0 6px', color: '#555' } }, i18n.desc || '' ),
			el( 'div', { key: 'list' }, data.langs.map( langLink ) ),
			el( 'p', { key: 'o', style: { margin: '8px 0 0', color: '#888', fontSize: '11px' } }, i18n.opens || '' ),
		] );
	}

	wp.plugins.registerPlugin( 'trrocket-translate', {
		render: function () {
			return el( Fragment, {}, [
				el(
					wp.editPost.PluginSidebarMoreMenuItem,
					{ key: 'mm', target: 'trrocket-translate-sidebar', icon: icon },
					title
				),
				el(
					wp.editPost.PluginSidebar,
					{ key: 'sb', name: 'trrocket-translate-sidebar', title: title, icon: icon },
					el( Panel )
				),
			] );
		},
	} );
}( window.wp ) );
