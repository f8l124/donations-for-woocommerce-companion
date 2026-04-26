/**
 * Phase F.3 — replace mode: parent's single-campaign form is suppressed via
 * ob_start/ob_get_clean and ours appears in its place. (D3)
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
import { test, expect } from '@playwright/test';
import {
	createCampaign,
	deletePost,
	setCampaignIntervals,
	wpCli,
} from './helpers';

test.describe( 'replace mode', () => {
	let campaignId: number;

	test.beforeAll( () => {
		campaignId = createCampaign( 'E2E Replace Mode Campaign' );
		setCampaignIntervals( campaignId, {
			one_time: { enabled: true, presets: [ { amount: 25 } ], min: 5, max: 1000, default_index: 0, cta_template: 'Donate {amount}' },
		} );
		wpCli( `post meta update ${ campaignId } _dfwc_companion_form_mode replace` );
	} );

	test.afterAll( () => { deletePost( campaignId ); } );

	test( 'single-campaign permalink renders our form, not parent\'s', async ( { page } ) => {
		const url = wpCli( `post get ${ campaignId } --field=guid` );
		await page.goto( url );

		// Our form must be present.
		await expect( page.locator( '[data-dfwc-form]' ) ).toBeVisible();

		// Parent's form markup landmarks should NOT be present. The parent's
		// front-end form template uses a `wc-donation-form` class somewhere
		// in its single-campaign view. If that class is present alongside
		// our form, the buffer suppression failed.
		const parentMarkers = await page.locator( '.wc-donation-frontend-form, #wc_donation_form' ).count();
		expect( parentMarkers ).toBe( 0 );
	} );
} );
