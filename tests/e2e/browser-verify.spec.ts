/**
 * Quick browser verification of Tier 3 admin tab injection.
 * Run via: npx playwright test tests/browser-verify.ts --project=wps --headed
 *
 * Local-only sanity check, not part of the full E2E suite.
 */
import { test, expect } from '@playwright/test';

test.describe.configure( { mode: 'serial' } );

test( 'tab injector relocates companion UI into #tab-3 and hides parent recurring controls', async ( { page } ) => {
	test.setTimeout( 120_000 );
	// Log in.
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( 'input#user_login', 'admin' );
	await page.fill( 'input#user_pass', 'password' );
	await Promise.all( [
		page.waitForURL( /\/wp-admin\//, { timeout: 30_000 } ).catch( ( e ) => { console.log( 'after-login URL:', page.url() ); throw e; } ),
		page.click( 'input#wp-submit' ),
	] );

	// Find the most recent wc-donation campaign id (or create one).
	await page.goto( '/wp-admin/edit.php?post_type=wc-donation' );
	const editLink = page.locator( 'table.posts a.row-title' ).first();
	const exists = await editLink.count();
	let editUrl: string;
	if ( exists > 0 ) {
		editUrl = ( await editLink.getAttribute( 'href' ) ) || '';
	} else {
		// Create one.
		await page.goto( '/wp-admin/post-new.php?post_type=wc-donation' );
		await page.fill( 'input#title', 'Verification Campaign' );
		// Need to publish — parent's nonce check fires on save_post.
		// The save will redirect us back to the edit screen.
		await page.click( 'input#publish' );
		await page.waitForURL( /post=\d+&action=edit/ );
		editUrl = page.url();
	}

	if ( ! editUrl.includes( 'action=edit' ) ) {
		await page.goto( editUrl );
	}

	// Check that #tab-3 exists (parent's Recurring Donations tab).
	const tab3 = page.locator( '#tab-3' );
	await expect( tab3, '#tab-3 (parent Recurring Donations tab) must be present' ).toHaveCount( 1 );

	// Check companion meta box wrapper is hidden post-relocation.
	const wrapper = page.locator( '#dfwc_companion_intervals' );
	await expect( wrapper, 'companion meta box wrapper #dfwc_companion_intervals should be hidden after JS injection' ).toHaveCSS( 'display', 'none' );

	// Check our content moved into #tab-3.
	const ourBoxInTab = tab3.locator( '[data-dfwc-meta-box]' );
	await expect( ourBoxInTab, 'companion content [data-dfwc-meta-box] should now live inside #tab-3' ).toHaveCount( 1 );

	// Check parent's WPS SFW recurring panel is hidden if present.
	const wps = page.locator( '#wps_sfw_product_target_section' );
	if ( await wps.count() > 0 ) {
		await expect( wps, '#wps_sfw_product_target_section should be hidden by companion injector' ).toHaveCSS( 'display', 'none' );
	}

	// Visual confirmation: take a screenshot.
	await page.screenshot( { path: 'tests/browser-verify-tab3.png', fullPage: false, clip: { x: 0, y: 0, width: 1280, height: 1500 } } );
} );
