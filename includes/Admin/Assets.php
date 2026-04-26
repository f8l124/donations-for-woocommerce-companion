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
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
	}

	public function maybe_enqueue( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'wc-donation' !== $screen->post_type ) {
			return;
		}

		wp_register_style(
			self::HANDLE_CSS,
			DFWC_COMPANION_URL . 'assets/css/dfwc-admin.css',
			[],
			DFWC_COMPANION_VERSION
		);

		wp_register_script(
			self::HANDLE_JS,
			DFWC_COMPANION_URL . 'assets/js/dfwc-admin.js',
			[],
			DFWC_COMPANION_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// Tab injector relocates our meta-box content into the parent's
		// "Recurring Donations" tab (#tab-3). Loads in footer so the parent's
		// metabox markup is fully present in the DOM by the time it runs.
		wp_register_script(
			self::HANDLE_TAB_INJECTOR,
			DFWC_COMPANION_URL . 'assets/js/dfwc-admin-tab-injector.js',
			[],
			DFWC_COMPANION_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_enqueue_style( self::HANDLE_CSS );
		wp_enqueue_script( self::HANDLE_JS );
		wp_enqueue_script( self::HANDLE_TAB_INJECTOR );
	}
}
