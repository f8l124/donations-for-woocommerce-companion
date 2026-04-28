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

	public const PARENT_SLUG     = 'dfwc-companion';
	public const CAPABILITY      = 'manage_woocommerce';
	public const DIAGNOSTICS_SLUG = 'dfwc-companion-diagnostics';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	public function register(): void {
		// Parent submenu under WooCommerce. Title shows up in left nav.
		add_submenu_page(
			'woocommerce',
			__( 'Donations Companion', 'dfwc-companion' ),
			__( 'Donations Companion', 'dfwc-companion' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( $this, 'render_parent_redirect' )
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

		// Phase 3 will add: Settings, Templates here.
	}

	/**
	 * Parent slug renderer — redirects to the diagnostics page until Phase 3
	 * ships the Settings page (which becomes the new default landing).
	 */
	public function render_parent_redirect(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'dfwc-companion' ) );
		}
		$url = admin_url( 'admin.php?page=' . self::DIAGNOSTICS_SLUG );
		wp_safe_redirect( $url );
		exit;
	}
}
