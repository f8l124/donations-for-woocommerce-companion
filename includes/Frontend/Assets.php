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

	public const HANDLE_CSS        = 'dfwc-overlay';
	public const HANDLE_JS         = 'dfwc-overlay';
	public const HANDLE_CRYPTO_CSS = 'dfwc-crypto';
	public const HANDLE_CRYPTO_JS  = 'dfwc-crypto';

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

		// v2.3.0 sub-phase 13.B — crypto donations module. Self-contained
		// (no dependency on dfwc-overlay.js); registered separately so it
		// can be enqueued + cache-busted independently.
		wp_register_style(
			self::HANDLE_CRYPTO_CSS,
			DFWC_COMPANION_URL . 'assets/css/dfwc-crypto.css',
			array(),
			DFWC_COMPANION_VERSION
		);

		wp_register_script(
			self::HANDLE_CRYPTO_JS,
			DFWC_COMPANION_URL . 'assets/js/dfwc-crypto.js',
			array(),
			DFWC_COMPANION_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
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
		// Crypto module ships alongside the cash overlay — donor-side gating
		// happens in the JS by reading data-crypto-enabled on the wrapper, so
		// enqueueing unconditionally costs only the parse of a small inert
		// module on pages where crypto isn't enabled.
		wp_enqueue_style( self::HANDLE_CRYPTO_CSS );
		wp_enqueue_script( self::HANDLE_CRYPTO_JS );
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
			// Phase 14A — stock pledge endpoint. Public endpoint;
			// rate-limited server-side. Same wp_rest nonce used as
			// the track endpoint.
			'stockPledgeUrl' => function_exists( 'rest_url' ) ? rest_url( 'dfwc-companion/v1/stock-pledge' ) : '',
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
				// Phase 14A — stock pledge form copy.
				'donateStockToggle'   => __( 'Donate stock instead', 'dfwc-companion' ),
				'donateStockOverflow' => __( 'Donate stock via Overflow', 'dfwc-companion' ),
				'stockIntro'          => __( 'Pledge stock to support this campaign. We\'ll send DTC transfer instructions to your email.', 'dfwc-companion' ),
				'stockDonorName'      => __( 'Your name', 'dfwc-companion' ),
				'stockDonorEmail'     => __( 'Email', 'dfwc-companion' ),
				'stockDonorPhone'     => __( 'Phone (optional)', 'dfwc-companion' ),
				'stockBrokerName'     => __( 'Your broker', 'dfwc-companion' ),
				'stockTicker'         => __( 'Ticker', 'dfwc-companion' ),
				'stockShares'         => __( 'Shares', 'dfwc-companion' ),
				'stockEstimatedValue' => __( 'Estimated value', 'dfwc-companion' ),
				'stockNotes'          => __( 'Notes (optional)', 'dfwc-companion' ),
				'stockSubmit'         => __( 'Submit pledge', 'dfwc-companion' ),
				'stockSuccess'        => __( 'Pledge received! Check your email for transfer instructions.', 'dfwc-companion' ),
				'stockNetworkError'   => __( 'Could not submit pledge. Please try again or email us directly.', 'dfwc-companion' ),
				// v2.3.0 — crypto donation copy.
				'cryptoDivider'       => __( 'or donate non-cash', 'dfwc-companion' ),
				'donateCrypto'        => __( 'Donate Crypto', 'dfwc-companion' ),
				'cryptoLoading'       => __( 'Loading…', 'dfwc-companion' ),
				'cryptoLoadError'     => __( 'Could not load the donation widget. Please try again.', 'dfwc-companion' ),
				'cryptoMountError'    => __( 'Could not mount the donation widget.', 'dfwc-companion' ),
				'cryptoHostedFallback' => __( 'Open the donation page', 'dfwc-companion' ),
				'cryptoPending'       => __( 'Donation submitted. Awaiting on-chain confirmation…', 'dfwc-companion' ),
				'cryptoSuccess'       => __( 'Thanks! We\'ll email you when the donation is fully confirmed on-chain.', 'dfwc-companion' ),
				'cryptoRecordingError' => __( 'Donation received by The Giving Block. Order recording on our side delayed; we\'ll reconcile via the webhook.', 'dfwc-companion' ),
				// v2.4.0 — cause closure copy.
				'causeClosedGeneric'   => __( 'This cause has reached its goal. Please choose another cause.', 'dfwc-companion' ),
				/* translators: %s: cause name (e.g., "Education") */
				'causeMetHeading'      => __( '%s reached its goal!', 'dfwc-companion' ),
				'causeMetCopy'         => __( 'Please consider supporting one of these still-open causes:', 'dfwc-companion' ),
				'causeMetNoAlternatives' => __( 'All causes on this campaign have reached their goals — please consider supporting our general fund.', 'dfwc-companion' ),
			),
			// v2.3.0 — TGB widget URL overrides. Filterable so admins on
			// hosted-private TGB deployments can override without forking.
			'tgb' => apply_filters(
				'dfwc_companion_tgb_widget_localize',
				array(
					'widgetScriptUrlSandbox'    => '',
					'widgetScriptUrlProduction' => '',
					'hostedUrl'                 => '',
				)
			),
		);
	}
}
