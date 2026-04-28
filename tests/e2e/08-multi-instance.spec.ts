/**
 * Phase F.3 — multi-instance: two shortcodes for two campaigns on a single
 * page. State and DOM IDs must be isolated. (R7)
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
import { test, expect } from '@playwright/test';
import {
	createCampaign,
	createPageWithShortcode,
	deletePost,
	setCampaignIntervals,
} from './helpers';

test.describe( 'multi-instance', () => {
	let cidA: number;
	let cidB: number;
	let pg: { id: number; url: string };

	test.beforeAll( () => {
		cidA = createCampaign( 'E2E Multi A' );
		cidB = createCampaign( 'E2E Multi B' );
		setCampaignIntervals( cidA, {
			one_time: { enabled: true, presets: [ { amount: 10 }, { amount: 25 } ], min: 5, max: 1000, default_index: 0, cta_template: 'Donate {amount}' },
		} );
		setCampaignIntervals( cidB, {
			one_time: { enabled: true, presets: [ { amount: 50 }, { amount: 100 } ], min: 5, max: 1000, default_index: 0, cta_template: 'Donate {amount}' },
		} );
		pg = createPageWithShortcode(
			'E2E Multi Page',
			`[dfwc_recurring_donation campaign_id="${ cidA }"][dfwc_recurring_donation campaign_id="${ cidB }"]`
		);
	} );

	test.afterAll( () => {
		deletePost( pg.id );
		deletePost( cidA );
		deletePost( cidB );
	} );

	test( 'two forms render independently, no DOM ID collisions', async ( { page } ) => {
		await page.goto( pg.url );

		const forms = page.locator( '[data-dfwc-overlay-target]' );
		await expect( forms ).toHaveCount( 2 );

		// Each form has a unique data-form-uid.
		const uids = await forms.evaluateAll( ( els ) => els.map( ( e ) => ( e as HTMLElement ).dataset.formUid ) );
		expect( new Set( uids ).size ).toBe( 2 );

		// Pick $25 in form A; form B's amount/CTA must NOT change.
		const formA = forms.nth( 0 );
		const formB = forms.nth( 1 );
		const ctaB_before = await formB.locator( '.wc-donation-f-submit-donation' ).innerText();

		await formA.locator( '[data-dfwc-preset][data-amount="25"]' ).click();
		await page.waitForTimeout( 250 );

		const ctaB_after = await formB.locator( '.wc-donation-f-submit-donation' ).innerText();
		expect( ctaB_after ).toBe( ctaB_before );

		const ctaA = await formA.locator( '.wc-donation-f-submit-donation' ).innerText();
		expect( ctaA ).toMatch( /25/ );
	} );
} );
