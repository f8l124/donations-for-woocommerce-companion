/**
 * Donations for WooCommerce Companion — admin live preview.
 *
 * Watches the meta box / templates form / settings form for changes,
 * debounces 350ms, serializes the visible config into a JSON payload,
 * POSTs to the preview REST endpoint, and writes the returned HTML into
 * an iframe via srcdoc. Same overlay JS that runs on donor-facing
 * pages also runs in the iframe — pixel-faithful preview.
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
		var pane = document.querySelector( '[data-dfwc-preview-pane]' );
		if ( ! pane ) { return; }
		if ( ! window.dfwcAdminPreview || ! window.dfwcAdminPreview.restUrl ) { return; }

		init( pane );
	} );

	function init( pane ) {
		var iframe       = pane.querySelector( '[data-dfwc-preview-iframe]' );
		var statusEl     = pane.querySelector( '[data-dfwc-preview-status]' );
		var viewportSel  = pane.querySelector( '[data-dfwc-preview-viewport]' );
		var engineSel    = pane.querySelector( '[data-dfwc-preview-engine]' );
		var languageSel  = pane.querySelector( '[data-dfwc-preview-language]' );
		var currencyInp  = pane.querySelector( '[data-dfwc-preview-currency]' );

		// Populate language dropdown if WPML active.
		if ( languageSel && Array.isArray( window.dfwcAdminPreview.languages ) ) {
			window.dfwcAdminPreview.languages.forEach( function ( lang ) {
				if ( ! lang || ! lang.code ) { return; }
				var opt = document.createElement( 'option' );
				opt.value = lang.code;
				opt.textContent = lang.label || lang.code;
				languageSel.appendChild( opt );
			} );
		}

		// Find the form to watch. Different sources have different roots.
		var source = pane.getAttribute( 'data-source' ) || 'campaign';
		var formRoot = locateFormRoot( source );
		if ( ! formRoot ) {
			setStatus( statusEl, 'Cannot locate the form. Preview disabled.' );
			return;
		}

		var refresh = debounce( function () {
			var config = serializeConfig( formRoot, source );
			if ( ! config ) {
				setStatus( statusEl, window.dfwcAdminPreview.i18n.error );
				return;
			}
			setStatus( statusEl, window.dfwcAdminPreview.i18n.loading );
			fetchPreview(
				{
					config: config,
					simulate_engine:   engineSel ? engineSel.value : 'auto',
					simulate_language: languageSel ? languageSel.value : '',
					simulate_currency: currencyInp ? currencyInp.value.toUpperCase() : '',
					viewport:          viewportSel ? viewportSel.value : 'desktop',
				},
				function ( html ) {
					writeIframe( iframe, html );
					setStatus( statusEl, window.dfwcAdminPreview.i18n.upToDate );
				},
				function () {
					setStatus( statusEl, window.dfwcAdminPreview.i18n.error );
				}
			);
		}, 350 );

		// Watch every input / select / textarea inside the form root.
		formRoot.addEventListener( 'input', refresh );
		formRoot.addEventListener( 'change', refresh );

		// Toolbar selectors trigger immediate refresh.
		[ viewportSel, engineSel, languageSel, currencyInp ].forEach( function ( el ) {
			if ( ! el ) { return; }
			el.addEventListener( 'change', refresh );
			el.addEventListener( 'input', refresh );
		} );

		// Initial render.
		refresh();
	}

	function setStatus( el, text ) {
		if ( el && text ) { el.textContent = text; }
	}

	function locateFormRoot( source ) {
		if ( 'template' === source ) {
			// Templates_Page edit form.
			var byForm = document.querySelector( '.dfwc-template-edit form' );
			if ( byForm ) { return byForm; }
			return document.querySelector( '.dfwc-template-edit' );
		}
		if ( 'settings' === source ) {
			return document.querySelector( '.dfwc-settings form' );
		}
		// Campaign edit screen — meta box content.
		return document.querySelector( '[data-dfwc-meta-box]' );
	}

	function fetchPreview( payload, onSuccess, onError ) {
		fetch( window.dfwcAdminPreview.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.dfwcAdminPreview.nonce,
			},
			body: JSON.stringify( payload ),
		} ).then( function ( res ) {
			if ( ! res.ok ) { throw new Error( 'preview_http_' + res.status ); }
			return res.json();
		} ).then( function ( data ) {
			if ( ! data || typeof data.html !== 'string' ) {
				throw new Error( 'preview_invalid_response' );
			}
			onSuccess( data.html );
		} ).catch( onError );
	}

	function writeIframe( iframe, html ) {
		if ( ! iframe ) { return; }
		// Build a complete document so the overlay JS / CSS load inside the iframe.
		var overlayCss = window.dfwcAdminPreview.overlayCssUrl || '';
		var overlayJs  = window.dfwcAdminPreview.overlayJsUrl || '';
		var doc = '<!DOCTYPE html><html><head><meta charset="utf-8">'
			+ '<meta name="viewport" content="width=device-width, initial-scale=1">'
			+ ( overlayCss ? '<link rel="stylesheet" href="' + overlayCss + '">' : '' )
			+ '<style>body{margin:0;padding:24px;font-family:system-ui,sans-serif;background:#fff;}'
			+ '.dfwc-preview .dfwc-preview__row1{opacity:0.3;}'
			+ '.dfwc-preview .dfwc-preview__title{margin:0 0 12px;font-size:1.4em;}'
			+ '.dfwc-preview .dfwc-preview__image{width:120px;height:90px;background:#eef;display:flex;'
			+ 'align-items:center;justify-content:center;color:#88a;border-radius:6px;margin-bottom:12px;}</style>'
			+ '</head><body>' + html
			+ ( overlayJs ? '<script>window.dfwcCompanion = window.dfwcCompanion || { currencySymbol: "$", i18n: {} };<\/script>' : '' )
			+ ( overlayJs ? '<script src="' + overlayJs + '"><\/script>' : '' )
			+ '</body></html>';

		iframe.setAttribute( 'srcdoc', doc );
	}

	/**
	 * Serialize the form root into a config payload matching Template_Validator's
	 * expected input shape. The payload is intentionally permissive — the
	 * validator sanitizes everything before rendering.
	 *
	 * Distinguishes campaign-edit (dfwc_intervals[...] field names) from
	 * template-edit (template[config][...] field names).
	 */
	function serializeConfig( formRoot, source ) {
		var inputs = formRoot.querySelectorAll( 'input, select, textarea' );
		var prefix, configKey;

		if ( 'template' === source ) {
			prefix    = 'template[config]';
			configKey = 'template[config]';
		} else {
			// Campaign edit and Settings both use field-name patterns.
			// Settings: dfwc_companion_global_settings[...]
			// Campaign: dfwc_intervals[...] + dfwc_display[...]
			prefix    = null; // handled per-input
			configKey = null;
		}

		var config = {};

		Array.prototype.forEach.call( inputs, function ( input ) {
			var name = input.name;
			if ( ! name ) { return; }

			var value = readInputValue( input );
			if ( null === value ) { return; }

			// Map field names back to config paths.
			var path = mapFieldNameToPath( name, source );
			if ( ! path ) { return; }

			setNested( config, path, value );
		} );

		return config;
	}

	function readInputValue( input ) {
		if ( 'checkbox' === input.type ) {
			return input.checked ? '1' : '';
		}
		if ( 'radio' === input.type ) {
			return input.checked ? input.value : null;
		}
		return input.value;
	}

	/**
	 * Translate WP-style nested field names into config-path arrays.
	 * Returns null when the field doesn't map to a config path.
	 *
	 * Examples:
	 *   dfwc_intervals[monthly][enabled]                   -> ['monthly','enabled']
	 *   dfwc_intervals[monthly][presets][0][amount]        -> ['monthly','presets',0,'amount']
	 *   dfwc_display[show_title]                           -> ['display','show_title']
	 *   template[config][monthly][enabled]                 -> ['monthly','enabled']
	 *   dfwc_companion_global_settings[monthly][enabled]   -> ['monthly','enabled']
	 */
	function mapFieldNameToPath( name, source ) {
		// Normalize quotes inside name (admins shouldn't have any but defensive).
		var stripped;
		if ( 'template' === source ) {
			// Strip "template[config]" prefix.
			stripped = name.replace( /^template\[config\]/, '' );
		} else if ( 'settings' === source ) {
			stripped = name.replace( /^dfwc_companion_global_settings/, '' );
		} else {
			// campaign-edit
			if ( 0 === name.indexOf( 'dfwc_intervals' ) ) {
				stripped = name.replace( /^dfwc_intervals/, '' );
			} else if ( 0 === name.indexOf( 'dfwc_display' ) ) {
				stripped = '[display]' + name.substring( 'dfwc_display'.length );
			} else {
				return null; // unrelated field
			}
		}

		if ( '' === stripped || stripped === name ) { return null; }

		// Parse [foo][bar][0][...] into ['foo','bar',0,...].
		var path = [];
		var re = /\[([^\]]*)\]/g;
		var m;
		while ( null !== ( m = re.exec( stripped ) ) ) {
			var segment = m[ 1 ];
			if ( /^\d+$/.test( segment ) ) {
				path.push( parseInt( segment, 10 ) );
			} else {
				path.push( segment );
			}
		}
		return path.length > 0 ? path : null;
	}

	function setNested( obj, path, value ) {
		var cursor = obj;
		for ( var i = 0; i < path.length - 1; i++ ) {
			var key = path[ i ];
			var nextKey = path[ i + 1 ];
			if ( typeof cursor[ key ] !== 'object' || cursor[ key ] === null ) {
				cursor[ key ] = ( typeof nextKey === 'number' ) ? [] : {};
			}
			cursor = cursor[ key ];
		}
		cursor[ path[ path.length - 1 ] ] = value;
	}
} )();
