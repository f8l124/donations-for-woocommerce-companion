<?php
/**
 * Admin health probe — runs cached checks on `admin_init` to detect parent
 * plugin contract changes and engine-missing scenarios that would silently
 * break the companion.
 *
 * Findings are stored in a 12-hour transient and rendered as admin notices.
 * Per `manage_woocommerce` capability gate so only relevant admins see them.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Admin;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config_Resolver;
use DFWC\Companion\Engine_Detector;

final class Self_Check {

	private const TRANSIENT_KEY = 'dfwc_self_check';
	private const TTL_SECONDS   = 12 * HOUR_IN_SECONDS;

	private const SEVERITY_ERROR   = 'error';
	private const SEVERITY_WARNING = 'warning';
	private const SEVERITY_INFO    = 'info';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_run' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	public function maybe_run(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Skip if cache is fresh (false transient = expired/missing).
		if ( false !== get_transient( self::TRANSIENT_KEY ) ) {
			return;
		}

		set_transient( self::TRANSIENT_KEY, $this->run_checks(), self::TTL_SECONDS );
	}

	public function render_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$findings = get_transient( self::TRANSIENT_KEY );
		if ( ! is_array( $findings ) || empty( $findings ) ) {
			return;
		}

		// Suppress on screens where notices would be inappropriate (e.g.
		// the WP installer or update screens). Use a conservative allow-list.
		if ( ! $this->should_render_on_current_screen() ) {
			return;
		}

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$severity = isset( $finding['severity'] ) ? (string) $finding['severity'] : self::SEVERITY_INFO;
			$message  = isset( $finding['message'] ) ? (string) $finding['message'] : '';
			if ( '' === $message ) {
				continue;
			}
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
				esc_attr( $severity ),
				esc_html__( 'Donations for WooCommerce Companion:', 'dfwc-companion' ),
				esc_html( $message )
			);
		}
	}

	/**
	 * Run all checks and return a list of finding arrays:
	 *   [ ['severity' => ..., 'message' => ..., 'code' => ...], ... ]
	 *
	 * @return array<int,array<string,string>>
	 */
	private function run_checks(): array {
		$findings = array();

		// Check 1: parent's AJAX endpoint must be registered.
		if ( false === has_action( 'wp_ajax_donation_to_order' ) ) {
			$findings[] = array(
				'severity' => self::SEVERITY_ERROR,
				'message'  => __( 'The Donation for WooCommerce AJAX endpoint is not registered. Recurring and one-time donations may both fail. Verify the parent plugin is active and not modified.', 'dfwc-companion' ),
				'code'     => 'ajax_endpoint_missing',
			);
		}

		// Check 2: parent plugin must define its version constant.
		if ( ! defined( 'WC_DONATION_VERSION' ) ) {
			$findings[] = array(
				'severity' => self::SEVERITY_ERROR,
				'message'  => __( 'Donation for WooCommerce is not active. The companion plugin will not function until the parent is enabled.', 'dfwc-companion' ),
				'code'     => 'parent_inactive',
			);
		} elseif ( version_compare( WC_DONATION_VERSION, '4.0.0', '>=' ) ) {
			// Check 3: warn on untested major-version bump.
			$findings[] = array(
				'severity' => self::SEVERITY_WARNING,
				'message'  => sprintf(
					/* translators: %s: detected parent plugin version */
					__( 'Donation for WooCommerce %s has not been tested with this companion. Verify recurring donations still complete to cart with the right line-item meta before relying on this site.', 'dfwc-companion' ),
					WC_DONATION_VERSION
				),
				'code'     => 'parent_major_bump',
			);
		}

		// Check 4: campaigns offering recurring intervals while no engine is active.
		if ( ! Engine_Detector::supports_recurring() ) {
			$count = $this->count_campaigns_with_recurring_enabled();
			if ( $count > 0 ) {
				$findings[] = array(
					'severity' => self::SEVERITY_WARNING,
					'message'  => sprintf(
						/* translators: %d: number of campaigns affected */
						_n(
							'%d campaign has Monthly or Annually enabled but no recurring billing engine is active. Donors will only see the One-time tab.',
							'%d campaigns have Monthly or Annually enabled but no recurring billing engine is active. Donors will only see the One-time tab.',
							$count,
							'dfwc-companion'
						),
						$count
					),
					'code'     => 'recurring_no_engine',
				);
			}
		}

		return $findings;
	}

	/**
	 * Count published wc-donation campaigns whose companion config has
	 * monthly OR annual enabled. Bounded by the number of campaigns with
	 * companion meta set; typical scale is dozens, not thousands.
	 */
	private function count_campaigns_with_recurring_enabled(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'wc-donation',
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'meta_key'       => Config_Resolver::META_KEY_INTERVALS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'suppress_filters' => false,
			)
		);

		$count = 0;
		foreach ( $ids as $id ) {
			$config = Config_Resolver::resolve( (int) $id );
			if ( ! empty( $config[ Config_Resolver::INTERVAL_MONTHLY ]['enabled'] )
				|| ! empty( $config[ Config_Resolver::INTERVAL_ANNUAL ]['enabled'] )
			) {
				$count++;
			}
		}
		return $count;
	}

	private function should_render_on_current_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return true;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return true;
		}
		// Suppress on update / customize / installer screens.
		$blocked = array( 'update-core', 'update', 'customize', 'install-plugins', 'plugin-install' );
		if ( in_array( $screen->id, $blocked, true ) ) {
			return false;
		}
		return true;
	}
}
