/**
 * Phase F.3 — engine missing: monthly/annual tabs disabled; one-time still
 * works; admin meta box shows the install-prompt notice.
 *
 * Only runs under the `none` project.
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
	loginAsAdmin,
	setCampaignIntervals,
} from './helpers';

test.describe( 'engine missing', () => {
	let campaignId: number;
	let page: { id: number; url: string };

	test.beforeAll( () => {
		const plugins = activePlugins();
		const hasEngine = plugins.includes( 'subscriptions-for-woocommerce' ) || plugins.includes( 'woocommerce-subscriptions' );
		test.skip( hasEngine, 'A recurring engine IS active; this spec requires none' );

		campaignId = createCampaign( 'E2E No Engine Campaign' );
		setCampaignIntervals( campaignId, {
			one_time: { enabled: true, presets: [ { amount: 25 } ], min: 5, max: 1000, default_index: 0, cta_template: 'Donate {amount}' },
			monthly:  { enabled: true, presets: [ { amount: 10 } ], min: 5, max: 500,  default_index: 0, cta_template: 'Donate {amount}/month' },
			annual:   { enabled: true, presets: [ { amount: 100 } ], min: 50, max: 50000, default_index: 0, cta_template: 'Donate {amount}/year' },
		} );
		page = createPageWithShortcode( 'E2E NoEngine Page', `[dfwc_recurring_donation campaign_id="${ campaignId }"]` );
	} );

	test.afterAll( () => {
		deletePost( page.id );
		deletePost( campaignId );
	} );

	test( 'donor sees only the One-time tab as enabled', async ( { page: pwPage } ) => {
		await pwPage.goto( page.url );
		const form = pwPage.locator( '[data-dfwc-form]' );
		await expect( form ).toBeVisible();

		// One-time tab is active and enabled.
		await expect( form.locator( '[data-dfwc-tab="one_time"]' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await expect( form.locator( '[data-dfwc-tab="one_time"]' ) ).not.toBeDisabled();

		// Monthly + annual tabs are present but aria-disabled.
		await expect( form.locator( '[data-dfwc-tab="monthly"]' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		await expect( form.locator( '[data-dfwc-tab="annual"]' ) ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'admin meta box shows engine-missing notice with install link', async ( { page: pwPage } ) => {
		await loginAsAdmin( pwPage );
		await pwPage.goto( `/wp-admin/post.php?post=${ campaignId }&action=edit` );
		await expect( pwPage.locator( '.dfwc-mb__notice' ).first() ).toContainText( /No recurring billing engine/i );
		await expect( pwPage.locator( '.dfwc-mb__notice a[href*="plugin-install.php"]' ).first() ).toBeVisible();
	} );
} );
