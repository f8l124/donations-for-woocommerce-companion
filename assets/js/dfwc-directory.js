/**
 * Donations for WooCommerce Companion — directory grid live search.
 *
 * Progressive enhancement: when JS is available, the search input
 * debounces and fetches the REST endpoint instead of submitting the form.
 * Filter <select> changes also fire a fetch. Without JS, the form
 * submits normally and the page reloads with new query args.
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
		var directories = document.querySelectorAll( '[data-dfwc-directory]' );
		Array.prototype.forEach.call( directories, init );
	} );

	function init( directory ) {
		var form = directory.querySelector( '[data-dfwc-directory-filters]' );
		if ( ! form ) { return; }

		// Cache the REST URL + nonce from the localized config object.
		// Bail (fall back to form submit) if the endpoint isn't configured.
		if ( ! window.dfwcDirectory || ! window.dfwcDirectory.restUrl ) { return; }

		// Search input: debounced live search.
		var searchInput = form.querySelector( '[data-dfwc-directory-search]' );
		if ( searchInput ) {
			var debouncedSearch = debounce( function () {
				submitFiltersToRest( form, directory );
			}, 300 );
			searchInput.addEventListener( 'input', debouncedSearch );
		}

		// Select changes: immediate live search.
		var selects = form.querySelectorAll( 'select' );
		Array.prototype.forEach.call( selects, function ( select ) {
			select.addEventListener( 'change', function () {
				submitFiltersToRest( form, directory );
			} );
		} );

		// Form submit (fallback / explicit click): also intercept and use REST.
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submitFiltersToRest( form, directory );
		} );
	}

	function submitFiltersToRest( form, directory ) {
		var grid = directory.querySelector( '.dfwc-directory__grid' );
		var pagination = directory.querySelector( '.dfwc-directory__pagination' );
		var count = directory.querySelector( '.dfwc-directory__count' );
		var empty = directory.querySelector( '.dfwc-directory__empty' );

		// Build params from form fields, stripping the dfwc_ prefix.
		var params = new URLSearchParams();
		var inputs = form.querySelectorAll( 'input, select' );
		Array.prototype.forEach.call( inputs, function ( input ) {
			if ( ! input.name || 0 !== input.name.indexOf( 'dfwc_' ) ) {
				return;
			}
			var key = input.name.substring( 5 ); // strip 'dfwc_'
			if ( '' === input.value ) { return; }
			params.set( key, input.value );
		} );
		// Live search renders without filter bar (UI swap is just the grid).
		params.set( 'show_filters', 'false' );

		directory.classList.add( 'dfwc-directory--loading' );

		fetch( window.dfwcDirectory.restUrl + '?' + params.toString(), {
			method: 'GET',
			headers: {
				'Accept': 'application/json',
				'X-WP-Nonce': window.dfwcDirectory.nonce || '',
			},
		} )
			.then( function ( res ) {
				if ( ! res.ok ) { throw new Error( 'rest_error' ); }
				return res.json();
			} )
			.then( function ( data ) {
				if ( ! data || typeof data.html !== 'string' ) { return; }
				// Replace the grid + count + pagination + empty in place.
				// REST responds with the FULL directory HTML; extract its inner pieces.
				var temp = document.createElement( 'div' );
				temp.innerHTML = data.html;
				var newGrid = temp.querySelector( '.dfwc-directory__grid' );
				var newPagination = temp.querySelector( '.dfwc-directory__pagination' );
				var newCount = temp.querySelector( '.dfwc-directory__count' );
				var newEmpty = temp.querySelector( '.dfwc-directory__empty' );

				// Swap the grid (or empty state).
				if ( grid && newGrid ) {
					grid.innerHTML = newGrid.innerHTML;
					if ( empty ) { empty.remove(); }
				} else if ( ! grid && newEmpty ) {
					// Was a grid; now empty: append the empty state.
					if ( newEmpty && ! directory.querySelector( '.dfwc-directory__empty' ) ) {
						directory.appendChild( newEmpty );
					}
				} else if ( grid && newEmpty ) {
					grid.remove();
					directory.appendChild( newEmpty );
				} else if ( ! grid && newGrid ) {
					// Was empty; now has results.
					var existingEmpty = directory.querySelector( '.dfwc-directory__empty' );
					if ( existingEmpty ) { existingEmpty.remove(); }
					directory.appendChild( newGrid );
				}

				// Swap pagination.
				if ( pagination && newPagination ) {
					pagination.innerHTML = newPagination.innerHTML;
				} else if ( ! pagination && newPagination ) {
					directory.appendChild( newPagination );
				} else if ( pagination && ! newPagination ) {
					pagination.remove();
				}

				// Swap count.
				if ( count && newCount ) {
					count.textContent = newCount.textContent;
				} else if ( ! count && newCount ) {
					directory.appendChild( newCount );
				} else if ( count && ! newCount ) {
					count.remove();
				}

				// Update browser URL via history.replaceState so deep links work.
				if ( window.history && window.history.replaceState ) {
					var url = new URL( window.location.href );
					params.forEach( function ( value, key ) {
						if ( 'show_filters' === key ) { return; }
						if ( '' === value ) { url.searchParams.delete( 'dfwc_' + key ); }
						else { url.searchParams.set( 'dfwc_' + key, value ); }
					} );
					window.history.replaceState( {}, '', url.toString() );
				}
			} )
			.catch( function () {
				// Fallback: full submit if REST fails (network error, rate limit, etc.).
				form.submit();
			} )
			.finally( function () {
				directory.classList.remove( 'dfwc-directory--loading' );
			} );
	}
} )();
