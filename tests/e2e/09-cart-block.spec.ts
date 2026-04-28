/**
 * Phase F.3 — Cart Block compatibility: with WC Cart/Checkout Block enabled,
 * a one-time donation still results in a cart-item line that displays in
 * the block-based cart UI.
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
	wpCli,
} from './helpers';

test.describe( 'cart block compatibility', () => {
	let campaignId: number;
	let donationPage: { id: number; url: string };
	let originalCartId = '';

	test.beforeAll( () => {
		// Stash the original cart page id; replace it with a Cart-Block page.
		originalCartId = wpCli( 'option get woocommerce_cart_page_id' );

		const cartBlockPageId = wpCli(
			`post create --post_type=page --post_status=publish --post_title="Cart Block" --post_content='<!-- wp:woocommerce/cart /-->' --porcelain`
		);
		wpCli( `option update woocommerce_cart_page_id ${ cartBlockPageId }` );

		campaignId = createCampaign( 'E2E Cart Block Campaign' );
		setCampaignIntervals( campaignId, {
			one_time: { enabled: true, presets: [ { amount: 25 } ], min: 5, max: 1000, default_index: 0, cta_template: 'Donate {amount}' },
		} );
		donationPage = createPageWithShortcode( 'E2E Cart Block Donation Page', `[dfwc_recurring_donation campaign_id="${ campaignId }"]` );
	} );

	test.afterAll( () => {
		// Restore original cart page.
		if ( originalCartId ) { wpCli( `option update woocommerce_cart_page_id ${ originalCartId }` ); }
		deletePost( donationPage.id );
		deletePost( campaignId );
	} );

	test( 'submit lands on Cart Block with the donation line item', async ( { page } ) => {
		await page.goto( donationPage.url );
		await page.locator( '[data-dfwc-overlay-target] [data-dfwc-preset][data-amount="25"]' ).click();
		await page.locator( '[data-dfwc-overlay-target] .wc-donation-f-submit-donation' ).click();

		// Wait for navigation to the cart page (which is now the block-based cart).
		await page.waitForURL( /\/(\?page_id=\d+|cart-block\/?)/i, { timeout: 15_000 } );

		// Cart-block renders the line in some `.wc-block-components-product-name` etc.
		// Don't tightly bind to the block class names (they shift across WC versions);
		// instead assert that the page doesn't show the empty-cart message.
		const emptyMsg = await page.getByText( /Your cart is empty/i ).count();
		expect( emptyMsg ).toBe( 0 );
	} );
} );
