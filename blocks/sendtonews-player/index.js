( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;

	registerBlockType( 'sendtonews/playerselector', {
		edit: function ( props ) {
			var embedKey = props.attributes.embedKey;
			var blockProps = useBlockProps();

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'STN Video Settings', initialOpen: true },
						el( TextControl, {
							label: 'Player ID (optional)',
							value: embedKey,
							onChange: function ( value ) {
								props.setAttributes( { embedKey: value } );
							},
						} )
					)
				),
				el(
					'div',
					Object.assign( {}, blockProps, {
						style: {
							position: 'relative',
							width: '100%',
							aspectRatio: '16/9',
							maxWidth: '760px',
						},
					} ),
					el(
						'div',
						{
							style: {
								position: 'absolute',
								top: 0,
								left: 0,
								right: 0,
								bottom: 0,
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'center',
								color: '#555',
							},
						},
						embedKey
							? 'STN Video Single (ID: ' + embedKey + ')'
							: 'STN Video Single'
					)
				)
			);
		},
	} );
} )( window.wp );
