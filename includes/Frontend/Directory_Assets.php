<?php
/**
 * Directory_Assets — frontend asset registration + enqueue for the
 * campaign directory grid (Phase 4).
 *
 * Separate from Frontend\Assets (which handles the donor-form overlay)
 * because the directory has different render contexts and different
 * conditional-load logic. Single registration; multiple enqueue paths.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Directory_Assets {

	public const HANDLE_CSS = 'dfwc-directory';
	public const HANDLE_JS  = 'dfwc-directory';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 10 );
	}

	public function register(): void {
		wp_register_style(
			self::HANDLE_CSS,
			DFWC_COMPANION_URL . 'assets/css/dfwc-directory.css',
			array(),
			DFWC_COMPANION_VERSION
		);

		wp_register_script(
			self::HANDLE_JS,
			DFWC_COMPANION_URL . 'assets/js/dfwc-directory.js',
			array(),
			DFWC_COMPANION_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			self::HANDLE_JS,
			'dfwcDirectory',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'dfwc-companion/v1/grid' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'loading' => __( 'Loading…', 'dfwc-companion' ),
				),
			)
		);
	}

	public function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$has_shortcode = function_exists( 'has_shortcode' ) && has_shortcode( (string) $post->post_content, 'dfwc_campaign_grid' );
		$has_block     = function_exists( 'has_block' ) && has_block( 'dfwc-companion/campaign-grid', $post );

		if ( $has_shortcode || $has_block ) {
			self::enqueue();
		}
	}

	/**
	 * Public entry point for non-detected contexts (Elementor widget,
	 * direct Renderer invocation). WordPress dedupes; safe to call any
	 * number of times.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE_CSS );
		wp_enqueue_script( self::HANDLE_JS );
	}
}
