/**
 * Donations for WooCommerce Companion — admin tab injector.
 *
 * Relocates our companion config UI into the parent plugin's "Recurring
 * Donations" tab (#tab-3) and keeps parent's recurring controls visible.
 * Auto-syncs parent's "Display Type" select to "User" whenever monthly or
 * annual is enabled in our companion UI, then disables the select so the
 * admin cannot accidentally desync the two configurations.
 *
 * Why expose parent's control instead of hiding it (v0.6.5):
 * Parent's class-wcdonationcampaignsetting.php save handler reads
 * `wc-donation-recurring` from post meta (line 1739) AFTER reading from
 * `$_POST['wc-donation-recurring']` (line 1462), and writes
 * `_wps_sfw_product` / `_wps_sfw_users` based on that value (lines 1740-1744).
 * Letting the select POST `'user'` naturally is a single-source-of-truth
 * fix; the v0.6.4-era priority-9999 + woocommerce_process_product_meta
 * defensive hooks (which we removed) were fighting parent's last-write
 * sequence in a fragile way.
 *
 * Falls back gracefully (meta box stays visible in its default location)
 * when the parent DOM doesn't match expectations.
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

	function findRecurringSelect() {
		// Both engines render <select name="wc-donation-recurring">. The id
		// differs (wc-donation-recurring on WCS, wc-free-donation-recurring on
		// WPS SFW), so we match on name to be engine-agnostic.
		return document.querySelector( 'select[name="wc-donation-recurring"]' );
	}

	function findEnabledCheckbox( interval ) {
		return document.querySelector(
			'input[name="dfwc_intervals[' + interval + '][enabled]"]'
		);
	}

	function isAnyRecurringEnabled() {
		var monthly = findEnabledCheckbox( 'monthly' );
		var annual  = findEnabledCheckbox( 'annual' );
		return ( monthly && monthly.checked ) || ( annual && annual.checked );
	}

	function syncRecurringSelect( select ) {
		if ( ! select ) { return; }
		if ( isAnyRecurringEnabled() ) {
			// Parent treats 'user' as "donor chooses recurring at submit time".
			// Setting it via JS so the form POSTs the correct value lets parent's
			// own save handler write _wps_sfw_product='yes' / _wps_sfw_users='user'
			// naturally — no race conditions for our save() to fight.
			if ( 'user' !== select.value ) {
				select.value = 'user';
				// Fire change so any parent JS observing the select reacts.
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			select.disabled = true;
			select.setAttribute( 'data-dfwc-locked', 'true' );
			showLockHint( select, true );
		} else {
			select.disabled = false;
			select.removeAttribute( 'data-dfwc-locked' );
			showLockHint( select, false );
		}
	}

	function showLockHint( select, locked ) {
		var existing = document.querySelector( '.dfwc-recurring-lock-hint' );
		if ( ! locked ) {
			if ( existing ) { existing.remove(); }
			return;
		}
		if ( existing ) { return; }
		var hint = document.createElement( 'p' );
		hint.className = 'dfwc-recurring-lock-hint description';
		hint.style.margin = '6px 0 0';
		hint.style.color  = '#646970';
		hint.style.fontStyle = 'italic';
		hint.textContent = 'Auto-managed by Donations for WooCommerce Companion. Disable Monthly and Annually above to unlock.';
		// Append after the select's closest wrapping element so it lands
		// directly under the dropdown regardless of WCS vs WPS SFW markup.
		var anchor = select.closest( 'p, .select-wrapper, div' ) || select.parentNode;
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( hint, anchor.nextSibling );
		}
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

		// Build a small intro line for above our relocated content.
		var intro = document.createElement( 'div' );
		intro.className = 'dfwc-mb__tab-intro';
		intro.innerHTML = ourBox.getAttribute( 'data-tab-intro-html' ) ||
			'<p style="margin:0 0 12px;color:#646970;"><em>Configure your donation intervals and preset amounts here. The parent plugin\'s "Display Type" control below stays in sync automatically.</em></p>';
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

		// v0.6.5: instead of hiding parent's recurring controls, sync them.
		// Find parent's "Display Type" select and bind it to our enable state.
		var parentSelect = findRecurringSelect();
		syncRecurringSelect( parentSelect );

		[ 'monthly', 'annual' ].forEach( function ( key ) {
			var cb = findEnabledCheckbox( key );
			if ( cb ) {
				cb.addEventListener( 'change', function () {
					syncRecurringSelect( findRecurringSelect() );
				} );
			}
		} );

		// Final guarantee: on form submit, re-assert the value. Browsers
		// don't POST a disabled <select>, so we must re-enable it just before
		// submission OR convert it to a hidden input. We re-enable: simpler
		// and preserves UX feedback during fill-out.
		var form = ourBox.closest( 'form' ) || document.querySelector( 'form#post' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				var sel = findRecurringSelect();
				if ( sel && sel.disabled && isAnyRecurringEnabled() ) {
					sel.value    = 'user';
					sel.disabled = false; // disabled fields don't POST
				}
			}, true );
		}
	} );
} )();
