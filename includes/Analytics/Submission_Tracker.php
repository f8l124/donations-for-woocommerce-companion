<?php
/**
 * Submission_Tracker — fires the donation_submitted / donation_failed
 * hooks server-side at the form-submit boundary.
 *
 * Hooks `wc_donation_alter_donate_response` — a parent-plugin filter that
 * runs after parent's AJAX handler builds its JSON response. **Critical
 * detail:** the parent's three apply_filters call sites
 * (`class-wcdonationorder.php:1592, 1634, 1821`) all pass ONE argument
 * (`$response`); they do NOT pass the request payload. v1.1.0 — v2.0.0
 * mistakenly registered with `accepted_args=2`, which threw an
 * ArgumentCountError mid-AJAX and stuck the donor's submit on an infinite
 * spinner. v2.0.1 fixes by binding 1 arg and reading `$_POST` directly
 * (we're inside parent's AJAX handler at this point, so `$_POST` is the
 * donor's submission).
 *
 * Privacy posture matches the rest of Phase 9: aggregate-only data, no
 * donor PII. Listeners that need PII (CRM tagging) pull it from their own
 * source — the cart's customer email, for example, is the FluentCRM
 * recipe's pattern.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Analytics;

defined( 'ABSPATH' ) || exit;

use DFWC\Companion\Config\Config_Resolver;
use DFWC\Companion\Config\Currency_Preset_Resolver;
use DFWC\Companion\Config\Engine_Interval_Map;

final class Submission_Tracker {

	public function __construct() {
		// Parent's filter signature: ( $response ) — single arg. Bind 1 to
		// match. We read POST directly inside the callback because we're
		// being invoked mid-AJAX (parent's `donation_to_order` action).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- parent plugin's filter
		add_filter( 'wc_donation_alter_donate_response', array( $this, 'on_donate_response' ), 10, 1 );
	}

	/**
	 * @param mixed $response Parent's AJAX response (typically array).
	 * @return mixed The unmodified response.
	 */
	public function on_donate_response( $response ) {
		// Inside parent's AJAX handler. `$_POST` is the donor's submission.
		// We never trust raw values — every field flows through Privacy_Guard
		// or absint/float casts before reaching the do_action call.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- parent's AJAX action verifies its own nonce; we're a passive observer
		$request = wp_unslash( $_POST );
		// phpcs:enable
		if ( ! is_array( $request ) || empty( $request ) ) {
			return $response;
		}

		$campaign_id = absint( $request['campaign_id'] ?? 0 );
		if ( $campaign_id < 1 ) {
			return $response;
		}

		$raw_amount = isset( $request['amount'] ) ? (string) $request['amount'] : '';
		$amount     = function_exists( 'wc_format_decimal' )
			? (float) wc_format_decimal( $raw_amount, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 )
			: (float) $raw_amount;

		$interval = $this->derive_interval( $request );
		$context  = Privacy_Guard::sanitize_context(
			isset( $request['dfwc_context'] ) ? (string) $request['dfwc_context'] : 'unknown'
		);
		$currency = Privacy_Guard::sanitize_currency(
			isset( $request['dfwc_currency'] ) ? (string) $request['dfwc_currency'] : Currency_Preset_Resolver::active_currency()
		);
		$language = Privacy_Guard::sanitize_language(
			isset( $request['dfwc_language'] ) ? (string) $request['dfwc_language'] : ''
		);

		$is_success = is_array( $response ) && 'success' === ( $response['response'] ?? '' );

		if ( $is_success ) {
			/**
			 * Fires after the parent plugin successfully processes a donor submit.
			 *
			 * @param int    $campaign_id Campaign post ID.
			 * @param string $interval    Companion interval key.
			 * @param float  $amount      Donation amount in the donor's currency.
			 * @param string $context     Render context (single|shortcode|...).
			 * @param string $currency    ISO 4217 currency code.
			 * @param string $language    WPML language code or '' if unknown.
			 * @param string $reason      Empty for success.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
			do_action(
				'dfwc_companion_donation_submitted',
				$campaign_id,
				$interval,
				$amount,
				$context,
				$currency,
				$language,
				''
			);
		} else {
			$reason = is_array( $response )
				? Privacy_Guard::sanitize_reason( (string) ( $response['message'] ?? 'unknown' ) )
				: 'unknown';

			/**
			 * Fires when the parent plugin rejects a donor submit OR our own
			 * Submit_Guard rejects (which short-circuits parent's handler;
			 * Submit_Guard's `wp_send_json_error` doesn't run this filter,
			 * so failures here are the post-Submit_Guard ones — bad cart,
			 * gateway hiccup, etc.).
			 *
			 * @param int    $campaign_id
			 * @param string $interval
			 * @param float  $amount
			 * @param string $context
			 * @param string $currency
			 * @param string $language
			 * @param string $reason   Failure message from parent's response.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
			do_action(
				'dfwc_companion_donation_failed',
				$campaign_id,
				$interval,
				$amount,
				$context,
				$currency,
				$language,
				$reason
			);
		}

		return $response;
	}

	/**
	 * Derive the companion interval key from the parent's POST payload.
	 * Mirrors `Submit_Guard::derive_interval_key` but lives here as a
	 * separate copy so the analytics path doesn't depend on Submit_Guard
	 * being wired (e.g., for sites that disable our submit guard via
	 * filter — if/when that becomes a thing).
	 *
	 * @param array<string,mixed> $request
	 */
	private function derive_interval( array $request ): string {
		$is_recurring = (string) ( $request['is_recurring'] ?? 'no' );
		if ( 'yes' !== $is_recurring ) {
			return Config_Resolver::INTERVAL_ONE_TIME;
		}

		$period     = (string) ( $request['new_period'] ?? $request['wps_sfw_subscription_interval'] ?? '' );
		$multiplier = (int) ( $request['new_interval'] ?? $request['wps_sfw_subscription_number'] ?? 1 );
		$multiplier = max( 1, $multiplier );

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

		// Anything else that arrived with is_recurring=yes is a cadence the
		// admin configured via the custom interval. Fall through to that key
		// so listeners can still distinguish from one_time. The campaign's
		// custom_period / custom_interval are the source of truth — but we
		// don't bother re-resolving them here; just label the event "custom".
		$resolved = Engine_Interval_Map::is_supported( Config_Resolver::INTERVAL_CUSTOM )
			? Config_Resolver::INTERVAL_CUSTOM
			: Config_Resolver::INTERVAL_ONE_TIME;

		return $resolved;
	}
}
