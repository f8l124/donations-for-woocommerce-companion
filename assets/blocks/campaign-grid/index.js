/**
 * Donations for WooCommerce Companion — campaign-grid block (editor side).
 *
 * Server-rendered block (render_callback in PHP). Editor exposes
 * InspectorControls for the same attributes the shortcode accepts; preview
 * uses ServerSideRender so what you see is what donors see.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType }   = wp.blocks;
	const { InspectorControls }   = wp.blockEditor || wp.editor;
	const { PanelBody, SelectControl, RangeControl, ToggleControl, TextControl } = wp.components;
	const ServerSideRender        = wp.serverSideRender || wp.editor.ServerSideRender;
	const { useBlockProps }       = wp.blockEditor || wp.editor;
	const el                      = wp.element.createElement;
	const { __ }                  = wp.i18n;

	registerBlockType( 'dfwc-companion/campaign-grid', {
		edit: function ( props ) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();

			const taxFilters = [
				{ key: 'cause',            label: __( 'Cause slug', 'dfwc-companion' ) },
				{ key: 'region',           label: __( 'Region slug', 'dfwc-companion' ) },
				{ key: 'country',          label: __( 'Country slug', 'dfwc-companion' ) },
				{ key: 'program',          label: __( 'Program slug', 'dfwc-companion' ) },
				{ key: 'sponsorship_type', label: __( 'Sponsorship type slug', 'dfwc-companion' ) },
				{ key: 'urgency',          label: __( 'Urgency slug', 'dfwc-companion' ) },
			];

			return el( 'div', blockProps, [
				el( InspectorControls, { key: 'inspector' }, [
					el( PanelBody, { title: __( 'Layout', 'dfwc-companion' ), initialOpen: true }, [
						el( SelectControl, {
							key: 'layout',
							label: __( 'Layout', 'dfwc-companion' ),
							value: attributes.layout,
							options: [
								{ value: 'grid', label: __( 'Grid', 'dfwc-companion' ) },
								{ value: 'list', label: __( 'List', 'dfwc-companion' ) },
							],
							onChange: function ( v ) { setAttributes( { layout: v } ); },
						} ),
						el( ToggleControl, {
							key: 'show_filters',
							label: __( 'Show filters', 'dfwc-companion' ),
							checked: attributes.show_filters,
							onChange: function ( v ) { setAttributes( { show_filters: !!v } ); },
						} ),
						el( ToggleControl, {
							key: 'featured',
							label: __( 'Featured campaigns only', 'dfwc-companion' ),
							checked: attributes.featured,
							onChange: function ( v ) { setAttributes( { featured: !!v } ); },
						} ),
						el( RangeControl, {
							key: 'per_page',
							label: __( 'Campaigns per page', 'dfwc-companion' ),
							value: attributes.per_page,
							min: 1,
							max: 50,
							onChange: function ( v ) { setAttributes( { per_page: v } ); },
						} ),
					] ),
					el( PanelBody, { title: __( 'Filters (optional)', 'dfwc-companion' ), initialOpen: false },
						taxFilters.map( function ( f ) {
							return el( TextControl, {
								key: f.key,
								label: f.label,
								value: attributes[ f.key ],
								onChange: function ( v ) {
									const update = {};
									update[ f.key ] = v;
									setAttributes( update );
								},
							} );
						} )
					),
					el( PanelBody, { title: __( 'Sort', 'dfwc-companion' ), initialOpen: false }, [
						el( SelectControl, {
							key: 'orderby',
							label: __( 'Sort by', 'dfwc-companion' ),
							value: attributes.orderby,
							options: [
								{ value: 'menu_order', label: __( 'Manual order', 'dfwc-companion' ) },
								{ value: 'featured',   label: __( 'Featured first', 'dfwc-companion' ) },
								{ value: 'title',      label: __( 'Title', 'dfwc-companion' ) },
								{ value: 'date',       label: __( 'Date', 'dfwc-companion' ) },
								{ value: 'rand',       label: __( 'Random', 'dfwc-companion' ) },
							],
							onChange: function ( v ) { setAttributes( { orderby: v } ); },
						} ),
						el( SelectControl, {
							key: 'order',
							label: __( 'Direction', 'dfwc-companion' ),
							value: attributes.order,
							options: [
								{ value: 'ASC',  label: __( 'Ascending', 'dfwc-companion' ) },
								{ value: 'DESC', label: __( 'Descending', 'dfwc-companion' ) },
							],
							onChange: function ( v ) { setAttributes( { order: v } ); },
						} ),
					] ),
				] ),
				el( ServerSideRender, {
					key: 'render',
					block: 'dfwc-companion/campaign-grid',
					attributes: attributes,
				} ),
			] );
		},
		save: function () {
			return null; // server-rendered
		},
	} );
} )( window.wp );
