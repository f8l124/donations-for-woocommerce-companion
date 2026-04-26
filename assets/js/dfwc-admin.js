/**
 * Admin meta-box behavior: tab switching, sortable preset repeater,
 * default-radio integrity, and basic validation.
 *
 * Vanilla JS, ES2018 baseline (last-2-versions evergreen + iOS 12+).
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
		var root = document.querySelector( '[data-dfwc-meta-box]' );
		if ( ! root ) { return; }

		initTabs( root );
		initRepeaters( root );
	} );

	function initTabs( root ) {
		var tabs = root.querySelectorAll( '[data-dfwc-tab]' );
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				if ( tab.disabled ) { return; }
				var key = tab.getAttribute( 'data-dfwc-tab' );
				activateTab( root, key );
			} );

			tab.addEventListener( 'keydown', function ( e ) {
				if ( e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' ) { return; }
				e.preventDefault();
				var siblings = Array.prototype.filter.call( tabs, function ( t ) { return ! t.disabled; } );
				var idx = siblings.indexOf( tab );
				if ( idx < 0 ) { return; }
				var nextIdx = e.key === 'ArrowRight' ? ( idx + 1 ) % siblings.length
				                                    : ( idx - 1 + siblings.length ) % siblings.length;
				siblings[ nextIdx ].focus();
				activateTab( root, siblings[ nextIdx ].getAttribute( 'data-dfwc-tab' ) );
			} );
		} );
	}

	function activateTab( root, key ) {
		root.querySelectorAll( '[data-dfwc-tab]' ).forEach( function ( t ) {
			t.setAttribute( 'aria-selected', t.getAttribute( 'data-dfwc-tab' ) === key ? 'true' : 'false' );
		} );
		root.querySelectorAll( '[data-dfwc-panel]' ).forEach( function ( p ) {
			if ( p.getAttribute( 'data-dfwc-panel' ) === key ) { p.removeAttribute( 'hidden' ); }
			else { p.setAttribute( 'hidden', '' ); }
		} );
	}

	function initRepeaters( root ) {
		root.querySelectorAll( '[data-dfwc-presets]' ).forEach( function ( table ) {
			var key = table.getAttribute( 'data-dfwc-presets' );
			var addBtn = root.querySelector( '[data-dfwc-add="' + key + '"]' );
			var template = root.querySelector( '#dfwc-preset-template-' + key );

			if ( addBtn && template ) {
				addBtn.addEventListener( 'click', function () { addRow( table, template, key ); } );
			}

			table.addEventListener( 'click', function ( e ) {
				var btn = e.target && e.target.closest && e.target.closest( '[data-dfwc-remove]' );
				if ( ! btn ) { return; }
				removeRow( table, btn.closest( 'tr' ), key );
			} );

			initDragDrop( table, key );
		} );
	}

	function addRow( table, template, key ) {
		var tbody = table.querySelector( 'tbody' );
		var rows = tbody.querySelectorAll( 'tr' );
		var nextIdx = rows.length;

		var html = template.innerHTML.split( '__INDEX__' ).join( String( nextIdx ) );
		var wrap = document.createElement( 'tbody' );
		wrap.innerHTML = html.trim();
		var newRow = wrap.firstChild;
		tbody.appendChild( newRow );

		// First-row added when none existed: pre-select default.
		if ( rows.length === 0 ) {
			var radio = newRow.querySelector( 'input[type="radio"]' );
			if ( radio ) { radio.checked = true; }
		}

		focusFirstField( newRow );
	}

	function removeRow( table, row, key ) {
		var tbody = table.querySelector( 'tbody' );
		if ( tbody.querySelectorAll( 'tr' ).length <= 1 ) {
			// Don't allow removing the last row — leave the user a starting point.
			return;
		}

		var wasDefault = !! row.querySelector( 'input[type="radio"]:checked' );
		row.parentNode.removeChild( row );
		renumberRows( tbody, key );

		if ( wasDefault ) {
			var first = tbody.querySelector( 'tr input[type="radio"]' );
			if ( first ) { first.checked = true; }
		}
	}

	/**
	 * Rewrite name="…[presets][N][amount]" indexes after add/remove/reorder.
	 * Also renumbers the per-row default radio's `value` attribute so the
	 * server-side default_index always matches a real row.
	 */
	function renumberRows( tbody, key ) {
		var rows = tbody.querySelectorAll( 'tr' );
		Array.prototype.forEach.call( rows, function ( row, idx ) {
			row.querySelectorAll( 'input' ).forEach( function ( input ) {
				var name = input.getAttribute( 'name' );
				if ( name ) {
					input.setAttribute(
						'name',
						name.replace( /\[presets\]\[\d+\]/, '[presets][' + idx + ']' )
					);
				}
				if ( input.type === 'radio' && input.name && input.name.indexOf( '[default_index]' ) !== -1 ) {
					input.value = String( idx );
				}
			} );
		} );
	}

	function initDragDrop( table, key ) {
		var tbody = table.querySelector( 'tbody' );
		var dragSrc = null;

		tbody.addEventListener( 'dragstart', function ( e ) {
			var row = e.target && e.target.closest && e.target.closest( 'tr' );
			if ( ! row ) { return; }
			dragSrc = row;
			row.classList.add( 'dfwc-mb__preset-row--dragging' );
			if ( e.dataTransfer ) { e.dataTransfer.effectAllowed = 'move'; }
		} );

		tbody.addEventListener( 'dragend', function () {
			if ( dragSrc ) {
				dragSrc.classList.remove( 'dfwc-mb__preset-row--dragging' );
				dragSrc = null;
				renumberRows( tbody, key );
			}
		} );

		tbody.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			if ( ! dragSrc ) { return; }
			var row = e.target && e.target.closest && e.target.closest( 'tr' );
			if ( ! row || row === dragSrc ) { return; }
			var rect = row.getBoundingClientRect();
			var midY = rect.top + ( rect.height / 2 );
			if ( e.clientY < midY ) { row.parentNode.insertBefore( dragSrc, row ); }
			else { row.parentNode.insertBefore( dragSrc, row.nextSibling ); }
		} );
	}

	function focusFirstField( row ) {
		var first = row.querySelector( 'input[type="number"], input[type="text"]' );
		if ( first ) { first.focus(); }
	}
} )();
