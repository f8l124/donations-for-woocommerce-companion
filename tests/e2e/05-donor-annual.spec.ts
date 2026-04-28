/**
 * Phase F.3 — donor flow: Annual tab → preset $300 → submit → cart shows
 * recurring line with billing_period=year (under WPS SFW or WCS).
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
import { test, expect } from '@playwright/test';
import {
	activePlugins,
	createCampaign,
	createPageWithShortcode,
	deletePost,
	setCampaignIntervals,
	setParentRecurringMode,
	waitForCtaText,
} from './helpers';

test.describe( 'donor: annual', () => {
	let campaignId: number;
	let page: { id: number; url: string };

	test.beforeAll( () => {
		const plugins = activePlugins();
		const hasEngine = plugins.includes( 'subscriptions-for-woocommerce' ) || plugins.includes( 'woocommerce-subscriptions' );
		test.skip( ! hasEngine, 'No recurring engine active in this fixture' );

		campaignId = createCampaign( 'E2E Annual Campaign' );
		setCampaignIntervals( campaignId, {
			one_time: { enabled: true, presets: [ { amount: 25 } ], min: 5, max: 1000, default_index: 0, cta_template: 'Donate {amount}' },
			monthly:  { enabled: false, presets: [], min: 5, max: 500, default_index: 0, cta_template: '' },
			annual:   { enabled: true,  presets: [ { amount: 100 }, { amount: 300 }, { amount: 1000 } ], min: 50, max: 50000, default_index: 1, cta_template: 'Donate {amount}/year' },
		} );
		setParentRecurringMode( campaignId, 'user' );
		page = createPageWithShortcode( 'E2E Annual Page', `[dfwc_recurring_donation campaign_id="${ campaignId }"]` );
	} );

	test.afterAll( () => {
		deletePost( page.id );
		deletePost( campaignId );
	} );

	test( 'annual tab → $300 preset → CTA reads "Donate $300/year"', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		const form = pwPage.locator( '[data-dfwc-overlay-target]' );
		await form.locator( '[data-dfwc-tab="annual"]' ).click();
		await form.locator( '[data-dfwc-panel="annual"] [data-dfwc-preset][data-amount="300"]' ).click();
		await waitForCtaText( pwPage, '[data-dfwc-overlay-target]', /300.*year/ );
	} );

	test( 'submit posts new_period=year', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-tab="annual"]' ).click();
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-panel="annual"] [data-dfwc-preset][data-amount="100"]' ).click();

		const requestPromise = pwPage.waitForRequest( ( req ) =>
			req.url().includes( 'admin-ajax.php' ) && req.method() === 'POST' );
		await pwPage.locator( '[data-dfwc-overlay-target] .wc-donation-f-submit-donation' ).click();
		const req = await requestPromise;
		const body = req.postData() || '';

		expect( body ).toContain( 'new_period=year' );
		expect( body ).toContain( 'wps_sfw_subscription_interval=year' );
	} );
} );
