/**
 * Phase 7 — donor flow under advanced giving intervals (weekly / quarterly /
 * semiannual / custom). Verifies:
 *   - The global toggle gates donor visibility (off → only standard 3 tabs).
 *   - Each advanced cadence ships the right (period, multiplier) AJAX keys
 *     to the parent's donate handler.
 *   - The custom interval respects the admin-configured cadence.
 *
 * Skipped on `none` (no engine = recurring intervals are hidden anyway) and
 * on `wcs` (paid; not in CI). Runs under `wps` only.
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
	wpCli,
	wpCliSafe,
} from './helpers';

test.describe( 'donor: advanced intervals (WPS SFW)', () => {
	let campaignId: number;
	let page: { id: number; url: string };
	let savedAdvancedToggle = '';

	test.beforeAll( () => {
		const plugins = activePlugins();
		test.skip( ! plugins.includes( 'subscriptions-for-woocommerce' ), 'WPS SFW not active in this fixture' );

		// Stash the current global option, then set the advanced-intervals
		// toggle on for the duration of the spec.
		savedAdvancedToggle = wpCliSafe( 'option get dfwc_companion_global_settings --format=json' );
		wpCli(
			"option update dfwc_companion_global_settings '" +
			JSON.stringify( {
				version: 1,
				default_template_id: '',
				preserve_data_on_uninstall: true,
				enable_advanced_intervals: true,
			} ) +
			"' --format=json"
		);

		campaignId = createCampaign( 'E2E Advanced Intervals' );
		setCampaignIntervals( campaignId, {
			one_time: {
				enabled: true,
				presets: [ { amount: 25 } ],
				min: 5,
				max: 1000,
				default_index: 0,
				cta_template: 'Donate {amount}',
			},
			monthly: {
				enabled: false,
				presets: [],
				min: 5,
				max: 500,
				default_index: 0,
				cta_template: '',
			},
			annual: {
				enabled: false,
				presets: [],
				min: 50,
				max: 50_000,
				default_index: 0,
				cta_template: '',
			},
			weekly: {
				enabled: true,
				presets: [ { amount: 5 }, { amount: 10 }, { amount: 25 } ],
				min: 2,
				max: 500,
				default_index: 1,
				cta_template: 'Donate {amount}/week',
			},
			quarterly: {
				enabled: true,
				presets: [ { amount: 50 }, { amount: 100 } ],
				min: 25,
				max: 5_000,
				default_index: 0,
				cta_template: 'Donate {amount} every 3 months',
			},
			semiannual: {
				enabled: true,
				presets: [ { amount: 100 }, { amount: 250 } ],
				min: 50,
				max: 10_000,
				default_index: 0,
				cta_template: 'Donate {amount} every 6 months',
			},
			custom: {
				enabled: true,
				presets: [ { amount: 30 } ],
				min: 10,
				max: 1_000,
				default_index: 0,
				cta_template: 'Donate {amount} {custom_label}',
				custom_period: 'week',
				custom_interval: 6,
				custom_label: 'every 6 weeks',
			},
		} );
		setParentRecurringMode( campaignId, 'user' );
		page = createPageWithShortcode(
			'E2E Advanced Intervals Page',
			`[dfwc_recurring_donation campaign_id="${ campaignId }"]`
		);
	} );

	test.afterAll( () => {
		deletePost( page.id );
		deletePost( campaignId );
		// Restore the original global option.
		if ( savedAdvancedToggle ) {
			wpCliSafe(
				`option update dfwc_companion_global_settings '${ savedAdvancedToggle.replace( /'/g, "'\\''" ) }' --format=json`
			);
		} else {
			wpCliSafe( 'option delete dfwc_companion_global_settings' );
		}
	} );

	test( 'all 7 interval tabs render when toggle is on and campaigns enable them', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		const form = pwPage.locator( '[data-dfwc-overlay-target]' );
		await expect( form ).toBeVisible();

		await expect( form.locator( '[data-dfwc-tab="one_time"]' ) ).toBeVisible();
		await expect( form.locator( '[data-dfwc-tab="weekly"]' ) ).toBeVisible();
		await expect( form.locator( '[data-dfwc-tab="quarterly"]' ) ).toBeVisible();
		await expect( form.locator( '[data-dfwc-tab="semiannual"]' ) ).toBeVisible();
		await expect( form.locator( '[data-dfwc-tab="custom"]' ) ).toBeVisible();

		// monthly / annual were left disabled in the test fixture, so they
		// should NOT render.
		await expect( form.locator( '[data-dfwc-tab="monthly"]' ) ).toHaveCount( 0 );
		await expect( form.locator( '[data-dfwc-tab="annual"]' ) ).toHaveCount( 0 );
	} );

	test( 'weekly submit ships period=week, interval=1 in both engine key sets', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-tab="weekly"]' ).click();
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-panel="weekly"] [data-dfwc-preset][data-amount="10"]' ).click();

		const requestPromise = pwPage.waitForRequest( ( req ) =>
			req.url().includes( 'admin-ajax.php' ) && req.method() === 'POST'
		);
		await pwPage.locator( '[data-dfwc-overlay-target] .wc-donation-f-submit-donation' ).click();
		const body = ( await requestPromise ).postData() || '';

		expect( body ).toContain( 'is_recurring=yes' );
		expect( body ).toContain( 'new_period=week' );
		expect( body ).toContain( 'new_interval=1' );
		expect( body ).toContain( 'wps_sfw_subscription_interval=week' );
		expect( body ).toContain( 'wps_sfw_subscription_number=1' );
	} );

	test( 'quarterly submit ships period=month, interval=3', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-tab="quarterly"]' ).click();
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-panel="quarterly"] [data-dfwc-preset][data-amount="50"]' ).click();

		const requestPromise = pwPage.waitForRequest( ( req ) =>
			req.url().includes( 'admin-ajax.php' ) && req.method() === 'POST'
		);
		await pwPage.locator( '[data-dfwc-overlay-target] .wc-donation-f-submit-donation' ).click();
		const body = ( await requestPromise ).postData() || '';

		expect( body ).toContain( 'new_period=month' );
		expect( body ).toContain( 'new_interval=3' );
		expect( body ).toContain( 'wps_sfw_subscription_interval=month' );
		expect( body ).toContain( 'wps_sfw_subscription_number=3' );
	} );

	test( 'semiannual submit ships period=month, interval=6', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-tab="semiannual"]' ).click();
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-panel="semiannual"] [data-dfwc-preset][data-amount="100"]' ).click();

		const requestPromise = pwPage.waitForRequest( ( req ) =>
			req.url().includes( 'admin-ajax.php' ) && req.method() === 'POST'
		);
		await pwPage.locator( '[data-dfwc-overlay-target] .wc-donation-f-submit-donation' ).click();
		const body = ( await requestPromise ).postData() || '';

		expect( body ).toContain( 'new_period=month' );
		expect( body ).toContain( 'new_interval=6' );
		expect( body ).toContain( 'wps_sfw_subscription_number=6' );
	} );

	test( 'custom interval honors admin cadence (every 6 weeks)', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-tab="custom"]' ).click();
		await pwPage.locator( '[data-dfwc-overlay-target] [data-dfwc-panel="custom"] [data-dfwc-preset][data-amount="30"]' ).click();

		const requestPromise = pwPage.waitForRequest( ( req ) =>
			req.url().includes( 'admin-ajax.php' ) && req.method() === 'POST'
		);
		await pwPage.locator( '[data-dfwc-overlay-target] .wc-donation-f-submit-donation' ).click();
		const body = ( await requestPromise ).postData() || '';

		expect( body ).toContain( 'new_period=week' );
		expect( body ).toContain( 'new_interval=6' );
		expect( body ).toContain( 'wps_sfw_subscription_interval=week' );
		expect( body ).toContain( 'wps_sfw_subscription_number=6' );
	} );

	test( 'global toggle off hides advanced tabs even when campaign enables them', async ( { page: pwPage } ) => {
		// Flip the toggle off.
		wpCli(
			"option update dfwc_companion_global_settings '" +
			JSON.stringify( {
				version: 1,
				default_template_id: '',
				preserve_data_on_uninstall: true,
				enable_advanced_intervals: false,
			} ) +
			"' --format=json"
		);

		await pwPage.goto( page.url );
		const form = pwPage.locator( '[data-dfwc-overlay-target]' );
		await expect( form ).toBeVisible();

		// Standard tabs visible (one_time only — monthly/annual disabled).
		await expect( form.locator( '[data-dfwc-tab="one_time"]' ) ).toBeVisible();

		// Advanced tabs gone.
		await expect( form.locator( '[data-dfwc-tab="weekly"]' ) ).toHaveCount( 0 );
		await expect( form.locator( '[data-dfwc-tab="quarterly"]' ) ).toHaveCount( 0 );
		await expect( form.locator( '[data-dfwc-tab="semiannual"]' ) ).toHaveCount( 0 );
		await expect( form.locator( '[data-dfwc-tab="custom"]' ) ).toHaveCount( 0 );

		// Restore for any subsequent specs in the same project run.
		wpCli(
			"option update dfwc_companion_global_settings '" +
			JSON.stringify( {
				version: 1,
				default_template_id: '',
				preserve_data_on_uninstall: true,
				enable_advanced_intervals: true,
			} ) +
			"' --format=json"
		);
	} );
} );
