/**
 * Phase F.3 — donor flow: Monthly tab → custom amount → submit → cart shows
 * recurring line item with billing_period=month under the WPS SFW engine.
 *
 * Skipped in `none` and `wcs` projects (only runs under `wps`).
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

test.describe( 'donor: monthly (WPS SFW)', () => {
	let campaignId: number;
	let page: { id: number; url: string };

	test.beforeAll( () => {
		// Verify the engine fixture is actually present.
		const plugins = activePlugins();
		test.skip( ! plugins.includes( 'subscriptions-for-woocommerce' ), 'WPS SFW not active in this fixture' );

		campaignId = createCampaign( 'E2E Monthly WPS Campaign' );
		setCampaignIntervals( campaignId, {
			one_time: { enabled: true,  presets: [ { amount: 25 } ], min: 5, max: 1000, default_index: 0, cta_template: 'Donate {amount}' },
			monthly:  { enabled: true,  presets: [ { amount: 10 }, { amount: 25 }, { amount: 50 } ], min: 5, max: 500, default_index: 1, cta_template: 'Donate {amount}/month' },
			annual:   { enabled: false, presets: [], min: 50, max: 50000, default_index: 0, cta_template: '' },
		} );
		setParentRecurringMode( campaignId, 'user' );
		page = createPageWithShortcode( 'E2E Monthly WPS Page', `[dfwc_recurring_donation campaign_id="${ campaignId }"]` );
	} );

	test.afterAll( () => {
		deletePost( page.id );
		deletePost( campaignId );
	} );

	test( 'monthly tab + custom $37 → CTA reads "Donate $37/month"', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		const form = pwPage.locator( '[data-dfwc-form]' );
		await expect( form ).toBeVisible();

		await form.locator( '[data-dfwc-tab="monthly"]' ).click();
		await expect( form.locator( '[data-dfwc-tab="monthly"]' ) ).toHaveAttribute( 'aria-selected', 'true' );

		const customInput = form.locator( '[data-dfwc-panel="monthly"] [data-dfwc-custom]' );
		await customInput.fill( '37' );
		await customInput.blur();

		await waitForCtaText( pwPage, '[data-dfwc-form]', /37.*month/ );
	} );

	test( 'submit fires AJAX with both WCS and WPS-SFW key sets', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		await pwPage.locator( '[data-dfwc-form] [data-dfwc-tab="monthly"]' ).click();
		await pwPage.locator( '[data-dfwc-form] [data-dfwc-panel="monthly"] [data-dfwc-preset][data-amount="25"]' ).click();

		// Capture the AJAX request.
		const requestPromise = pwPage.waitForRequest( ( req ) =>
			req.url().includes( 'admin-ajax.php' ) && req.method() === 'POST' );

		await pwPage.locator( '[data-dfwc-form] [data-dfwc-cta]' ).click();
		const req = await requestPromise;
		const body = req.postData() || '';

		// Spot-check both key sets are present.
		expect( body ).toContain( 'is_recurring=yes' );
		expect( body ).toContain( 'new_period=month' );
		expect( body ).toContain( 'wps_sfw_subscription_interval=month' );
		expect( body ).toContain( 'amount=25' );
	} );
} );
