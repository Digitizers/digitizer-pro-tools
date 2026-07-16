/**
 * Digitizer Pro Tools - Enlighter block (server-rendered, no build step).
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var TextareaControl = components.TextareaControl;

	var langs = ( window.DPTEnlighterBlock && window.DPTEnlighterBlock.languages ) || { plain: 'Plain text' };
	var options = Object.keys( langs ).map( function ( key ) {
		return { label: langs[ key ], value: key };
	} );

	blocks.registerBlockType( 'digitizer/enlighter', {
		apiVersion: 2,
		title: __( 'Code (Enlighter)', 'digitizer-pro-tools' ),
		description: __( 'Syntax-highlighted code block.', 'digitizer-pro-tools' ),
		icon: 'editor-code',
		category: 'formatting',
		attributes: {
			code: { type: 'string', default: '' },
			language: { type: 'string', default: 'php' },
			lines: { type: 'boolean', default: true },
			copy: { type: 'boolean', default: true }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var setA = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Code settings', 'digitizer-pro-tools' ) },
						el( SelectControl, {
							label: __( 'Language', 'digitizer-pro-tools' ),
							value: a.language,
							options: options,
							onChange: function ( v ) { setA( { language: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Line numbers', 'digitizer-pro-tools' ),
							checked: a.lines,
							onChange: function ( v ) { setA( { lines: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Copy button', 'digitizer-pro-tools' ),
							checked: a.copy,
							onChange: function ( v ) { setA( { copy: v } ); }
						} )
					)
				),
				el( TextareaControl, {
					label: __( 'Code', 'digitizer-pro-tools' ),
					value: a.code,
					rows: 10,
					onChange: function ( v ) { setA( { code: v } ); },
					__nextHasNoMarginBottom: true
				} )
			);
		},
		save: function () {
			// Dynamic block - rendered by PHP.
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
