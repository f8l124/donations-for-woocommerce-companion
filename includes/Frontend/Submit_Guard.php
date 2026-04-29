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
use DFWC\Companion\Config\Engine_Interval_Map;
use DFWC\Companion\Config\Goal_State;

final class Submit_Guard {

	private const PARENT_AJAX_ACTION = 'donation_to_order';

	public function __construct() {
		// Priority 5 = before any other listener on this hook.
		add_action( 'wc_donation_before_donate', array( $this, 'enforce' ), 5 );
	}

	public function enforce(): void {
		// Defensive: hook only fires inside parent's AJAX handler, but make
		// sure we never wp_die() outside an AJAX context.
		if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
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
		$multiplier   = isset( $_POST['new_interval'] ) ? absint( wp_unslash( $_POST['new_interval'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$config       = Config_Resolver::resolve( $campaign_id );
		$interval_key = $this->derive_interval_key( $is_recurring, $period, $multiplier, $config );
		if ( null === $interval_key ) {
			// Unknown pattern (donor came through a non-companion form?). Defer to parent.
			return;
		}

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

		// Phase 13 — when admin opts in, clamp the one-time max to the
		// campaign's remaining goal. Skipped for recurring intervals — the
		// donor's first charge fits within remaining, but their renewals
		// would not (and silently capping renewals is worse than letting
		// the goal be exceeded a little).
		$global = Config_Resolver::get_global_settings();
		if ( ! empty( $global['enable_goal_based_max'] ) && Config_Resolver::INTERVAL_ONE_TIME === $interval_key ) {
			$goal = Goal_State::for_campaign( $campaign_id );
			if ( $goal->is_amount_goal() ) {
				if ( $goal->is_fully_funded() && ! empty( $global['enable_fully_funded_redirect'] ) ) {
					$general_fund_id = (int) ( $global['general_fund_campaign_id'] ?? 0 );
					$general_fund_url = $general_fund_id > 0 ? (string) get_permalink( $general_fund_id ) : '';
					$this->reject(
						'' !== $general_fund_url
							? sprintf(
								/* translators: %s: general-fund campaign URL */
								__( 'This campaign reached its goal. Please continue your support via our general fund: %s', 'dfwc-companion' ),
								esc_url_raw( $general_fund_url )
							)
							: __( 'This campaign reached its goal. Thank you — please consider donating to another active campaign.', 'dfwc-companion' )
					);
				}
				if ( ! $goal->is_fully_funded() ) {
					// Cap max at the remaining-goal value. Defensive `min`:
					// admin's configured max still wins if it's lower.
					$max = min( $max, $goal->remaining_amount() );
				}
			}
		}

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
	 * Map (is_recurring, period, multiplier) → companion interval key. Returns
	 * null when the combination doesn't fit our model so we defer to parent's
	 * logic.
	 *
	 * Phase 7 — also recognizes weekly / quarterly / semi-annual cadences,
	 * and matches `custom` when the campaign's custom block declares the
	 * exact (period, multiplier) the donor posted (so we honor the admin's
	 * configured cadence rather than guessing).
	 *
	 * @param array<string,mixed> $config Resolved campaign config.
	 */
	private function derive_interval_key( string $is_recurring, string $period, int $multiplier, array $config ): ?string {
		if ( 'no' === $is_recurring ) {
			return Config_Resolver::INTERVAL_ONE_TIME;
		}
		if ( 'yes' !== $is_recurring ) {
			return null;
		}

		$multiplier = max( 1, $multiplier );

		// Standard + advanced fixed-cadence intervals — match by (period, multiplier).
		$fixed = array(
			Config_Resolver::INTERVAL_MONTHLY    => array( 'month', 1 ),
			Config_Resolver::INTERVAL_ANNUAL     => array( 'year', 1 ),
			Config_Resolver::INTERVAL_WEEKLY     => array( 'week', 1 ),
			Config_Resolver::INTERVAL_QUARTERLY  => array( 'month', 3 ),
			Config_Resolver::INTERVAL_SEMIANNUAL => array( 'month', 6 ),
		);
		foreach ( $fixed as $key => $cadence ) {
			if ( $cadence[0] === $period && $cadence[1] === $multiplier ) {
				return $key;
			}
		}

		// Custom interval — match the admin's configured cadence.
		$custom_block = $config[ Config_Resolver::INTERVAL_CUSTOM ] ?? null;
		if ( is_array( $custom_block ) && ! empty( $custom_block['enabled'] ) ) {
			$custom_cadence = Engine_Interval_Map::for_interval( Config_Resolver::INTERVAL_CUSTOM, $custom_block );
			if ( null !== $custom_cadence
				&& $custom_cadence['period'] === $period
				&& (int) $custom_cadence['interval'] === $multiplier
			) {
				return Config_Resolver::INTERVAL_CUSTOM;
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
