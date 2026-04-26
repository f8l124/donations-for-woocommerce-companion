/**
 * Donations for WooCommerce Companion — admin tab injector.
 *
 * Relocates our companion config UI into the parent plugin's "Recurring
 * Donations" tab (#tab-3) and hides the parent's now-redundant recurring
 * controls. Falls back gracefully (meta box stays visible in its default
 * location) when the parent DOM doesn't match expectations.
 *
 * The parent's tab template lives at includes/views/admin/recurring_donations_html.php
 * and the tab container is rendered from includes/views/admin/single_campaign.php
 * line 125 as `<div id="tab-3" class="wc-donation-tabcontent">...</div>`.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		// Our companion meta box content — what we want to relocate.
		var ourBox = document.querySelector( '[data-dfwc-meta-box]' );
		if ( ! ourBox ) { return; } // Companion meta box not on this screen.

		// Parent's "Recurring Donations" tab container.
		var tab = document.getElementById( 'tab-3' );
		if ( ! tab ) {
			console.warn( '[dfwc-companion] #tab-3 not found; keeping companion meta box at its default location.' );
			return;
		}

		// Parent's recurring controls live in two possible inner blocks
		// depending on which engine is detected (WCS vs WPS SFW). Hide
		// whichever ones exist BUT keep them in the DOM so their <input>
		// fields still POST (preserves parent's underlying state).
		var parentBlocks = [
			document.getElementById( 'wps_sfw_product_target_section' ), // WPS SFW path
			document.getElementById( 'wc-donation-recurring' ),          // WCS Display Type select
			document.getElementById( 'wc-donation-recurring-text' ),     // WCS Recurring Text wrapper
			document.getElementById( 'wc-donation-recurring-schedules' ),// WCS interval/period/length selects
		];

		// Parent also might render a "WC Subscriptions inactive" notice when
		// no engine is loaded — keep that visible so admin knows what to install.
		// Detect via the .notice.notice-warning sibling text, leave it alone.

		var hiddenAny = false;
		parentBlocks.forEach( function ( el ) {
			if ( el ) {
				el.style.display = 'none';
				el.setAttribute( 'data-dfwc-hidden-by-companion', 'true' );
				hiddenAny = true;
			}
		} );

		// Build a small intro line for above our relocated content.
		var intro = document.createElement( 'div' );
		intro.className = 'dfwc-mb__tab-intro';
		intro.innerHTML = ourBox.getAttribute( 'data-tab-intro-html' ) ||
			'<p style="margin:0 0 12px;color:#646970;"><em>Configure your donation intervals and preset amounts here. These settings replace the legacy Display Type / Recurring Text controls.</em></p>';
		tab.insertBefore( intro, tab.firstChild );

		// Move our content into the tab. Move (not clone) so the form
		// inputs retain a single canonical location for serialization.
		// They still POST because the parent's metabox <form> wraps the
		// entire post-edit screen in the WP classic editor.
		tab.appendChild( ourBox );

		// Hide the now-empty companion meta box wrapper so the admin doesn't
		// see two copies. The wrapper is the <div class="postbox"> ancestor
		// of [data-dfwc-meta-box], identified by id="dfwc_companion_intervals".
		var wrapper = document.getElementById( 'dfwc_companion_intervals' );
		if ( wrapper ) {
			wrapper.style.display = 'none';
			wrapper.setAttribute( 'data-dfwc-relocated', 'true' );
		}

		// Mark the body so CSS can target relocated state if needed.
		document.body.classList.add( 'dfwc-tab-relocated' );

		// If we hid parent's blocks, also hide their tab's "Display Type" /
		// "Recurring Text" labels that sit between them as bare text nodes.
		// Easier: hide every direct child <p>/<div> that contains a label
		// referencing the wc-donation-recurring* selectors when the engine
		// is WPS SFW or WCS. Skip — current selectors already cover them.

		if ( ! hiddenAny ) {
			console.info( '[dfwc-companion] No parent recurring controls found in #tab-3 — relocated UI but left tab otherwise unchanged.' );
		}
	} );
} )();
