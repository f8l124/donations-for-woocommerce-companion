/**
 * Donations for WooCommerce Companion — donor-form augmentation overlay.
 *
 * Lifecycle: parent plugin renders its full form (cause selector, gift aid,
 * processing fee, tributes, donor-wall integration, etc.) inside `.wc-donation-in-action`.
 * On DOM ready, we find each `[data-dfwc-overlay-target]` instance, locate
 * parent's amount + recurring controls within, hide them, and mount our
 * interval-first UI in the same place. As the donor changes our tabs / picks
 * a preset / types a custom amount, we WRITE the resulting state into parent's
 * existing hidden inputs and recurring controls. Parent's existing submit
 * handler at `assets/js/frontend.js:470` reads from those same inputs and
 * does the AJAX. We never re-implement parent's POST shape.
 *
 * Defensive throughout: every parent selector is wrapped in null-safe checks.
 * If any expected element is missing (parent restructures markup in a future
 * release), the overlay logs a console warning and bails — donor sees parent's
 * unmodified form, which is functional, just without our interval-first UX.
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
		var targets = document.querySelectorAll( '[data-dfwc-overlay-target]' );
		Array.prototype.forEach.call( targets, init );
	} );

	function init( wrapper ) {
		var scope = wrapper.querySelector( '.wc-donation-in-action' );
		if ( ! scope ) {
			console.warn( '[dfwc] .wc-donation-in-action not found inside overlay target — parent shortcode produced no form. Bailing.' );
			return;
		}

		var config;
		try {
			config = JSON.parse( wrapper.getAttribute( 'data-config' ) || '{}' );
		} catch ( e ) {
			console.warn( '[dfwc] failed to parse data-config JSON; bailing', e );
			return;
		}

		var enabledIntervals;
		try {
			enabledIntervals = JSON.parse( wrapper.getAttribute( 'data-intervals' ) || '[]' );
		} catch ( e ) { enabledIntervals = []; }
		if ( ! enabledIntervals.length ) {
			enabledIntervals = Object.keys( config );
		}
		if ( ! enabledIntervals.length ) {
			console.warn( '[dfwc] no enabled intervals in data-config; bailing' );
			return;
		}

		var display;
		try {
			display = JSON.parse( wrapper.getAttribute( 'data-display' ) || '{}' );
		} catch ( e ) { display = {}; }
		// Defaults match Config_Resolver::display_defaults() — show everything,
		// don't override the cause heading.
		if ( typeof display.show_title    !== 'boolean' ) { display.show_title    = true; }
		if ( typeof display.show_image    !== 'boolean' ) { display.show_image    = true; }
		if ( typeof display.cause_heading !== 'string'  ) { display.cause_heading = ''; }

		var campaignId = parseInt( wrapper.getAttribute( 'data-campaign-id' ), 10 ) || 0;
		var engine     = wrapper.getAttribute( 'data-engine' ) || 'none';
		var initialKey = wrapper.getAttribute( 'data-active-interval' ) || enabledIntervals[ 0 ];
		if ( enabledIntervals.indexOf( initialKey ) < 0 ) { initialKey = enabledIntervals[ 0 ]; }

		// Locate parent's hidden inputs and controls. Every selector is scoped
		// to .wc-donation-in-action to keep multi-instance pages isolated.
		var parentEls = locateParent( scope );
		if ( ! parentEls.priceInput ) {
			console.warn( '[dfwc] parent hidden price input not found; bailing' );
			return;
		}

		// Build our overlay UI.
		var ui = buildOverlayUi( config, enabledIntervals, initialKey, campaignId );

		// Insert our UI in parent's natural amount-block position so the
		// admin's "Frontend Ordering" choice (Cause → Amount → Subscription
		// → Tribute → ...) is preserved. Parent's amount block is always
		// `.row1` per its template; if absent (custom theme override), fall
		// back to prepending so the donor still sees the overlay.
		var amountBlock = scope.querySelector( '.row1' );
		if ( amountBlock && amountBlock.parentNode ) {
			amountBlock.parentNode.insertBefore( ui.root, amountBlock );
		} else {
			scope.insertBefore( ui.root, scope.firstChild );
		}

		// Hide parent's amount block(s) and recurring block(s) AFTER our UI
		// is inserted so the visible flow goes:
		//   [parent's blocks above amount] → [our overlay] → [parent's blocks below amount]
		hideAll( scope.querySelectorAll( '.row1' ) );
		hideAll( scope.querySelectorAll( '.donation_subscription' ) );
		hideAll( scope.querySelectorAll( '.wc_donation_subscription_table' ) );
		hideAll( scope.querySelectorAll( '.subscription-options' ) );

		// Apply admin's display options (campaign title, image, cause heading).
		applyDisplay( scope, display );

		var state = {
			interval:    initialKey,
			amount:      0,
			customByKey: {}, // remember custom amount per panel for tab-switching UX
		};

		// Write initial state.
		var initialEntry = config[ initialKey ];
		if ( initialEntry && initialEntry.presets && initialEntry.presets.length ) {
			var idx = Math.max( 0, Math.min( initialEntry.default_index | 0, initialEntry.presets.length - 1 ) );
			state.amount = initialEntry.presets[ idx ].amount || 0;
			var radio = ui.root.querySelector( '[data-dfwc-preset][data-interval="' + initialKey + '"][data-idx="' + idx + '"]' );
			if ( radio ) { radio.checked = true; }
		}

		applyState( state, parentEls, config, ui );

		// Bind tab switches.
		Array.prototype.forEach.call( ui.tabs, function ( tab ) {
			tab.addEventListener( 'click', function () {
				if ( tab.disabled || tab.getAttribute( 'aria-disabled' ) === 'true' ) { return; }
				var key = tab.getAttribute( 'data-dfwc-tab' );
				switchTo( key, state, parentEls, config, ui );
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				if ( e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' ) { return; }
				e.preventDefault();
				var siblings = Array.prototype.filter.call( ui.tabs, function ( t ) {
					return ! t.disabled && t.getAttribute( 'aria-disabled' ) !== 'true';
				} );
				var i = siblings.indexOf( tab );
				if ( i < 0 ) { return; }
				var next = e.key === 'ArrowRight' ? ( i + 1 ) % siblings.length : ( i - 1 + siblings.length ) % siblings.length;
				siblings[ next ].focus();
				switchTo( siblings[ next ].getAttribute( 'data-dfwc-tab' ), state, parentEls, config, ui );
			} );
		} );

		// Bind preset radios.
		ui.root.addEventListener( 'change', function ( e ) {
			var input = e.target;
			if ( ! input || input.getAttribute( 'data-dfwc-preset' ) === null ) { return; }
			var intervalKey = input.getAttribute( 'data-interval' );
			if ( intervalKey !== state.interval ) { return; }

			// Clear our custom-amount input for this panel.
			var custom = ui.root.querySelector( '[data-dfwc-custom][data-interval="' + intervalKey + '"]' );
			if ( custom ) {
				custom.value = '';
				state.customByKey[ intervalKey ] = '';
			}

			state.amount = parseFloat( input.getAttribute( 'data-amount' ) ) || 0;
			applyState( state, parentEls, config, ui );
		} );

		// Bind custom amount inputs (debounced + clamp on blur).
		Array.prototype.forEach.call( ui.root.querySelectorAll( '[data-dfwc-custom]' ), function ( inp ) {
			var intervalKey = inp.getAttribute( 'data-interval' );

			var onInput = debounce( function () {
				if ( intervalKey !== state.interval ) { return; }
				clearPresetSelection( ui.root, intervalKey );
				state.customByKey[ intervalKey ] = inp.value;
				state.amount = parseFloat( inp.value ) || 0;
				applyState( state, parentEls, config, ui );
			}, 200 );

			inp.addEventListener( 'input', onInput );

			inp.addEventListener( 'blur', function () {
				if ( '' === inp.value ) { return; }
				var clamped = clampAmount( parseFloat( inp.value ) || 0, intervalKey, config );
				if ( String( clamped ) !== inp.value ) { inp.value = String( clamped ); }
				if ( intervalKey === state.interval ) {
					state.customByKey[ intervalKey ] = inp.value;
					state.amount = clamped;
					applyState( state, parentEls, config, ui );
				}
			} );
		} );

		// Capture-phase guard on parent's submit button: if amount is invalid,
		// halt before parent's delegated handler runs.
		if ( parentEls.submitBtn ) {
			parentEls.submitBtn.addEventListener( 'click', function ( e ) {
				if ( state.amount > 0 && amountInRange( state.amount, state.interval, config ) ) {
					return; // let parent's delegated handler fire
				}
				e.stopImmediatePropagation();
				e.preventDefault();
				showError( ui.root, ( window.dfwcCompanion && window.dfwcCompanion.i18n && window.dfwcCompanion.i18n.errorAmountRequired ) || 'Please choose an amount.' );
			}, true );
		}
	}

	function locateParent( scope ) {
		// Two hidden price inputs share `name="wc-donation-price"` — one with a
		// .donate_<id>_<rand> class (canonical write target), one without.
		// Mirror writes to both to be safe.
		var priceInputs = scope.querySelectorAll( 'input[name="wc-donation-price"]' );

		return {
			priceInput:                 priceInputs[ 0 ] || null,
			priceInputs:                priceInputs,
			causeInput:                 scope.querySelector( 'input[name="wc-donation-cause"]' ),
			campaignIdInput:            scope.querySelector( '.wc_donation_camp' ),
			randIdInput:                scope.querySelector( '.wp_rand' ),
			recurringCheckbox:          scope.querySelector( '.donation-is-recurring' ),
			selectedLabelInput:         scope.querySelector( 'input[name="selectedLabel"]' ), // may not exist; we'll create
			subPeriodInterval:          scope.querySelector( 'select[name="_subscription_period_interval"]' ),
			subPeriod:                  scope.querySelector( 'select[name="_subscription_period"]' ),
			subLength:                  scope.querySelector( 'select[name="_subscription_length"]' ),
			wpsNumber:                  scope.querySelector( '.wps_sfw_subscription_number' ),
			wpsInterval:                scope.querySelector( '.wps_sfw_subscription_interval' ),
			wpsExpiryNumber:            scope.querySelector( '.wps_sfw_subscription_expiry_number' ),
			wpsExpiryInterval:          scope.querySelector( '.wps_sfw_subscription_expiry_interval' ),
			submitBtn:                  scope.querySelector( '.wc-donation-f-submit-donation' ),
			scope:                      scope,
		};
	}

	function buildOverlayUi( config, enabledIntervals, initialKey, campaignId ) {
		var labelMap = {
			one_time: 'One-time',
			monthly:  'Monthly',
			annual:   'Annually',
		};

		var root = document.createElement( 'div' );
		root.className = 'dfwc-overlay__root';
		root.setAttribute( 'data-dfwc-overlay-root', '' );

		// Tabs.
		var tabs = document.createElement( 'div' );
		tabs.className = 'dfwc-overlay__tabs';
		tabs.setAttribute( 'role', 'tablist' );

		var allKeys = [ 'one_time', 'monthly', 'annual' ];
		var tabEls = [];
		allKeys.forEach( function ( key ) {
			var enabled = enabledIntervals.indexOf( key ) >= 0;
			var btn     = document.createElement( 'button' );
			btn.type    = 'button';
			btn.className = 'dfwc-overlay__tab' + ( key === initialKey ? ' dfwc-overlay__tab--active' : '' );
			btn.setAttribute( 'data-dfwc-tab', key );
			btn.setAttribute( 'role', 'tab' );
			btn.setAttribute( 'aria-selected', key === initialKey ? 'true' : 'false' );
			btn.setAttribute( 'tabindex', key === initialKey ? '0' : '-1' );
			if ( ! enabled ) {
				btn.disabled = true;
				btn.setAttribute( 'aria-disabled', 'true' );
			}
			btn.textContent = ( config[ key ] && config[ key ].label ) || labelMap[ key ] || key;
			tabs.appendChild( btn );
			tabEls.push( btn );
		} );

		root.appendChild( tabs );

		// Panels.
		var panels = {};
		enabledIntervals.forEach( function ( key ) {
			var entry = config[ key ];
			if ( ! entry ) { return; }

			var panel = document.createElement( 'div' );
			panel.className = 'dfwc-overlay__panel';
			panel.setAttribute( 'data-dfwc-panel', key );
			if ( key !== initialKey ) { panel.setAttribute( 'hidden', '' ); }

			// Presets.
			if ( entry.presets && entry.presets.length ) {
				var grid = document.createElement( 'div' );
				grid.className = 'dfwc-overlay__presets';
				grid.setAttribute( 'role', 'radiogroup' );
				grid.setAttribute( 'aria-label', 'Donation amounts' );

				// Phase 5: subtitle (per interval) above the preset grid.
				if ( entry.subtitle ) {
					var subtitleEl = document.createElement( 'p' );
					subtitleEl.className = 'dfwc-overlay__subtitle';
					subtitleEl.textContent = entry.subtitle;
					panel.appendChild( subtitleEl );
				}

				var displayMode = entry.impact_display_mode || 'below_button';

				entry.presets.forEach( function ( p, idx ) {
					var presetId = 'dfwc-preset-' + campaignId + '-' + key + '-' + idx;
					var input    = document.createElement( 'input' );
					input.type   = 'radio';
					input.id     = presetId;
					input.name   = 'dfwc_preset_' + campaignId + '_' + key;
					input.value  = String( p.amount );
					input.setAttribute( 'data-dfwc-preset', '' );
					input.setAttribute( 'data-interval', key );
					input.setAttribute( 'data-idx', String( idx ) );
					input.setAttribute( 'data-amount', String( p.amount ) );
					input.setAttribute( 'data-label', p.label || '' );
					input.className = 'dfwc-overlay__preset-input';

					var label = document.createElement( 'label' );
					label.className = 'dfwc-overlay__preset-label';
					label.setAttribute( 'for', presetId );
					if ( p.is_featured ) {
						label.classList.add( 'dfwc-overlay__preset-label--featured' );
					}

					var amt = document.createElement( 'span' );
					amt.className = 'dfwc-overlay__preset-amount';
					amt.textContent = formatCurrency( p.amount );
					label.appendChild( amt );

					if ( p.label ) {
						var tag = document.createElement( 'span' );
						tag.className = 'dfwc-overlay__preset-tag';
						tag.textContent = p.label;
						label.appendChild( tag );
					}

					// Phase 5: featured badge.
					if ( p.is_featured ) {
						var badge = document.createElement( 'span' );
						badge.className = 'dfwc-overlay__featured-badge';
						badge.textContent = ( window.dfwcCompanion && window.dfwcCompanion.i18n && window.dfwcCompanion.i18n.featured ) || 'Most popular';
						label.appendChild( badge );
					}

					// Phase 5: impact label, rendered per chosen display mode.
					if ( p.impact_label ) {
						if ( 'inline' === displayMode ) {
							var inlineImpact = document.createElement( 'span' );
							inlineImpact.className = 'dfwc-overlay__preset-impact-inline';
							inlineImpact.textContent = p.impact_label;
							label.appendChild( inlineImpact );
						} else if ( 'tooltip' === displayMode ) {
							var ttId = 'dfwc-impact-tt-' + campaignId + '-' + key + '-' + idx;
							label.setAttribute( 'data-dfwc-tooltip', p.impact_label );
							input.setAttribute( 'aria-describedby', ttId );
							var srImpact = document.createElement( 'span' );
							srImpact.className = 'dfwc-overlay__sr-only';
							srImpact.id = ttId;
							srImpact.textContent = p.impact_label;
							label.appendChild( srImpact );
						}
						// 'below_button' and 'card' modes append a sibling row outside the label.
					}

					grid.appendChild( input );
					grid.appendChild( label );

					// Below-button impact: full-width row beneath each preset.
					if ( p.impact_label && ( 'below_button' === displayMode || 'card' === displayMode ) ) {
						var impactBelow = document.createElement( 'div' );
						impactBelow.className = 'dfwc-overlay__preset-impact';
						impactBelow.textContent = p.impact_label;
						impactBelow.id = 'dfwc-impact-' + campaignId + '-' + key + '-' + idx;
						input.setAttribute( 'aria-describedby', impactBelow.id );
						grid.appendChild( impactBelow );
					}
				} );

				if ( 'card' === displayMode ) {
					grid.classList.add( 'dfwc-overlay__presets--card' );
				}

				panel.appendChild( grid );
			}

			// Custom amount — only render when admin enabled it for this interval.
			// Default (config flag undefined) is to show, matching pre-0.6.0 behavior.
			var customAllowed = entry.custom_amount_enabled !== false;
			if ( customAllowed ) {
				var customWrap = document.createElement( 'div' );
				customWrap.className = 'dfwc-overlay__custom';
				var customLabel       = document.createElement( 'label' );
				customLabel.className = 'dfwc-overlay__custom-label';
				customLabel.textContent = 'Or enter your own amount';
				customWrap.appendChild( customLabel );

				var customInputWrap = document.createElement( 'div' );
				customInputWrap.className = 'dfwc-overlay__custom-input-wrap';

				var sym = document.createElement( 'span' );
				sym.className = 'dfwc-overlay__currency';
				sym.setAttribute( 'aria-hidden', 'true' );
				sym.textContent = ( window.dfwcCompanion && window.dfwcCompanion.currencySymbol ) || '$';
				customInputWrap.appendChild( sym );

				var input         = document.createElement( 'input' );
				input.type        = 'number';
				input.className   = 'dfwc-overlay__custom-input';
				input.setAttribute( 'data-dfwc-custom', '' );
				input.setAttribute( 'data-interval', key );
				input.setAttribute( 'inputmode', 'decimal' );
				input.setAttribute( 'step', '0.01' );
				input.setAttribute( 'min', String( entry.min ) );
				input.setAttribute( 'max', String( entry.max ) );
				input.setAttribute( 'placeholder', 'Between ' + formatCurrency( entry.min ) + ' and ' + formatCurrency( entry.max ) );
				customInputWrap.appendChild( input );
				customWrap.appendChild( customInputWrap );

				// Phase 5: custom-amount impact label.
				if ( entry.custom_amount_impact_label ) {
					var customImpact = document.createElement( 'p' );
					customImpact.className = 'dfwc-overlay__custom-impact';
					customImpact.textContent = entry.custom_amount_impact_label;
					customWrap.appendChild( customImpact );
				}

				panel.appendChild( customWrap );
			}

			// Phase 5: annual equivalency footer (token replacement updated by applyState).
			if ( entry.annual_equivalency ) {
				var equivWrap = document.createElement( 'p' );
				equivWrap.className = 'dfwc-overlay__equivalency';
				equivWrap.setAttribute( 'data-dfwc-equivalency', key );
				equivWrap.setAttribute( 'data-template', entry.annual_equivalency );
				equivWrap.textContent = ''; // populated by applyState
				panel.appendChild( equivWrap );
			}

			root.appendChild( panel );
			panels[ key ] = panel;
		} );

		var error = document.createElement( 'div' );
		error.className = 'dfwc-overlay__error';
		error.setAttribute( 'role', 'alert' );
		error.setAttribute( 'data-dfwc-error', '' );
		error.setAttribute( 'hidden', '' );
		root.appendChild( error );

		return { root: root, tabs: tabEls, panels: panels };
	}

	function switchTo( key, state, parentEls, config, ui ) {
		if ( ! config[ key ] ) { return; }
		state.interval = key;

		Array.prototype.forEach.call( ui.tabs, function ( t ) {
			var isActive = t.getAttribute( 'data-dfwc-tab' ) === key;
			t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			t.setAttribute( 'tabindex', isActive ? '0' : '-1' );
			t.classList.toggle( 'dfwc-overlay__tab--active', isActive );
		} );

		Object.keys( ui.panels ).forEach( function ( k ) {
			if ( k === key ) { ui.panels[ k ].removeAttribute( 'hidden' ); }
			else { ui.panels[ k ].setAttribute( 'hidden', '' ); }
		} );

		// Resolve amount for this tab: prior custom value if remembered, else
		// the panel's default preset, else 0.
		var customForKey = state.customByKey[ key ] || '';
		if ( customForKey && parseFloat( customForKey ) > 0 ) {
			state.amount = parseFloat( customForKey );
		} else {
			var entry = config[ key ];
			var checkedPreset = ui.root.querySelector( '[data-dfwc-preset][data-interval="' + key + '"]:checked' );
			if ( checkedPreset ) {
				state.amount = parseFloat( checkedPreset.getAttribute( 'data-amount' ) ) || 0;
			} else if ( entry && entry.presets && entry.presets.length ) {
				var idx = Math.max( 0, Math.min( entry.default_index | 0, entry.presets.length - 1 ) );
				state.amount = entry.presets[ idx ].amount || 0;
				// auto-select that preset radio
				var r = ui.root.querySelector( '[data-dfwc-preset][data-interval="' + key + '"][data-idx="' + idx + '"]' );
				if ( r ) { r.checked = true; }
			}
		}

		applyState( state, parentEls, config, ui );
	}

	function applyState( state, parentEls, config, ui ) {
		// 1) Write hidden price inputs.
		writeAll( parentEls.priceInputs, String( state.amount ) );

		// 2) Recurring on/off + period selects (WCS) + WPS SFW fields.
		var isRecurring = state.interval !== 'one_time';
		if ( parentEls.recurringCheckbox ) {
			if ( parentEls.recurringCheckbox.checked !== isRecurring ) {
				parentEls.recurringCheckbox.checked = isRecurring;
				dispatchChange( parentEls.recurringCheckbox );
			}
		}

		if ( isRecurring ) {
			var period = state.interval === 'monthly' ? 'month' : 'year';

			// WCS path
			if ( parentEls.subPeriod )         { setSelect( parentEls.subPeriod, period ); }
			if ( parentEls.subPeriodInterval ) { setSelect( parentEls.subPeriodInterval, '1' ); }
			if ( parentEls.subLength )         { setSelect( parentEls.subLength, '0' ); }

			// WPS SFW path (parent renders only one branch in the DOM, so the
			// other branch's selectors return null — set whichever exists).
			if ( parentEls.wpsNumber )         { parentEls.wpsNumber.value = '1'; }
			if ( parentEls.wpsInterval )       { setSelect( parentEls.wpsInterval, period ); }
			if ( parentEls.wpsExpiryNumber )   { parentEls.wpsExpiryNumber.value = ''; }
			if ( parentEls.wpsExpiryInterval ) { setSelect( parentEls.wpsExpiryInterval, period ); }
		}

		// 3) selectedLabel: parent's submit handler reads from the visible amount
		// UI which we hid. Add/update a hidden field so parent gets a value.
		var label = '';
		var checkedPreset = ui.root.querySelector( '[data-dfwc-preset][data-interval="' + state.interval + '"]:checked' );
		if ( checkedPreset ) { label = checkedPreset.getAttribute( 'data-label' ) || ''; }
		setSelectedLabel( parentEls, label );

		// 4) Update parent's submit button label using our CTA template.
		if ( parentEls.submitBtn ) {
			var entry = config[ state.interval ];
			var template = ( entry && entry.cta_template ) || 'Donate {amount}';
			var intervalSuffix = state.interval === 'monthly' ? '/month'
				: state.interval === 'annual' ? '/year' : '';
			var label = template
				.split( '{amount}' ).join( formatCurrency( state.amount ) )
				.split( '{interval}' ).join( intervalSuffix )
				.replace( /\{[a-z_]+\}/g, '' );
			// textContent — XSS-safe even if admin pasted markup into template
			parentEls.submitBtn.textContent = label;
		}

		// 5) Phase 5: update annual-equivalency text using {amount}/{annual_amount} tokens.
		var equivWraps = ui.root.querySelectorAll( '[data-dfwc-equivalency]' );
		Array.prototype.forEach.call( equivWraps, function ( eq ) {
			var equivKey = eq.getAttribute( 'data-dfwc-equivalency' );
			var template = eq.getAttribute( 'data-template' ) || '';
			if ( ! template ) { eq.textContent = ''; return; }
			// Only show for the active interval; hide on other panels (panels are
			// already hidden via `[hidden]`, but the inner text shouldn't carry
			// stale values when an SR re-reads).
			if ( equivKey !== state.interval ) {
				eq.textContent = '';
				return;
			}
			if ( state.amount <= 0 ) {
				eq.textContent = '';
				return;
			}
			var multiplier = 'monthly' === state.interval ? 12
				: 'weekly' === state.interval ? 52
				: 'quarterly' === state.interval ? 4
				: 'semiannual' === state.interval ? 2
				: 'annual' === state.interval ? 1
				: 1;
			var annual = state.amount * multiplier;
			var rendered = template
				.split( '{amount}' ).join( formatCurrency( state.amount ) )
				.split( '{annual_amount}' ).join( formatCurrency( annual ) )
				.replace( /\{[a-z_]+\}/g, '' );
			eq.textContent = rendered;
		} );

		// 6) Clear inline error if amount is now valid.
		if ( state.amount > 0 && amountInRange( state.amount, state.interval, config ) ) {
			showError( ui.root, '' );
		}
	}

	function setSelectedLabel( parentEls, value ) {
		var input = parentEls.selectedLabelInput;
		if ( ! input ) {
			input = parentEls.scope.ownerDocument.createElement( 'input' );
			input.type  = 'hidden';
			input.name  = 'selectedLabel';
			input.setAttribute( 'data-dfwc-injected', '' );
			parentEls.scope.appendChild( input );
			parentEls.selectedLabelInput = input;
		}
		input.value = value;
	}

	function clampAmount( value, intervalKey, config ) {
		var entry = config[ intervalKey ];
		if ( ! entry || isNaN( value ) ) { return 0; }
		if ( value < entry.min ) { return entry.min; }
		if ( value > entry.max ) { return entry.max; }
		return value;
	}

	function amountInRange( value, intervalKey, config ) {
		var entry = config[ intervalKey ];
		if ( ! entry ) { return false; }
		return value >= entry.min && value <= entry.max;
	}

	function clearPresetSelection( root, intervalKey ) {
		Array.prototype.forEach.call(
			root.querySelectorAll( '[data-dfwc-preset][data-interval="' + intervalKey + '"]' ),
			function ( r ) { r.checked = false; }
		);
	}

	function writeAll( nodeList, value ) {
		Array.prototype.forEach.call( nodeList || [], function ( el ) {
			if ( el.value !== value ) {
				el.value = value;
				dispatchChange( el );
			}
		} );
	}

	function setSelect( selectEl, value ) {
		if ( ! selectEl ) { return; }
		// Find matching option; if none, leave alone.
		var found = false;
		Array.prototype.forEach.call( selectEl.options || [], function ( opt ) {
			if ( opt.value === value ) { found = true; }
		} );
		if ( found && selectEl.value !== value ) {
			selectEl.value = value;
			dispatchChange( selectEl );
		}
	}

	function dispatchChange( el ) {
		try {
			var ev = new Event( 'change', { bubbles: true } );
			el.dispatchEvent( ev );
		} catch ( e ) {
			// Older browsers fallback (IE quirks etc.); modern targets are fine.
		}
	}

	function hideAll( nodeList ) {
		Array.prototype.forEach.call( nodeList || [], function ( el ) {
			el.classList.add( 'dfwc-overlay-hidden' );
		} );
	}

	/**
	 * Apply admin display options to parent's rendered form. Selectors locked
	 * to parent v3.9.8 (frontend-order-donation.php:401-419 for title+image,
	 * frontend-donation-cause-disp.php:5 for cause heading).
	 */
	function applyDisplay( scope, display ) {
		if ( ! display.show_title ) {
			hideAll( scope.querySelectorAll( '.campaign-title' ) );
		}
		if ( ! display.show_image ) {
			hideAll( scope.querySelectorAll( '.block-campaign-thumbnail' ) );
		}
		if ( display.cause_heading && typeof display.cause_heading === 'string' ) {
			// Cause block uses h3.wc-donation-title with hardcoded "Select Cause".
			// XSS-safe: textContent, never innerHTML.
			var heading = scope.querySelector( '.row2 h3.wc-donation-title' );
			if ( heading ) {
				heading.textContent = display.cause_heading;
			}
		}
	}

	function showError( root, message ) {
		var node = root.querySelector( '[data-dfwc-error]' );
		if ( ! node ) { return; }
		if ( ! message ) {
			node.textContent = '';
			node.setAttribute( 'hidden', '' );
			return;
		}
		node.textContent = message;
		node.removeAttribute( 'hidden' );
	}

	function formatCurrency( amount ) {
		var locale   = ( window.dfwcCompanion && window.dfwcCompanion.locale ) || ( navigator.language || 'en-US' );
		var currency = ( window.dfwcCompanion && window.dfwcCompanion.currency ) || 'USD';
		try {
			return new Intl.NumberFormat( locale, { style: 'currency', currency: currency, maximumFractionDigits: 2 } ).format( amount || 0 );
		} catch ( e ) {
			return ( ( window.dfwcCompanion && window.dfwcCompanion.currencySymbol ) || '$' ) + Number( amount || 0 ).toFixed( 2 );
		}
	}
} )();
