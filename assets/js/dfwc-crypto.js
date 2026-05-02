/**
 * Donations for WooCommerce Companion — donor-side crypto donation flow.
 *
 * Self-contained module (no dependency on dfwc-overlay.js — both modules
 * attach to the same wrapper attributes independently). Lifecycle:
 *
 * 1. On DOMContentLoaded + every dynamic-DOM injection event (Elementor
 *    Pro popup show, generic modal mount, AJAX-loaded campaign embed),
 *    scan the document for `[data-dfwc-overlay-target][data-crypto-enabled="1"]`
 *    wrappers.
 *
 * 2. For each wrapper, mount a "Donate Crypto" button below the cash form
 *    inside the wrapper's `.wc-donation-in-action` scope (after parent's
 *    .row1 amount block, mirroring Phase 14A stock pledge placement).
 *
 * 3. On button click, lazy-load The Giving Block's widget script (only on
 *    first click, never on page load). While loading, button shows a
 *    spinner.
 *
 * 4. Mount the TGB widget into a container directly below the button.
 *    TGB's widget owns the iframe inside that container.
 *
 * 5. Bridge: when the donor commits in the widget, TGB's widget calls
 *    `window.dfwcTgbCommit({donation_id, currency, amount_crypto,
 *    amount_usd, project_id?, donor_email_hashed?})`. Our bridge POSTs
 *    those fields to /dfwc-companion/v1/crypto-pending-order, which
 *    (in 13.C) creates an on-hold WC order and returns the WC order id.
 *
 * 6. UI swaps to a "Thanks — awaiting confirmation" message regardless of
 *    POST outcome. POST failures are logged to console; the donor's TGB
 *    transaction is independent of our order recording.
 *
 * Idempotency: WeakSet of initialized wrappers. Cloned wrappers (popup
 * builders) are different DOM nodes and get fresh init.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
( function () {
	'use strict';

	var initialized = ( typeof WeakSet === 'function' ) ? new WeakSet() : null;

	// TGB widget script URLs. Filterable via wp_localize_script's
	// dfwcCompanion.tgb.widgetScriptUrl{Sandbox,Production} so admins on
	// hosted-private TGB deployments can override without forking us.
	var DEFAULT_SCRIPT_URLS = {
		sandbox:    'https://widget.sandbox.tgbwidget.com/v2/tgbWidget.js',
		production: 'https://widget.tgbwidget.com/v2/tgbWidget.js',
	};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	function initAll() {
		var nodes = document.querySelectorAll( '[data-dfwc-overlay-target][data-crypto-enabled="1"]' );
		Array.prototype.forEach.call( nodes, init );
	}

	function startObserver() {
		if ( typeof MutationObserver !== 'function' || ! document.body ) { return; }
		var observer = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;
				for ( var j = 0; j < added.length; j++ ) {
					var node = added[ j ];
					if ( ! node || node.nodeType !== 1 ) { continue; }
					var matches = node.matches && node.matches( '[data-dfwc-overlay-target][data-crypto-enabled="1"]' );
					var contains = node.querySelector && node.querySelector( '[data-dfwc-overlay-target][data-crypto-enabled="1"]' );
					if ( matches || contains ) {
						initAll();
						return;
					}
				}
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	ready( function () {
		initAll();
		startObserver();
	} );

	// Same belt-and-suspenders we use in dfwc-overlay.js for popup builders
	// that recycle DOM via class-flip rather than appending a fresh subtree.
	document.addEventListener( 'elementor/popup/show', function () {
		setTimeout( initAll, 0 );
	} );

	function init( wrapper ) {
		if ( initialized && initialized.has( wrapper ) ) { return; }

		var scope = wrapper.querySelector( '.wc-donation-in-action' );
		if ( ! scope ) { return; }

		// Strip any stale crypto DOM left over from cloning (matches the
		// stale-clone-cleanup pattern from v2.2.4 in dfwc-overlay.js).
		var staleCrypto = scope.querySelectorAll( '.dfwc-crypto, .dfwc-crypto__widget-mount' );
		for ( var s = 0; s < staleCrypto.length; s++ ) {
			if ( staleCrypto[ s ].parentNode ) {
				staleCrypto[ s ].parentNode.removeChild( staleCrypto[ s ] );
			}
		}

		var cfg = {
			environment:  wrapper.getAttribute( 'data-tgb-environment' ) || 'sandbox',
			orgId:        wrapper.getAttribute( 'data-tgb-org-id' ) || '',
			projectId:    wrapper.getAttribute( 'data-tgb-project-id' ) || '',
			pendingUrl:   wrapper.getAttribute( 'data-crypto-pending-url' ) || '',
			campaignId:   parseInt( wrapper.getAttribute( 'data-campaign-id' ), 10 ) || 0,
		};

		// Bail if no org_id is configured — without it we can't even mount
		// the widget. Console-warn so the admin sees the diagnostic in dev tools.
		if ( '' === cfg.orgId ) {
			if ( window.console && console.warn ) {
				console.warn( '[dfwc-crypto] data-tgb-org-id is empty; skipping crypto button render. Configure under WooCommerce → Donations Companion → Crypto Donations.' );
			}
			if ( initialized ) { initialized.add( wrapper ); }
			return;
		}

		var i18n = ( window.dfwcCompanion && window.dfwcCompanion.i18n ) || {};
		var container = buildContainer( i18n, cfg );

		// Mount below parent's amount block (after the cash form), matching
		// Phase 14A stock pledge placement.
		var amountBlock = scope.querySelector( '.row1' );
		if ( amountBlock && amountBlock.parentNode ) {
			amountBlock.parentNode.insertBefore( container, amountBlock.nextSibling );
		} else {
			scope.appendChild( container );
		}

		bindButton( container, cfg, i18n );

		if ( initialized ) { initialized.add( wrapper ); }
	}

	function buildContainer( i18n, cfg ) {
		var container = document.createElement( 'div' );
		container.className = 'dfwc-crypto';
		container.setAttribute( 'data-dfwc-crypto', '' );

		var divider = document.createElement( 'div' );
		divider.className = 'dfwc-crypto__divider';
		divider.textContent = i18n.cryptoDivider || 'or donate non-cash';
		container.appendChild( divider );

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'dfwc-crypto__toggle';
		button.setAttribute( 'data-dfwc-crypto-toggle', '' );
		button.setAttribute( 'aria-expanded', 'false' );
		button.textContent = i18n.donateCrypto || 'Donate Crypto';
		container.appendChild( button );

		var mount = document.createElement( 'div' );
		mount.className = 'dfwc-crypto__widget-mount';
		mount.setAttribute( 'data-dfwc-crypto-widget', '' );
		mount.hidden = true;
		container.appendChild( mount );

		var status = document.createElement( 'div' );
		status.className = 'dfwc-crypto__status';
		status.setAttribute( 'data-dfwc-crypto-status', '' );
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		container.appendChild( status );

		return container;
	}

	function bindButton( container, cfg, i18n ) {
		var button = container.querySelector( '[data-dfwc-crypto-toggle]' );
		var mount  = container.querySelector( '[data-dfwc-crypto-widget]' );
		var status = container.querySelector( '[data-dfwc-crypto-status]' );

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.classList.add( 'dfwc-crypto__toggle--loading' );
			button.textContent = i18n.cryptoLoading || 'Loading…';

			loadWidgetScript( cfg.environment ).then(
				function () {
					button.style.display = 'none';
					mount.hidden = false;
					mountWidget( mount, cfg, status, i18n );
				},
				function ( err ) {
					button.disabled = false;
					button.classList.remove( 'dfwc-crypto__toggle--loading' );
					button.textContent = i18n.donateCrypto || 'Donate Crypto';
					showStatus( status, 'error', i18n.cryptoLoadError || 'Could not load the donation widget. Please try again.' );
					if ( window.console && console.error ) {
						console.error( '[dfwc-crypto] widget script load failed', err );
					}
				}
			);
		} );

		// Cross-instance commit bridge. TGB widget calls window.dfwcTgbCommit
		// when the donor commits. We attach a lazy listener that POSTs to
		// our pending-order endpoint and swaps the UI to a thanks message.
		// Lazy-attached so multiple wrappers on a page each get their own.
		container.addEventListener( 'dfwc:tgb:commit', function ( event ) {
			handleCommit( event.detail || {}, cfg, status, mount, i18n );
		} );
	}

	function loadWidgetScript( environment ) {
		var localizeUrls = ( window.dfwcCompanion && window.dfwcCompanion.tgb ) || {};
		var url = ( 'production' === environment )
			? ( localizeUrls.widgetScriptUrlProduction || DEFAULT_SCRIPT_URLS.production )
			: ( localizeUrls.widgetScriptUrlSandbox    || DEFAULT_SCRIPT_URLS.sandbox );

		// Reuse a shared promise per URL so multiple wrappers on the same
		// page only fetch once.
		window.__dfwcTgbScriptPromises = window.__dfwcTgbScriptPromises || {};
		if ( window.__dfwcTgbScriptPromises[ url ] ) {
			return window.__dfwcTgbScriptPromises[ url ];
		}

		window.__dfwcTgbScriptPromises[ url ] = new Promise( function ( resolve, reject ) {
			var script = document.createElement( 'script' );
			script.src = url;
			script.async = true;
			script.onload = function () { resolve(); };
			script.onerror = function () { reject( new Error( 'TGB widget script failed to load: ' + url ) ); };
			document.head.appendChild( script );
		} );

		// Install the global commit bridge once. TGB's widget calls this
		// from inside its iframe via postMessage handlers it sets up. We
		// dispatch a custom DOM event to whichever container is currently
		// active so per-wrapper handlers can pick it up.
		if ( ! window.dfwcTgbCommit ) {
			window.dfwcTgbCommit = function ( payload ) {
				var active = document.querySelector( '.dfwc-crypto .dfwc-crypto__widget-mount:not([hidden])' );
				if ( ! active ) { return; }
				var container = active.closest( '.dfwc-crypto' );
				if ( container ) {
					container.dispatchEvent( new CustomEvent( 'dfwc:tgb:commit', { detail: payload } ) );
				}
			};
		}

		return window.__dfwcTgbScriptPromises[ url ];
	}

	function mountWidget( mount, cfg, status, i18n ) {
		// TGB widget API surface — exact constructor name + options shape
		// to be confirmed at integration time. Best-effort guess based on
		// industry-standard nonprofit widget patterns. Admins integrating
		// against a different TGB widget version can override this via
		// window.dfwcMountTgbWidget(mountEl, cfg) before this script runs.
		if ( typeof window.dfwcMountTgbWidget === 'function' ) {
			try { window.dfwcMountTgbWidget( mount, cfg ); }
			catch ( e ) {
				showStatus( status, 'error', i18n.cryptoMountError || 'Could not mount the donation widget.' );
				if ( window.console && console.error ) { console.error( '[dfwc-crypto] custom mount failed', e ); }
			}
			return;
		}

		if ( typeof window.tgbWidget === 'function' ) {
			try {
				window.tgbWidget( {
					container:    mount,
					organization: cfg.orgId,
					project:      cfg.projectId,
					environment:  cfg.environment,
					onCommit:     function ( payload ) {
						mount.dispatchEvent( new CustomEvent( 'dfwc:tgb:commit', { detail: payload, bubbles: true } ) );
					},
				} );
			} catch ( e ) {
				showStatus( status, 'error', i18n.cryptoMountError || 'Could not mount the donation widget.' );
				if ( window.console && console.error ) { console.error( '[dfwc-crypto] tgbWidget mount failed', e ); }
			}
			return;
		}

		// Last-resort fallback — TGB script loaded but didn't expose the
		// expected globals. Show a manual link to the org's TGB hosted page
		// if one was provided via localize.
		var hostedUrl = ( window.dfwcCompanion && window.dfwcCompanion.tgb && window.dfwcCompanion.tgb.hostedUrl ) || '';
		if ( '' !== hostedUrl ) {
			var link = document.createElement( 'a' );
			link.href   = hostedUrl;
			link.target = '_blank';
			link.rel    = 'noopener noreferrer';
			link.className = 'dfwc-crypto__hosted-link';
			link.textContent = i18n.cryptoHostedFallback || 'Open the donation page';
			mount.appendChild( link );
		} else {
			showStatus( status, 'error', i18n.cryptoMountError || 'Could not mount the donation widget.' );
		}
	}

	function handleCommit( payload, cfg, status, mount, i18n ) {
		showStatus( status, 'pending', i18n.cryptoPending || 'Donation submitted. Awaiting on-chain confirmation…' );

		if ( '' === cfg.pendingUrl || ! payload || ! payload.donation_id ) {
			// 13.C will land the REST endpoint; until then, log and stop.
			if ( window.console && console.info ) {
				console.info( '[dfwc-crypto] pending order POST skipped (endpoint pending or payload missing)', payload );
			}
			return;
		}

		var nonce = ( window.dfwcCompanion && window.dfwcCompanion.trackNonce ) || '';
		fetch( cfg.pendingUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				donation_id:    payload.donation_id,
				campaign_id:    cfg.campaignId,
				project_id:     cfg.projectId,
				currency:       payload.currency || '',
				amount_crypto:  payload.amount_crypto || 0,
				amount_usd:     payload.amount_usd || 0,
			} ),
		} ).then(
			function ( response ) {
				if ( response.ok ) {
					showStatus( status, 'success', i18n.cryptoSuccess || 'Thanks! We\'ll email you when the donation is fully confirmed on-chain.' );
				} else {
					// Order recording failed but the TGB transaction itself
					// is independent. Surface a softer message — donor's
					// crypto donation IS still going through.
					showStatus( status, 'warning', i18n.cryptoRecordingError || 'Donation received by The Giving Block. Order recording on our side delayed; we\'ll reconcile via the webhook.' );
					if ( window.console && console.error ) {
						console.error( '[dfwc-crypto] pending order POST returned non-ok', response.status );
					}
				}
			},
			function ( err ) {
				showStatus( status, 'warning', i18n.cryptoRecordingError || 'Donation received by The Giving Block. Order recording on our side delayed; we\'ll reconcile via the webhook.' );
				if ( window.console && console.error ) {
					console.error( '[dfwc-crypto] pending order POST failed', err );
				}
			}
		);
	}

	function showStatus( el, kind, message ) {
		el.className = 'dfwc-crypto__status dfwc-crypto__status--' + kind;
		el.textContent = message;
	}
}() );
