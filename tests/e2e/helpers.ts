/**
 * Shared E2E helpers: WP-CLI wrappers, admin login, fixture setup/teardown.
 *
 * Spec files import these to avoid duplicating WP-CLI shell-out boilerplate.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
import { execSync } from 'node:child_process';
import type { Page } from '@playwright/test';

/**
 * Run a WP-CLI command via wp-env's `cli` container. Output is returned as
 * trimmed string. Throws on non-zero exit. The `wp` prefix is added here.
 */
export function wpCli( args: string ): string {
	const cmd = `wp-env run cli wp ${ args }`;
	return execSync( cmd, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim();
}

/**
 * Best-effort wp-cli runner that won't throw — used in cleanup code.
 */
export function wpCliSafe( args: string ): string {
	try { return wpCli( args ); }
	catch { return ''; }
}

/**
 * Admin login via the standard wp-login.php form. Defaults match wp-env's
 * out-of-box admin user (admin/password).
 */
export async function loginAsAdmin( page: Page, username = 'admin', password = 'password' ): Promise<void> {
	await page.goto( '/wp-login.php' );
	await page.fill( 'input#user_login', username );
	await page.fill( 'input#user_pass', password );
	await page.click( 'input#wp-submit' );
	await page.waitForURL( /\/wp-admin\/?/ );
}

/**
 * Create a wc-donation campaign via WP-CLI. Returns the new post ID.
 */
export function createCampaign( title: string ): number {
	const id = wpCli( `post create --post_type=wc-donation --post_status=publish --post_title="${ title }" --porcelain` );
	const num = parseInt( id, 10 );
	if ( ! Number.isFinite( num ) || num < 1 ) {
		throw new Error( `createCampaign: unexpected wp-cli output: ${ id }` );
	}
	return num;
}

/**
 * Create a page containing the given shortcode. Returns the new post ID
 * and the front-end permalink.
 */
export function createPageWithShortcode( title: string, shortcode: string ): { id: number; url: string } {
	const id = wpCli( `post create --post_type=page --post_status=publish --post_title="${ title }" --post_content="${ shortcode }" --porcelain` );
	const num = parseInt( id, 10 );
	if ( ! Number.isFinite( num ) || num < 1 ) {
		throw new Error( `createPageWithShortcode: unexpected wp-cli output: ${ id }` );
	}
	const url = wpCli( `post get ${ num } --field=guid` );
	return { id: num, url };
}

/**
 * Set per-campaign companion config via WP-CLI. Pass a partial — defaults
 * fill in unspecified fields when Config_Resolver reads it back.
 */
export function setCampaignIntervals( campaignId: number, config: object ): void {
	const json = JSON.stringify( config ).replace( /'/g, "'\\''" );
	wpCli( `post meta update ${ campaignId } _dfwc_companion_intervals '${ json }' --format=json` );
}

/**
 * Force-set the parent's wc-donation-recurring meta directly (for tests
 * that bypass our save handler).
 */
export function setParentRecurringMode( campaignId: number, mode: 'user' | 'enabled' | 'disabled' ): void {
	wpCli( `post meta update ${ campaignId } wc-donation-recurring "${ mode }"` );
}

/**
 * Clean up a post + its meta. Quiet on missing.
 */
export function deletePost( id: number ): void {
	wpCliSafe( `post delete ${ id } --force` );
}

/**
 * Active plugins, one slug per line.
 */
export function activePlugins(): string[] {
	return wpCli( 'plugin list --status=active --field=name' ).split( '\n' ).map( s => s.trim() ).filter( Boolean );
}

/**
 * Wait for the form's CTA text to match a regex (substring match).
 * The CTA updates asynchronously after preset/custom-amount changes.
 */
export async function waitForCtaText( page: Page, formSelector: string, pattern: RegExp ): Promise<void> {
	await page.waitForFunction(
		( { sel, src } ) => {
			const root = document.querySelector( sel );
			if ( ! root ) { return false; }
			const cta = root.querySelector( '[data-dfwc-cta]' );
			if ( ! cta ) { return false; }
			return new RegExp( src ).test( cta.textContent || '' );
		},
		{ sel: formSelector, src: pattern.source },
		{ timeout: 5_000 }
	);
}

/**
 * Pull a cart item's meta from inside WP via the REST API (handy for
 * asserting `billing_period` etc. without screen-scraping the cart UI).
 */
export function dumpCartItems(): string {
	// Note: wc_cart isn't exposed via REST out of the box. Use a small
	// inline PHP eval for tests that need this — keeps spec code clean.
	return wpCli( 'eval "WC()->frontend_includes(); echo json_encode( WC()->cart ? WC()->cart->get_cart() : [] );"' );
}
