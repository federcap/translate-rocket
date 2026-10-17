/* TranslateRocket — «TranslateRocket search» block (1.8.8): a search box that searches in the page's language. */
( function ( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var Inspector = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'translaterocket/search', {
		apiVersion: 3,
		title: __( 'TranslateRocket search', 'translate-rocket' ),
		description: __( 'A search box that searches in the language of the page.', 'translate-rocket' ),
		icon: 'search',
		category: 'widgets',
		keywords: [ 'search', 'translate', 'language' ],
		attributes: {
			profile: { type: 'string', default: 'default' },
			style: { type: 'string', default: '' },
			filters: { type: 'string', default: '' },
			autocomplete: { type: 'string', default: '' },
			all: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( Inspector, {}, el( PanelBody, { title: __( 'Look', 'translate-rocket' ) },
					el( SelectControl, {
						label: __( 'Profile (set up on the Search screen)', 'translate-rocket' ),
						value: props.attributes.profile,
						options: ( window.TRRocketSearchProfiles || [ 'default' ] ).map( function ( p ) { return { label: p, value: p }; } ),
						onChange: function ( v ) { props.setAttributes( { profile: v } ); }
					} ),
					el( SelectControl, {
						label: __( 'Look of the search box', 'translate-rocket' ),
						value: props.attributes.style,
						options: [
							{ label: __( 'As in the profile', 'translate-rocket' ), value: '' },
							{ label: __( 'Field', 'translate-rocket' ), value: 'field' },
							{ label: __( 'Rounded field', 'translate-rocket' ), value: 'pill' },
							{ label: __( 'Magnifier that opens a field', 'translate-rocket' ), value: 'icon' },
							{ label: __( 'Magnifier that opens the search over the page', 'translate-rocket' ), value: 'overlay' }
						],
						onChange: function ( v ) { props.setAttributes( { style: v } ); }
					} ),
					el( SelectControl, {
						label: __( 'Filters for the visitor', 'translate-rocket' ),
						value: props.attributes.filters,
						options: [
							{ label: __( 'As in the settings', 'translate-rocket' ), value: '' },
							{ label: __( 'None', 'translate-rocket' ), value: 'none' },
							{ label: __( 'Always in sight, under the box', 'translate-rocket' ), value: 'chips' },
							{ label: __( 'Behind a «Filters» button', 'translate-rocket' ), value: 'dropdown' }
						],
						onChange: function ( v ) { props.setAttributes( { filters: v } ); }
					} ),
					el( SelectControl, {
						label: __( 'Suggestions while typing', 'translate-rocket' ),
						value: props.attributes.autocomplete,
						options: [
							{ label: __( 'As in the settings', 'translate-rocket' ), value: '' },
							{ label: __( 'Yes', 'translate-rocket' ), value: 'yes' },
							{ label: __( 'No', 'translate-rocket' ), value: 'no' }
						],
						onChange: function ( v ) { props.setAttributes( { autocomplete: v } ); }
					} ),
					el( SelectControl, {
						label: __( 'Languages', 'translate-rocket' ),
						value: props.attributes.all,
						options: [
							{ label: __( 'As in the settings', 'translate-rocket' ), value: '' },
							{ label: __( 'The language of the page', 'translate-rocket' ), value: 'no' },
							{ label: __( 'Every language', 'translate-rocket' ), value: 'yes' }
						],
						onChange: function ( v ) { props.setAttributes( { all: v } ); }
					} )
				) ),
				el( ServerSideRender, { block: 'translaterocket/search', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
