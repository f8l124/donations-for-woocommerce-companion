<?php
/**
 * Frontend asset registration + conditional enqueue + `dfwcCompanion` JS
 * localize.
 *
 * Splits register-vs-enqueue: scripts/styles register on every page so
 * out-of-band callers (Elementor widget render(), Renderer::render() when
 * driven from a non-shortcode/non-block context) can call wp_enqueue_*()
 * at any point in the request lifecycle. Conditional enqueue (shortcode /
 * block detection) runs separately on `wp_enqueue_scripts`.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const HANDLE_CSS = 'dfwc-overlay';
	public const HANDLE_JS  = 'dfwc-overlay';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ), 10 );
	}

	public function register(): void {
		wp_register_style(
			self::HANDLE_CSS,
			DFWC_COMPANION_URL . 'assets/css/dfwc-overlay.css',
			array(),
			DFWC_COMPANION_VERSION
		);

		wp_register_script(
			self::HANDLE_JS,
			DFWC_COMPANION_URL . 'assets/js/dfwc-overlay.js',
			array(),
			DFWC_COMPANION_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script( self::HANDLE_JS, 'dfwcCompanion', $this->build_localize() );
	}

	/**
	 * Conditional enqueue for shortcode + block contexts. Out-of-band callers
	 * (Elementor widget, Renderer when invoked outside has_shortcode/has_block
	 * detection) call enqueue() directly.
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
	 * render(), Renderer when called from arbitrary code paths) can pull in
	 * the assets on demand. WordPress dedupes; safe to call multiple times.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE_CSS );
		wp_enqueue_script( self::HANDLE_JS );
	}

	private function build_localize(): array {
		return array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( '_wcdnonce' ),
			'action'         => 'donation_to_order',
			'cartUrl'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
			'currency'       => get_woocommerce_currency(),
			'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
			'locale'         => str_replace( '_', '-', get_locale() ),
			// Phase 9 — analytics track endpoint + nonce. Public endpoint
			// (allows nopriv donors); the nonce protects against stale
			// browser state replaying old payloads, not against forgery.
			'trackUrl'       => function_exists( 'rest_url' ) ? rest_url( 'dfwc-companion/v1/track' ) : '',
			'trackNonce'     => wp_create_nonce( 'wp_rest' ),
			'i18n'           => array(
				'errorGeneric'        => __( 'Something went wrong. Please try again.', 'dfwc-companion' ),
				'errorAmountRequired' => __( 'Please choose a preset amount or enter a custom amount.', 'dfwc-companion' ),
				'errorNetwork'        => __( 'Network error. Please check your connection and try again.', 'dfwc-companion' ),
				'monthly'             => '/' . __( 'month', 'dfwc-companion' ),
				'annual'              => '/' . __( 'year', 'dfwc-companion' ),
				// Phase 13 — goal-aware giving copy.
				'goalMetHeading'      => __( 'This campaign reached its goal!', 'dfwc-companion' ),
				'goalMetCopy'         => __( 'Want to keep supporting our work? Make a gift to our general fund instead.', 'dfwc-companion' ),
				'generalFundCta'      => __( 'Give to the general fund', 'dfwc-companion' ),
			),
		);
	}
}
