/**
 * Donations for WooCommerce Companion — block editor script.
 *
 * Registers the recurring-donation block. Editor renders via ServerSideRender
 * (PHP render_callback in Block.php). Inspector sidebar offers a campaign
 * picker populated from the wc-donation post type.
 *
 * Vanilla wp.* globals — no build step. Available because the script's
 * dependencies include wp-blocks / wp-element / wp-block-editor / etc.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) { return; }

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var useState          = wp.element.useState;
	var useEffect         = wp.element.useEffect;
	var __                = ( wp.i18n && wp.i18n.__ ) || function ( s ) { return s; };
	var ServerSideRender  = wp.serverSideRender || ( wp.editor && wp.editor.ServerSideRender ) || null;
	var InspectorControls = wp.blockEditor && wp.blockEditor.InspectorControls;
	var useBlockProps     = wp.blockEditor && wp.blockEditor.useBlockProps;
	var PanelBody         = wp.components && wp.components.PanelBody;
	var SelectControl     = wp.components && wp.components.SelectControl;
	var Placeholder       = wp.components && wp.components.Placeholder;
	var Spinner           = wp.components && wp.components.Spinner;
	var apiFetch          = wp.apiFetch;

	registerBlockType( 'dfwc-companion/recurring-donation', {
		edit: function ( props ) {
			var attributes    = props.attributes || {};
			var setAttributes = props.setAttributes;
			var campaignId    = attributes.campaignId || 0;

			var blockProps = useBlockProps ? useBlockProps() : {};

			var campaignsState = useState( null );
			var campaigns      = campaignsState[0];
			var setCampaigns   = campaignsState[1];

			useEffect( function () {
				if ( ! apiFetch ) { setCampaigns( [] ); return; }
				apiFetch( { path: '/wp/v2/wc-donation?per_page=100&status=publish&_fields=id,title' } )
					.then( function ( items ) {
						setCampaigns( items.map( function ( p ) {
							return { value: String( p.id ), label: ( p.title && p.title.rendered ) || ( '#' + p.id ) };
						} ) );
					} )
					.catch( function () { setCampaigns( [] ); } );
			}, [] );

			var options = [ { value: '0', label: __( '— Select a campaign —', 'dfwc-companion' ) } ];
			if ( campaigns ) { options = options.concat( campaigns ); }

			var inspector = InspectorControls && PanelBody && SelectControl
				? el( InspectorControls, null,
					el( PanelBody, { title: __( 'Campaign', 'dfwc-companion' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Donation campaign', 'dfwc-companion' ),
							value: String( campaignId ),
							options: options,
							onChange: function ( v ) { setAttributes( { campaignId: parseInt( v, 10 ) || 0 } ); },
						} )
					)
				)
				: null;

			var preview;
			if ( ! campaignId && Placeholder ) {
				preview = el( Placeholder, {
					icon: 'heart',
					label: __( 'Recurring Donation', 'dfwc-companion' ),
					instructions: campaigns === null
						? __( 'Loading campaigns…', 'dfwc-companion' )
						: __( 'Choose a campaign in the block sidebar to preview the form.', 'dfwc-companion' ),
				}, campaigns === null && Spinner ? el( Spinner, null ) : null );
			} else if ( campaignId && ServerSideRender ) {
				preview = el( ServerSideRender, {
					block: 'dfwc-companion/recurring-donation',
					attributes: { campaignId: campaignId },
				} );
			} else {
				preview = el( 'div', { className: 'dfwc-form__placeholder' },
					__( 'Recurring Donation form (campaign #', 'dfwc-companion' ) + campaignId + ')'
				);
			}

			return el( Fragment, null, inspector, el( 'div', blockProps, preview ) );
		},

		// Server-rendered: save returns null so post_content stays clean.
		save: function () { return null; },
	} );
} )( window.wp );
