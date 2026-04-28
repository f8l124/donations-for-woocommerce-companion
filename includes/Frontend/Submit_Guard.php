<?php
/**
 * Server-side enforcement of amount min/max + interval-enabled check.
 *
 * Hooks into the parent plugin's `wc_donation_before_donate` action — fires
 * AFTER parent's nonce check (class-wcdonationorder.php:1542) but BEFORE its
 * own amount validation (line 1638). We re-validate against companion config
 * because client-side checks can be bypassed via DevTools / curl.
 *
 * Reject by `wp_send_json_error` which calls `wp_die`, halting parent flow.
 *
 * Master plan deviation D9.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Frontend;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;

final class Submit_Guard {

	private const PARENT_AJAX_ACTION = 'donation_to_order';

	public function __construct() {
		// Priority 5 = before any other listener on this hook.
		add_action( 'wc_donation_before_donate', array( $this, 'enforce' ), 5 );
	}

	public function enforce(): void {
		// Defensive: hook only fires inside parent's AJAX handler, but make
		// sure we never wp_die() outside an AJAX context. Use the constant
		// directly to avoid any wp_doing_ajax() availability surprises.
		if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( self::PARENT_AJAX_ACTION !== $action ) {
			return;
		}

		// Phase 8 defense-in-depth: refuse submits carrying the preview flag.
		// The flag is set by Preview_Renderer in admin-only iframe HTML; it
		// shouldn't ever reach a frontend AJAX submit. If it does — someone
		// scraped preview HTML and tried to submit it — refuse cleanly rather
		// than land a bogus donation in the cart.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['dfwc_preview'] ) ) {
			$this->reject(
				__( 'Preview submissions are not accepted. Reload the donor form and try again.', 'dfwc-companion' )
			);
		}

		// Nonce already verified by parent at line 1542 before this hook fires.
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( wp_unslash( $_POST['campaign_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $campaign_id < 1 ) {
			return; // Parent will reject.
		}

		$amount_raw = isset( $_POST['amount'] ) ? wp_unslash( (string) $_POST['amount'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$amount     = (float) wc_format_decimal( $amount_raw, wc_get_price_decimals() );

		$is_recurring = isset( $_POST['is_recurring'] ) ? sanitize_text_field( wp_unslash( $_POST['is_recurring'] ) ) : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$period       = isset( $_POST['new_period'] ) ? sanitize_text_field( wp_unslash( $_POST['new_period'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$interval_key = $this->derive_interval_key( $is_recurring, $period );
		if ( null === $interval_key ) {
			// Unknown pattern (donor came through a non-companion form?). Defer to parent.
			return;
		}

		$config = Config_Resolver::resolve( $campaign_id );
		$block  = $config[ $interval_key ] ?? null;

		if ( ! is_array( $block ) || empty( $block['enabled'] ) ) {
			$this->reject(
				__( 'This donation interval is not available for this campaign.', 'dfwc-companion' )
			);
		}

		// Phase 6 — overlay per-currency min/max so donors in non-base
		// currencies aren't rejected against base-currency thresholds. The
		// resolver no-ops when active currency matches base or no override
		// exists for that currency.
		$block = Currency_Preset_Resolver::resolve( $block );

		$min = (float) ( $block['min'] ?? 0 );
		$max = (float) ( $block['max'] ?? PHP_INT_MAX );

		if ( $amount < $min || $amount > $max ) {
			$this->reject(
				sprintf(
					/* translators: 1: minimum amount, 2: maximum amount */
					__( 'Donation amount must be between %1$s and %2$s.', 'dfwc-companion' ),
					wp_strip_all_tags( wc_price( $min ) ),
					wp_strip_all_tags( wc_price( $max ) )
				)
			);
		}
	}

	/**
	 * Map (is_recurring, period) → companion interval key. Returns null when
	 * the combination doesn't fit our model so we defer to parent's logic.
	 */
	private function derive_interval_key( string $is_recurring, string $period ): ?string {
		if ( 'no' === $is_recurring ) {
			return Config_Resolver::INTERVAL_ONE_TIME;
		}
		if ( 'yes' === $is_recurring ) {
			if ( 'month' === $period ) {
				return Config_Resolver::INTERVAL_MONTHLY;
			}
			if ( 'year' === $period ) {
				return Config_Resolver::INTERVAL_ANNUAL;
			}
		}
		return null;
	}

	/**
	 * Send a JSON error response and halt. The shape mirrors the parent's
	 * own error responses so the donor-side JS handles them uniformly.
	 */
	private function reject( string $message ): void {
		wp_send_json_error(
			array(
				'response' => 'error',
				'message'  => $message,
			),
			422
		);
		// wp_send_json_error calls wp_die — execution stops here.
	}
}
