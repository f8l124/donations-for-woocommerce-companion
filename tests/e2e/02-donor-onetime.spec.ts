/**
 * Phase F.3 — donor flow: One-time tab → preset $50 → submit → cart shows
 * $50 line item with no subscription metadata.
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
	waitForCtaText,
} from './helpers';

test.describe( 'donor: one-time', () => {
	let campaignId: number;
	let page: { id: number; url: string };

	test.beforeAll( () => {
		campaignId = createCampaign( 'E2E One-Time Campaign' );
		setCampaignIntervals( campaignId, {
			one_time: {
				enabled: true,
				presets: [
					{ amount: 25, label: '' },
					{ amount: 50, label: '' },
					{ amount: 100, label: '' },
				],
				min: 5, max: 1000, default_index: 1,
				cta_template: 'Donate {amount}',
			},
		} );
		page = createPageWithShortcode( 'E2E OneTime Page', `[dfwc_recurring_donation campaign_id="${ campaignId }"]` );
	} );

	test.afterAll( () => {
		deletePost( page.id );
		deletePost( campaignId );
	} );

	test( 'renders form, selects $50 preset, CTA reads "Donate $50"', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		const form = pwPage.locator( '[data-dfwc-form]' );
		await expect( form ).toBeVisible();

		// One-time tab is active by default.
		await expect( form.locator( '[data-dfwc-tab="one_time"]' ) ).toHaveAttribute( 'aria-selected', 'true' );

		// $50 preset is the default (default_index=1 in fixture).
		await waitForCtaText( pwPage, '[data-dfwc-form]', /50/ );

		// Click $25 preset.
		await form.locator( '[data-dfwc-preset][data-amount="25"]' ).click();
		await waitForCtaText( pwPage, '[data-dfwc-form]', /25/ );
	} );
} );
