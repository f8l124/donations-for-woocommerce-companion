<?php
/**
 * Frontend asset registration + conditional enqueue + `dfwcCompanion` JS
 * localize.
 *
 * Splits register-vs-enqueue: scripts/styles register on every page so
 * out-of-band callers (e.g., the Elementor widget's render() method,
 * Phase F's Form_Replacer) can call wp_enqueue_*() at any point in the
 * request lifecycle. Conditional enqueue (shortcode/block detection) runs
 * separately on `wp_enqueue_scripts`.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const HANDLE_CSS = 'dfwc-form';
	public const HANDLE_JS  = 'dfwc-form';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'register' ], 5 );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ], 10 );
	}

	public function register(): void {
		wp_register_style(
			self::HANDLE_CSS,
			DFWC_COMPANION_URL . 'assets/css/dfwc-form.css',
			[],
			DFWC_COMPANION_VERSION
		);

		wp_register_script(
			self::HANDLE_JS,
			DFWC_COMPANION_URL . 'assets/js/dfwc-form.js',
			[],
			DFWC_COMPANION_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		wp_localize_script( self::HANDLE_JS, 'dfwcCompanion', $this->build_localize() );
	}

	/**
	 * Conditional enqueue for shortcode + block contexts. Out-of-band callers
	 * (Elementor widget, Form_Replacer) call enqueue() directly.
	 */
	public function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$has_shortcode = function_exists( 'has_shortcode' ) && has_shortcode( (string) $post->post_content, 'dfwc_recurring_donation' );
		$has_block     = function_exists( 'has_block' ) && has_block( 'dfwc-companion/recurring-donation', $post );

		if ( $has_shortcode || $has_block ) {
			self::enqueue();
		}
	}

	/**
	 * Public entry point so non-shortcode/non-block contexts (Elementor widget
	 * render(), Form_Replacer in Phase F) can pull in the assets on demand.
	 * WordPress dedupes; safe to call multiple times.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE_CSS );
		wp_enqueue_script( self::HANDLE_JS );
	}

	private function build_localize(): array {
		return [
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( '_wcdnonce' ),
			'action'         => 'donation_to_order',
			'currency'       => get_woocommerce_currency(),
			'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
			'locale'         => str_replace( '_', '-', get_locale() ),
			'i18n'           => [
				'errorGeneric' => __( 'Something went wrong. Please try again.', 'dfwc-companion' ),
				'monthly'      => '/' . __( 'month', 'dfwc-companion' ),
				'annual'       => '/' . __( 'year', 'dfwc-companion' ),
			],
		];
	}
}
