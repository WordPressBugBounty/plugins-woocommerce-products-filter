/* HUSKY MCP block: shows the AI assistant connection box, rendered on the server. */
( function ( wp ) {
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'husky/mcp-connect', {
		apiVersion: 3,
		title: __( 'AI assistant connection (HUSKY MCP)', 'woocommerce-products-filter' ),
		icon: 'search',
		category: 'widgets',
		attributes: {
			title: { type: 'string', default: '' },
			text: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var blockProps = wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Texts', 'woocommerce-products-filter' ) },
						el( TextControl, {
							label: __( 'Title (empty = from HUSKY settings)', 'woocommerce-products-filter' ),
							value: props.attributes.title,
							onChange: function ( v ) { props.setAttributes( { title: v } ); }
						} ),
						el( TextareaControl, {
							label: __( 'Text (empty = from HUSKY settings)', 'woocommerce-products-filter' ),
							value: props.attributes.text,
							onChange: function ( v ) { props.setAttributes( { text: v } ); }
						} )
					)
				),
				el( ServerSideRender, { block: 'husky/mcp-connect', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
