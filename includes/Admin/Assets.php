<?php
/**
 * Conditional admin asset enqueue: only on the wc-donation post edit screens.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

final class Assets {

	private const HANDLE_CSS          = 'dfwc-admin';
	private const HANDLE_JS           = 'dfwc-admin';
	private const HANDLE_TAB_INJECTOR = 'dfwc-admin-tab-injector';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue( string $hook ): void {
		// Always register so other code paths can enqueue without re-declaring.
		$this->register_assets();

		// Campaign edit screen — full meta-box behavior.
		if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'wc-donation' === $screen->post_type ) {
				wp_enqueue_style( self::HANDLE_CSS );
				wp_enqueue_script( self::HANDLE_JS );
				wp_enqueue_script( self::HANDLE_TAB_INJECTOR );
				return;
			}
		}

		// Companion sub-pages (Diagnostics in Phase 2; Settings + Templates in Phase 3+).
		// WP's admin hook for sub-pages is shaped `<parent_slug>_page_<page_slug>`.
		// Match by prefix to catch all our sub-pages without listing each.
		if ( 0 === strpos( (string) $hook, 'donations-companion_page_dfwc-companion-' )
			|| 0 === strpos( (string) $hook, 'woocommerce_page_dfwc-companion' )
		) {
			wp_enqueue_style( self::HANDLE_CSS );
		}
	}

	private function register_assets(): void {
		if ( wp_style_is( self::HANDLE_CSS, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::HANDLE_CSS,
			DFWC_COMPANION_URL . 'assets/css/dfwc-admin.css',
			array(),
			DFWC_COMPANION_VERSION
		);

		wp_register_script(
			self::HANDLE_JS,
			DFWC_COMPANION_URL . 'assets/js/dfwc-admin.js',
			array(),
			DFWC_COMPANION_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Tab injector relocates our meta-box content into the parent's
		// "Recurring Donations" tab (#tab-3). Loads in footer so the parent's
		// metabox markup is fully present in the DOM by the time it runs.
		wp_register_script(
			self::HANDLE_TAB_INJECTOR,
			DFWC_COMPANION_URL . 'assets/js/dfwc-admin-tab-injector.js',
			array(),
			DFWC_COMPANION_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
