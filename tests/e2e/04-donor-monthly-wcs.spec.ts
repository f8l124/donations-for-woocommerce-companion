/**
 * Phase F.3 — donor flow: Monthly under WC Subscriptions (paid plugin).
 *
 * WCS isn't installable from wp.org; this spec only runs under the `wcs`
 * project, which expects the plugin to be mounted manually. Skipped in CI.
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

test.describe( 'donor: monthly (WC Subscriptions)', () => {
	let campaignId: number;
	let page: { id: number; url: string };

	test.beforeAll( () => {
		const plugins = activePlugins();
		test.skip( ! plugins.includes( 'woocommerce-subscriptions' ), 'WC Subscriptions not active in this fixture' );

		campaignId = createCampaign( 'E2E Monthly WCS Campaign' );
		setCampaignIntervals( campaignId, {
			one_time: { enabled: true, presets: [ { amount: 25 } ], min: 5, max: 1000, default_index: 0, cta_template: 'Donate {amount}' },
			monthly:  { enabled: true, presets: [ { amount: 25 } ], min: 5, max: 500,  default_index: 0, cta_template: 'Donate {amount}/month' },
			annual:   { enabled: false, presets: [], min: 50, max: 50000, default_index: 0, cta_template: '' },
		} );
		setParentRecurringMode( campaignId, 'user' );
		page = createPageWithShortcode( 'E2E Monthly WCS Page', `[dfwc_recurring_donation campaign_id="${ campaignId }"]` );
	} );

	test.afterAll( () => {
		deletePost( page.id );
		deletePost( campaignId );
	} );

	test( 'monthly tab renders + CTA updates', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		await pwPage.locator( '[data-dfwc-form] [data-dfwc-tab="monthly"]' ).click();
		await waitForCtaText( pwPage, '[data-dfwc-form]', /25.*month/ );
	} );
} );
