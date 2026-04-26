/**
 * Phase F.3 — admin meta-box save round-trip + D1 force-set verification.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
import { test, expect } from '@playwright/test';
import { loginAsAdmin, createCampaign, deletePost, wpCli } from './helpers';

test.describe( 'admin meta box', () => {
	let campaignId: number;

	test.beforeAll( () => { campaignId = createCampaign( 'E2E Meta Box Save Test' ); } );
	test.afterAll( () => { deletePost( campaignId ); } );

	test( 'configures three intervals + saves; round-trips on reload; D1 force-sets parent recurring meta', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( `/wp-admin/post.php?post=${ campaignId }&action=edit` );

		// Meta box should be visible.
		await expect( page.locator( '[data-dfwc-meta-box]' ) ).toBeVisible();

		// Switch to monthly tab + enable.
		await page.click( '[data-dfwc-tab="monthly"]' );
		await page.check( 'input[name="dfwc_intervals[monthly][enabled]"]' );

		// Switch to annual tab + enable.
		await page.click( '[data-dfwc-tab="annual"]' );
		await page.check( 'input[name="dfwc_intervals[annual][enabled]"]' );

		// Save.
		await page.click( 'input#publish, input#save-post' );
		await page.waitForLoadState( 'networkidle' );

		// D1: parent's wc-donation-recurring meta must be 'user' now.
		const recurring = wpCli( `post meta get ${ campaignId } wc-donation-recurring` );
		expect( recurring ).toBe( 'user' );

		// Subscription period defaults must be seeded.
		const period = wpCli( `post meta get ${ campaignId } _subscription_period` );
		expect( [ 'month', 'year' ] ).toContain( period );

		// Reload and confirm enable checkboxes round-tripped.
		await page.reload();
		await page.click( '[data-dfwc-tab="monthly"]' );
		await expect( page.locator( 'input[name="dfwc_intervals[monthly][enabled]"]' ) ).toBeChecked();
		await page.click( '[data-dfwc-tab="annual"]' );
		await expect( page.locator( 'input[name="dfwc_intervals[annual][enabled]"]' ) ).toBeChecked();
	} );
} );
