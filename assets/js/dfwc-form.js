/**
 * Donations for WooCommerce Companion — donor form behavior.
 *
 * Vanilla IIFE per form root. Multiple instances on a page are isolated
 * by `data-form-uid` and queries scoped to each form element.
 *
 * Phase C: tab switching, preset selection, custom amount, CTA updates.
 * Phase D: AJAX submit handler will replace the stub at the bottom.
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

	function debounce( fn, wait ) {
		var t;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( ctx, args ); }, wait );
		};
	}

	ready( function () {
		var forms = document.querySelectorAll( '[data-dfwc-form]' );
		Array.prototype.forEach.call( forms, init );
	} );

	function init( formEl ) {
		var config = parseConfig( formEl );
		if ( ! config ) { return; }

		var state = {
			interval: formEl.getAttribute( 'data-active-interval' ) || firstKey( config ),
			amount:   readActiveAmount( formEl, formEl.getAttribute( 'data-active-interval' ) || firstKey( config ), config ),
		};

		bindTabs( formEl, config, state );
		bindPresets( formEl, config, state );
		bindCustom( formEl, config, state );
		bindSubmit( formEl, config, state );

		updateCta( formEl, config, state );
	}

	function parseConfig( formEl ) {
		var raw = formEl.getAttribute( 'data-config' );
		if ( ! raw ) { return null; }
		try { return JSON.parse( raw ); }
		catch ( e ) { return null; }
	}

	function firstKey( obj ) {
		for ( var k in obj ) { if ( Object.prototype.hasOwnProperty.call( obj, k ) ) { return k; } }
		return '';
	}

	function readActiveAmount( formEl, intervalKey, config ) {
		var panel = formEl.querySelector( '[data-dfwc-panel="' + intervalKey + '"]' );
		if ( ! panel ) { return 0; }
		var checked = panel.querySelector( '[data-dfwc-preset]:checked' );
		if ( checked ) { return parseFloat( checked.getAttribute( 'data-amount' ) ) || 0; }
		var custom = panel.querySelector( '[data-dfwc-custom]' );
		if ( custom && custom.value ) { return clamp( parseFloat( custom.value ) || 0, intervalKey, config ); }
		return 0;
	}

	function clamp( value, intervalKey, config ) {
		var entry = config[ intervalKey ];
		if ( ! entry || isNaN( value ) ) { return 0; }
		if ( value < entry.min ) { return entry.min; }
		if ( value > entry.max ) { return entry.max; }
		return value;
	}

	function bindTabs( formEl, config, state ) {
		var tabs = formEl.querySelectorAll( '[data-dfwc-tab]' );
		Array.prototype.forEach.call( tabs, function ( tab ) {
			tab.addEventListener( 'click', function () {
				if ( tab.disabled || tab.getAttribute( 'aria-disabled' ) === 'true' ) { return; }
				switchTo( formEl, config, state, tab.getAttribute( 'data-dfwc-tab' ) );
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				if ( e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' ) { return; }
				e.preventDefault();
				var siblings = Array.prototype.filter.call( tabs, function ( t ) {
					return ! t.disabled && t.getAttribute( 'aria-disabled' ) !== 'true';
				} );
				var idx = siblings.indexOf( tab );
				if ( idx < 0 ) { return; }
				var nextIdx = e.key === 'ArrowRight' ? ( idx + 1 ) % siblings.length
				                                    : ( idx - 1 + siblings.length ) % siblings.length;
				siblings[ nextIdx ].focus();
				switchTo( formEl, config, state, siblings[ nextIdx ].getAttribute( 'data-dfwc-tab' ) );
			} );
		} );
	}

	function switchTo( formEl, config, state, intervalKey ) {
		if ( ! config[ intervalKey ] ) { return; }
		state.interval = intervalKey;

		// Toggle tab states.
		Array.prototype.forEach.call( formEl.querySelectorAll( '[data-dfwc-tab]' ), function ( t ) {
			var isActive = t.getAttribute( 'data-dfwc-tab' ) === intervalKey;
			t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			t.setAttribute( 'tabindex', isActive ? '0' : '-1' );
			t.classList.toggle( 'dfwc-form__tab--active', isActive );
		} );

		// Toggle panel visibility.
		Array.prototype.forEach.call( formEl.querySelectorAll( '[data-dfwc-panel]' ), function ( p ) {
			if ( p.getAttribute( 'data-dfwc-panel' ) === intervalKey ) { p.removeAttribute( 'hidden' ); }
			else { p.setAttribute( 'hidden', '' ); }
		} );

		state.amount = readActiveAmount( formEl, intervalKey, config );
		updateCta( formEl, config, state );
	}

	function bindPresets( formEl, config, state ) {
		formEl.addEventListener( 'change', function ( e ) {
			var input = e.target;
			if ( ! input || input.getAttribute( 'data-dfwc-preset' ) === null ) { return; }

			var panel = input.closest( '[data-dfwc-panel]' );
			if ( ! panel ) { return; }
			var intervalKey = panel.getAttribute( 'data-dfwc-panel' );
			if ( intervalKey !== state.interval ) { return; }

			// Clear the panel's custom-amount input when a preset is picked.
			var custom = panel.querySelector( '[data-dfwc-custom]' );
			if ( custom ) { custom.value = ''; }

			state.amount = parseFloat( input.getAttribute( 'data-amount' ) ) || 0;
			updateCta( formEl, config, state );
		} );
	}

	function bindCustom( formEl, config, state ) {
		var customs = formEl.querySelectorAll( '[data-dfwc-custom]' );
		Array.prototype.forEach.call( customs, function ( input ) {
			var panel = input.closest( '[data-dfwc-panel]' );
			if ( ! panel ) { return; }
			var intervalKey = panel.getAttribute( 'data-dfwc-panel' );

			var onInput = debounce( function () {
				if ( intervalKey !== state.interval ) { return; }
				clearPresetSelection( panel );
				state.amount = parseFloat( input.value ) || 0;
				updateCta( formEl, config, state );
			}, 200 );

			input.addEventListener( 'input', onInput );

			input.addEventListener( 'blur', function () {
				if ( '' === input.value ) { return; }
				var clamped = clamp( parseFloat( input.value ) || 0, intervalKey, config );
				if ( String( clamped ) !== input.value ) { input.value = String( clamped ); }
				if ( intervalKey === state.interval ) {
					state.amount = clamped;
					updateCta( formEl, config, state );
				}
			} );
		} );
	}

	function clearPresetSelection( panel ) {
		Array.prototype.forEach.call( panel.querySelectorAll( '[data-dfwc-preset]' ), function ( r ) { r.checked = false; } );
	}

	function updateCta( formEl, config, state ) {
		var cta = formEl.querySelector( '[data-dfwc-cta]' );
		if ( ! cta ) { return; }
		var entry = config[ state.interval ];
		if ( ! entry ) { return; }

		var template = entry.cta_template || '';
		var amount = state.amount;
		var disabled = ! amount || amount <= 0;
		cta.disabled = disabled;
		cta.setAttribute( 'aria-disabled', disabled ? 'true' : 'false' );

		cta.innerHTML = renderCta( template, amount, state.interval );
	}

	/**
	 * Mirror of Renderer::format_cta() — substitutes {amount} and {interval}
	 * tokens. Currency formatting uses Intl.NumberFormat with the locale +
	 * currency from the global localize.
	 */
	function renderCta( template, amount, intervalKey ) {
		var locale = ( window.dfwcCompanion && window.dfwcCompanion.locale ) || ( navigator.language || 'en-US' );
		var currency = ( window.dfwcCompanion && window.dfwcCompanion.currency ) || 'USD';
		var formatted;
		try {
			formatted = new Intl.NumberFormat( locale, {
				style: 'currency',
				currency: currency,
				maximumFractionDigits: 2,
			} ).format( amount || 0 );
		} catch ( e ) {
			formatted = String( amount || 0 );
		}

		var i18n = ( window.dfwcCompanion && window.dfwcCompanion.i18n ) || {};
		var intervalSuffix = '';
		if ( intervalKey === 'monthly' )      { intervalSuffix = i18n.monthly || '/month'; }
		else if ( intervalKey === 'annual' ) { intervalSuffix = i18n.annual  || '/year'; }

		// Strip unknown tokens from admin-supplied templates as a defense in depth.
		return String( template )
			.split( '{amount}' ).join( escapeHtml( formatted ) )
			.split( '{interval}' ).join( escapeHtml( intervalSuffix ) )
			.replace( /\{[a-z_]+\}/g, '' );
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function bindSubmit( formEl, config, state ) {
		formEl.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submit( formEl, config, state );
		} );
	}

	function submit( formEl, config, state ) {
		var i18n = ( window.dfwcCompanion && window.dfwcCompanion.i18n ) || {};

		if ( ! state.amount || state.amount <= 0 ) {
			showError( formEl, i18n.errorAmountRequired || 'Please choose an amount.' );
			return;
		}

		var entry = config[ state.interval ];
		if ( entry ) {
			if ( state.amount < entry.min || state.amount > entry.max ) {
				showError( formEl, i18n.errorAmountRequired || 'Please choose an amount within the allowed range.' );
				return;
			}
		}

		showError( formEl, '' );
		setLoading( formEl, true );

		postDonation( buildPayload( formEl, state ) )
			.then( function ( response ) { handleResponse( response, formEl ); } )
			.catch( function ( err ) {
				var msg = err && err.isNetwork ? ( i18n.errorNetwork || 'Network error.' ) : ( i18n.errorGeneric || 'Something went wrong.' );
				showError( formEl, msg );
			} )
			.then( function () { setLoading( formEl, false ); } );
	}

	function buildPayload( formEl, state ) {
		var fd = new FormData();
		var ctx = window.dfwcCompanion || {};

		fd.append( 'action',      ctx.action || 'donation_to_order' );
		fd.append( 'nonce',       ctx.nonce  || '' );
		fd.append( 'campaign_id', String( formEl.getAttribute( 'data-campaign-id' ) || '0' ) );
		fd.append( 'amount',      String( state.amount ) );

		if ( state.interval === 'one_time' ) {
			fd.append( 'is_recurring', 'no' );
			return fd;
		}

		// Recurring — send BOTH WCS-style and WPS-SFW-style key sets. Parent
		// reads only the relevant set based on its own runtime engine check.
		// We don't know the linked-product type at render time without an
		// extra DB query; sending both is harmless. (Master plan deviation D2.)
		var period = state.interval === 'monthly' ? 'month' : 'year';

		fd.append( 'is_recurring',  'yes' );
		fd.append( 'new_period',    period );
		fd.append( 'new_interval',  '1' );
		fd.append( 'new_length',    '0' ); // 0 = open-ended in WCS

		fd.append( 'wps_sfw_subscription_number',          '1' );
		fd.append( 'wps_sfw_subscription_interval',        period );
		fd.append( 'wps_sfw_subscription_expiry_number',   '' ); // empty = open-ended in WPS SFW
		fd.append( 'wps_sfw_subscription_expiry_interval', '' );

		return fd;
	}

	function postDonation( formData ) {
		var ctx = window.dfwcCompanion || {};
		var url = ctx.ajaxUrl || '';
		var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		var timer = controller ? setTimeout( function () { controller.abort(); }, 30000 ) : 0;

		return fetch( url, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json' },
			signal: controller ? controller.signal : undefined,
		} )
			.catch( function ( err ) {
				err.isNetwork = true;
				throw err;
			} )
			.then( function ( res ) {
				if ( timer ) { clearTimeout( timer ); }
				if ( ! res.ok ) {
					return res.text().then( function ( body ) {
						return parseResponseText( body, res.status );
					} );
				}
				return res.text().then( function ( body ) { return parseResponseText( body, res.status ); } );
			} );
	}

	function parseResponseText( body, status ) {
		try {
			var parsed = JSON.parse( body );
			// wp_send_json_error wraps the payload in { success: false, data: {...} }.
			// Parent's own error path emits a flat object { response, message }.
			// Normalize to a flat shape for handleResponse().
			if ( parsed && typeof parsed === 'object' && Object.prototype.hasOwnProperty.call( parsed, 'success' ) ) {
				var data = parsed.data || {};
				return Object.assign( { response: parsed.success ? 'success' : 'error' }, data );
			}
			return parsed;
		} catch ( e ) {
			var err = new Error( 'Invalid response (HTTP ' + status + ')' );
			err.isNetwork = false;
			throw err;
		}
	}

	function handleResponse( response, formEl ) {
		if ( response && response.response === 'success' ) {
			var ctx = window.dfwcCompanion || {};
			var redirect = response.cart_url || response.redirect || ctx.cartUrl || '/cart/';
			window.location.href = redirect;
			return;
		}
		var i18n = ( window.dfwcCompanion && window.dfwcCompanion.i18n ) || {};
		var msg = ( response && response.message ) || i18n.errorGeneric || 'Something went wrong.';
		showError( formEl, msg );
	}

	function showError( formEl, message ) {
		var node = formEl.querySelector( '[data-dfwc-error]' );
		if ( ! node ) { return; }
		if ( ! message ) {
			node.textContent = '';
			node.setAttribute( 'hidden', '' );
			return;
		}
		// textContent (not innerHTML) — defense against XSS via response message.
		node.textContent = message;
		node.removeAttribute( 'hidden' );
	}

	function setLoading( formEl, isLoading ) {
		var cta = formEl.querySelector( '[data-dfwc-cta]' );
		if ( ! cta ) { return; }
		if ( isLoading ) {
			formEl.setAttribute( 'aria-busy', 'true' );
			if ( ! cta.dataset.dfwcOriginalLabel ) {
				cta.dataset.dfwcOriginalLabel = cta.innerHTML;
			}
			cta.disabled = true;
			cta.setAttribute( 'aria-disabled', 'true' );
			cta.innerHTML = '<span class="dfwc-form__spinner" aria-hidden="true"></span>';
		} else {
			formEl.removeAttribute( 'aria-busy' );
			cta.disabled = false;
			cta.removeAttribute( 'aria-disabled' );
			if ( cta.dataset.dfwcOriginalLabel ) {
				cta.innerHTML = cta.dataset.dfwcOriginalLabel;
				delete cta.dataset.dfwcOriginalLabel;
			}
		}
	}
} )();
