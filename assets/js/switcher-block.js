/**
 * Gutenberg block for the TranslateRocket language switcher.
 * Plain JS (no build step). Dynamic block: rendered server-side by PHP.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}

	var el            = wp.element.createElement;
	var __            = wp.i18n.__;
	var InspectorCtrl = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody     = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ServerRender  = wp.serverSideRender;

	wp.blocks.registerBlockType( 'translaterocket/switcher', {
		apiVersion: 3,
		title: __( 'Language Switcher', 'translate-rocket' ),
		description: __( 'Let visitors switch language (TranslateRocket).', 'translate-rocket' ),
		icon: 'translation',
		category: 'widgets',
		keywords: [ 'language', 'translate', 'switcher', 'multilingual' ],
		attributes: {
			type: { type: 'string', default: 'inline' },
			show: { type: 'string', default: 'both' },
			current: { type: 'string', default: 'show' }
		},
		edit: function ( props ) {
			var a          = props.attributes;
			var blockProps = useBlockProps ? useBlockProps() : {};

			function set( key ) {
				return function ( value ) {
					var update = {};
					update[ key ] = value;
					props.setAttributes( update );
				};
			}

			var controls = el(
				InspectorCtrl,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'Switcher settings', 'translate-rocket' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Style', 'translate-rocket' ),
						value: a.type,
						options: [
							{ label: __( 'Inline (horizontal)', 'translate-rocket' ), value: 'inline' },
							{ label: __( 'List (vertical)', 'translate-rocket' ), value: 'list' },
							{ label: __( 'Dropdown', 'translate-rocket' ), value: 'dropdown' }
						],
						onChange: set( 'type' )
					} ),
					el( SelectControl, {
						label: __( 'Show', 'translate-rocket' ),
						value: a.show,
						options: [
							{ label: __( 'Flag + name', 'translate-rocket' ), value: 'both' },
							{ label: __( 'Flag only', 'translate-rocket' ), value: 'flag' },
							{ label: __( 'Name only', 'translate-rocket' ), value: 'name' }
						],
						onChange: set( 'show' )
					} ),
					el( SelectControl, {
						label: __( 'Current language', 'translate-rocket' ),
						value: a.current,
						options: [
							{ label: __( 'Show', 'translate-rocket' ), value: 'show' },
							{ label: __( 'Hide', 'translate-rocket' ), value: 'hide' }
						],
						onChange: set( 'current' )
					} )
				)
			);

			var preview;
			if ( ServerRender ) {
				preview = el( ServerRender, { block: 'translaterocket/switcher', attributes: a } );
			} else {
				preview = el( 'p', {}, __( 'Language Switcher', 'translate-rocket' ) );
			}

			return el( 'div', blockProps, controls, preview );
		},
		save: function () {
			return null; // Dynamic: rendered by PHP.
		}
	} );
}( window.wp ) );
