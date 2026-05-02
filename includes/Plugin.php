<?php
/**
 * Bootstrap singleton for the companion plugin.
 *
 * Wires HPOS / Cart Blocks compatibility, runs dependency guards, detects the
 * recurring engine, and instantiates the admin + frontend submodules.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private string $engine = Engine_Detector::ENGINE_NONE;

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->boot();
	}

	public function engine(): string {
		return $this->engine;
	}

	public function is_booted(): bool {
		return $this->booted;
	}

	private function boot(): void {
		// HPOS / Cart Blocks declaration must run before WC's own init regardless of
		// dependency check outcome (so we don't appear "incompatible" on the WC screen).
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

		if ( ! $this->check_dependencies() ) {
			return;
		}

		$this->engine = Engine_Detector::detect();

		// Schema migrations first — they run on plugins_loaded:5-ish, before
		// any save handler that might consume the new schema. Idempotent.
		( new Config\Migration_Service() )->maybe_migrate();

		new Admin\Meta_Box();
		new Admin\Assets();
		new Admin\Admin_Menu();
		new Admin\Settings_Page();
		new Admin\Templates_Page();
		new Admin\Bulk_Actions();
		new Admin\Diagnostics_Page();
		new Admin\Self_Check();
		new Frontend\Shortcode();
		new Frontend\Block();
		new Frontend\Assets();
		new Frontend\Submit_Guard();
		new Frontend\Context_Augmenter();
		new Frontend\Elementor_Adapter();

		// Phase 4 — campaign directory + taxonomies.
		new Taxonomy\Campaign_Taxonomies();
		new Frontend\Campaign_Grid_Shortcode();
		new Frontend\Campaign_Grid_Block();
		new Frontend\Directory_Assets();
		new REST\Grid_REST_Controller();

		// Phase 8 — live admin preview.
		new Admin\Preview_Controller();
		new REST\Preview_REST_Controller();

		// Phase 9 — event hooks (analytics / CRM / webhook integration).
		new Analytics\Submission_Tracker();
		new REST\Track_REST_Controller();

		// Phase 14A — built-in stock donations + Overflow integration. CPT
		// + admin reconciliation page wire unconditionally; the donor-side
		// affordance gates on the global toggle at render time.
		new Stock\Stock_Pledge_Post_Type();
		new Admin\Stock_Pledges_Page();
		new REST\Stock_Pledge_REST_Controller();
		new REST\Overflow_Webhook_REST_Controller();

		// Phase 17 (v2.1.0) — QuickBooks sync moved to a sibling plugin
		// (donations-for-woocommerce-qbo-sync). Companion shows a one-time
		// admin notice if legacy options are present but the new plugin
		// isn't installed.
		new Admin\QBO_Migration_Notice();

		// Phase 14 (v2.4.0) — cause-aware giving. Sub-phase 14.A wires
		// stable cause identity (companion-minted UUIDs threaded through
		// the cart → order line item meta). 14.C wires the per-cause
		// raised-amount aggregator + invalidation hooks. Per-cause
		// goal storage (14.B) lives in Config\Cause_Goals_Schema with no
		// runtime hooks. Closure UX (14.D) lands separately.
		new Config\Cause_Identity();
		new Config\Cause_Raised_Aggregator();

		// Phase 13 (v2.3.0) — crypto donations via The Giving Block.
		// Admin settings page is always wired (so admins can configure
		// before enabling). Donor-side rendering happens via the
		// Crypto_Donation_Renderer attribute emit in Renderer/Augmenter
		// (13.B). Pending-order REST + WC payment method registration
		// always wire — gating happens server-side via the global toggle.
		new Admin\Crypto_Settings_Page();
		new Gateways\TGB_Payment_Gateway();
		new REST\Crypto_Pending_Order_REST_Controller();
		new REST\TGB_Webhook_REST_Controller();

		// Phase 11 — `wp dfwc-companion` CLI commands. No-op outside WP-CLI.
		CLI\CLI_Commands::register();

		$this->booted = true;
	}

	public function declare_compatibility(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DFWC_COMPANION_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DFWC_COMPANION_FILE, true );
	}

	private function check_dependencies(): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->add_notice(
				__( 'Donations for WooCommerce Companion requires WooCommerce to be active.', 'dfwc-companion' ),
				'error'
			);
			return false;
		}

		if ( ! defined( 'WC_DONATION_VERSION' ) ) {
			$this->add_notice(
				__( 'Donations for WooCommerce Companion requires the Donation for WooCommerce plugin to be active.', 'dfwc-companion' ),
				'error'
			);
			return false;
		}

		if ( version_compare( WC_DONATION_VERSION, DFWC_COMPANION_MIN_PARENT_VERSION, '<' ) ) {
			$this->add_notice(
				sprintf(
					/* translators: 1: detected parent plugin version, 2: required minimum version */
					__( 'Donations for WooCommerce Companion is tested with Donation for WooCommerce %2$s or newer. You are running %1$s; recurring intervals may not behave as expected.', 'dfwc-companion' ),
					WC_DONATION_VERSION,
					DFWC_COMPANION_MIN_PARENT_VERSION
				),
				'warning'
			);
			// Warn but continue — the integration may still work for many features.
		}

		if ( version_compare( WC_DONATION_VERSION, '4.0.0', '>=' ) ) {
			$this->add_notice(
				__( 'Donations for WooCommerce Companion has not been tested with Donation for WooCommerce 4.x. Please verify recurring donations before relying on this plugin.', 'dfwc-companion' ),
				'warning'
			);
		}

		return true;
	}

	private function add_notice( string $message, string $severity = 'info' ): void {
		add_action(
			'admin_notices',
			static function () use ( $message, $severity ) {
				printf(
					'<div class="notice notice-%1$s"><p>%2$s</p></div>',
					esc_attr( $severity ),
					esc_html( $message )
				);
			}
		);
	}
}
