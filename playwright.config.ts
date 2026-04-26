/**
 * Playwright config: one project per recurring-engine fixture.
 *
 * The `none` and `wps` projects use auto-installable wp-env fixtures and run
 * in CI. The `wcs` project requires WooCommerce Subscriptions (paid) to be
 * mounted manually into the running container — local-only, skipped in CI.
 *
 * Workers: 1. wp-env shares one MySQL container; parallel writes from
 * Playwright create flaky data races. The OneDrive working dir on Windows
 * also has file-watcher quirks that compound at higher concurrency. (R9)
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */
import { defineConfig, devices } from '@playwright/test';

const isCI = !! process.env.CI;
const baseURL = process.env.DFWC_BASE_URL || 'http://localhost:8889';

export default defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false,
	forbidOnly: isCI,
	retries: isCI ? 2 : 0,
	workers: 1,
	reporter: isCI ? [ [ 'github' ], [ 'html', { open: 'never' } ] ] : [ [ 'html', { open: 'never' } ], [ 'list' ] ],
	timeout: 60_000,
	expect: { timeout: 10_000 },

	use: {
		baseURL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		actionTimeout: 10_000,
		navigationTimeout: 30_000,
	},

	projects: [
		{
			name: 'none',
			use: { ...devices[ 'Desktop Chrome' ] },
			testIgnore: [ '**/03-donor-monthly-wps.spec.ts', '**/04-donor-monthly-wcs.spec.ts', '**/05-donor-annual.spec.ts' ],
		},
		{
			name: 'wps',
			use: { ...devices[ 'Desktop Chrome' ] },
			testIgnore: [ '**/04-donor-monthly-wcs.spec.ts', '**/06-engine-missing.spec.ts' ],
		},
		{
			name: 'wcs',
			use: { ...devices[ 'Desktop Chrome' ] },
			testIgnore: [ '**/03-donor-monthly-wps.spec.ts', '**/06-engine-missing.spec.ts' ],
		},
	],
} );
