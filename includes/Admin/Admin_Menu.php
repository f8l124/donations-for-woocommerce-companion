<?php
/**
 * Admin_Menu — registers the parent "Donations Companion" submenu under
 * WooCommerce, plus the Diagnostics sub-page (the only sub-page in v0.7.0
 * Phase 2). Phase 3 extends this with Settings + Templates.
 *
 * Tight integration: lives under `WooCommerce → Donations Companion` rather
 * than a top-level WP admin menu. This matches every other respectable WC
 * extension. The parent submenu redirects to Diagnostics until Phase 3 ships
 * a Settings page to redirect to instead.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

final class Admin_Menu {

	public const PARENT_SLUG      = 'dfwc-companion';
	public const CAPABILITY       = 'manage_woocommerce';
	public const SETTINGS_SLUG    = 'dfwc-companion';
	public const TEMPLATES_SLUG   = 'dfwc-companion-templates';
	public const DIAGNOSTICS_SLUG = 'dfwc-companion-diagnostics';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	public function register(): void {
		// Parent submenu under WooCommerce. The page-slug here matches
		// SETTINGS_SLUG so clicking the parent menu item lands on Settings.
		add_submenu_page(
			'woocommerce',
			__( 'Donations Companion', 'dfwc-companion' ),
			__( 'Donations Companion', 'dfwc-companion' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( Settings_Page::class, 'render' )
		);

		// Settings explicit submenu — title differs from the parent group label.
		// WP automatically adds a submenu mirroring the parent slug; we override
		// its label to "Settings" by re-registering with the same slug.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'dfwc-companion' ),
			__( 'Settings', 'dfwc-companion' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( Settings_Page::class, 'render' )
		);

		// Templates list + edit (single page; edit mode via ?action=edit&id=...).
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Templates', 'dfwc-companion' ),
			__( 'Templates', 'dfwc-companion' ),
			self::CAPABILITY,
			self::TEMPLATES_SLUG,
			array( Templates_Page::class, 'render' )
		);

		// Diagnostics sub-page.
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Diagnostics', 'dfwc-companion' ),
			__( 'Diagnostics', 'dfwc-companion' ),
			self::CAPABILITY,
			self::DIAGNOSTICS_SLUG,
			array( Diagnostics_Page::class, 'render' )
		);
	}
}
