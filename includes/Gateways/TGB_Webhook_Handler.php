<?php
/**
 * TGB_Webhook_Handler — verify, parse, and process incoming TGB webhooks.
 *
 * Three responsibilities, split for testability:
 *
 *   verify_signature( $raw_body, $signature, $secret ) : bool
 *     Constant-time HMAC-SHA256 verification. Pure function — fully
 *     unit-testable.
 *
 *   parse_and_validate( $raw_body ) : array{ok,data?,error?}
 *     JSON decode + field validation. Pure function — fully
 *     unit-testable. Allow-listed event types only; minimum required
 *     fields are donation_id + amount_usd.
 *
 *   process( $payload ) : array{ok,action?,order_id?,error?}
 *     The order side-effect. Idempotent + race-safe. Three branches:
 *
 *     - Order does not exist (race: webhook arrived before pending POST)
 *       → create on-hold order via TGB_Pending_Order::create, then
 *         immediately flip to completed + fire Phase 9 hook.
 *
 *     - Order exists at on-hold (normal path)
 *       → flip to completed + fire Phase 9 hook.
 *
 *     - Order exists at completed (replay/duplicate webhook)
 *       → no-op. Phase 9 hook does NOT fire a second time.
 *
 * The Phase 9 hook signature matches Submission_Tracker exactly:
 *
 *   do_action(
 *     'dfwc_companion_donation_submitted',
 *     $campaign_id, $interval, $amount, $context, $currency, $language, $reason
 *   );
 *
 * Crypto donations always fire with $interval='one_time', $context='crypto',
 * $currency='USD' (TGB reports in USD; cross-currency is documented v2.3.x
 * concern), $language='' (webhook doesn't carry locale), $reason=''.
 *
 * The signature header format is configurable via the
 * `dfwc_companion_tgb_webhook_signature_header` filter (default: 'X-TGB-Signature').
 * Stripe-style headers (`t=<ts>,v1=<hex>`) can be parsed by a custom
 * verifier wired through the `dfwc_companion_tgb_webhook_verify` filter.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Gateways;

defined( 'ABSPATH' ) || exit;

final class TGB_Webhook_Handler {

	public const EVENT_DONATION_CONFIRMED = 'donation.confirmed';

	/**
	 * Allow-listed event types. We only act on `donation.confirmed`; other
	 * events (donation.refunded, donation.failed, etc.) are accepted but
	 * not processed at v2.3.0 — they 200-OK so TGB doesn't retry.
	 *
	 * @return array<int,string>
	 */
	public static function known_events(): array {
		return array(
			self::EVENT_DONATION_CONFIRMED,
			// Future events accepted here as v2.3.x grows.
		);
	}

	/**
	 * Constant-time HMAC-SHA256 signature verification.
	 *
	 * @param string $raw_body  Raw request body bytes.
	 * @param string $signature Hex signature from the request header.
	 * @param string $secret    Shared webhook secret.
	 */
	public static function verify_signature( string $raw_body, string $signature, string $secret ): bool {
		if ( '' === $signature || '' === $secret ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $raw_body, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Parse + validate a JSON webhook body. Returns
	 * `array{ok:true,data:array}` on success or
	 * `array{ok:false,error:string}` on failure.
	 *
	 * Required fields: event, donation_id, amount_usd.
	 * Optional fields: project_id, currency, amount_crypto, organization_id, tx_hash, confirmed_at.
	 *
	 * @return array{ok:bool,data?:array<string,mixed>,error?:string}
	 */
	public static function parse_and_validate( string $raw_body ): array {
		if ( '' === $raw_body ) {
			return array(
				'ok' => false,
				'error' => 'empty body',
			);
		}

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok' => false,
				'error' => 'invalid json',
			);
		}

		$event = isset( $decoded['event'] ) ? (string) $decoded['event'] : '';
		if ( ! in_array( $event, self::known_events(), true ) ) {
			return array(
				'ok' => false,
				'error' => 'unknown event: ' . $event,
			);
		}

		$donation_id = isset( $decoded['donation_id'] ) ? (string) $decoded['donation_id'] : '';
		// Same loose format as TGB_Pending_Order: alphanumeric+_- only,
		// up to 255 chars. Defends the SQL meta-value column from overflow.
		if ( '' === $donation_id || strlen( $donation_id ) > 255 || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $donation_id ) ) {
			return array(
				'ok' => false,
				'error' => 'invalid donation_id',
			);
		}

		$amount_usd = isset( $decoded['amount_usd'] ) ? (float) $decoded['amount_usd'] : 0.0;
		if ( $amount_usd <= 0 || $amount_usd > 10000000.0 ) {
			return array(
				'ok' => false,
				'error' => 'invalid amount_usd',
			);
		}

		// Optional fields — passed through if present, sanitized lightly.
		$currency = isset( $decoded['currency'] ) ? strtoupper( (string) $decoded['currency'] ) : '';
		if ( '' !== $currency && ! preg_match( '/^[A-Z0-9]{1,16}$/', $currency ) ) {
			return array(
				'ok' => false,
				'error' => 'invalid currency',
			);
		}

		$amount_crypto = isset( $decoded['amount_crypto'] ) ? (float) $decoded['amount_crypto'] : 0.0;
		if ( $amount_crypto < 0 ) {
			return array(
				'ok' => false,
				'error' => 'invalid amount_crypto',
			);
		}

		$project_id     = isset( $decoded['project_id'] ) ? (string) $decoded['project_id'] : '';
		$tx_hash        = isset( $decoded['tx_hash'] ) ? (string) $decoded['tx_hash'] : '';
		$confirmed_at   = isset( $decoded['confirmed_at'] ) ? (string) $decoded['confirmed_at'] : '';
		$organization_id = isset( $decoded['organization_id'] ) ? (string) $decoded['organization_id'] : '';

		return array(
			'ok'   => true,
			'data' => array(
				'event'           => $event,
				'donation_id'     => $donation_id,
				'amount_usd'      => $amount_usd,
				'amount_crypto'   => $amount_crypto,
				'currency'        => $currency,
				'project_id'      => $project_id,
				'tx_hash'         => $tx_hash,
				'confirmed_at'    => $confirmed_at,
				'organization_id' => $organization_id,
			),
		);
	}

	/**
	 * Process a verified + parsed webhook payload. Idempotent + race-safe
	 * with the donor-side Crypto_Pending_Order_REST_Controller.
	 *
	 * For a donation_id we've never seen (race case): create the on-hold
	 * order via TGB_Pending_Order::create, immediately flip to completed,
	 * fire Phase 9 hook.
	 *
	 * For a donation_id with an existing on-hold order (normal case):
	 * flip to completed, fire Phase 9 hook.
	 *
	 * For a donation_id with an existing completed order (replay case):
	 * 200-OK no-op. Phase 9 hook does NOT fire a second time.
	 *
	 * @param array<string,mixed> $payload Validated payload from parse_and_validate().
	 * @return array{ok:bool,action?:string,order_id?:int,error?:string}
	 */
	public static function process( array $payload ): array {
		$donation_id = (string) $payload['donation_id'];

		$existing = TGB_Pending_Order::find_existing_by_donation_id( $donation_id );

		if ( null === $existing ) {
			// Race: webhook arrived before pending POST. Create the order
			// on the spot using only the webhook-provided fields. campaign_id
			// is unknown at this point — TGB doesn't carry our companion
			// campaign id (only their project_id). Resolve via project_id
			// reverse-lookup.
			$campaign_id = self::resolve_campaign_id_from_project( (string) $payload['project_id'] );
			if ( 0 === $campaign_id ) {
				// Without a campaign, we can't create the order. Tell TGB
				// "received" so they don't retry, but log + diagnostic.
				return array(
					'ok'    => false,
					'error' => 'no campaign matches project_id ' . (string) $payload['project_id'],
				);
			}

			$create_result = TGB_Pending_Order::create(
				array(
					'donation_id'   => $donation_id,
					'campaign_id'   => $campaign_id,
					'project_id'    => (string) $payload['project_id'],
					'currency'      => (string) $payload['currency'],
					'amount_crypto' => (float) $payload['amount_crypto'],
					'amount_usd'    => (float) $payload['amount_usd'],
				)
			);

			if ( $create_result instanceof \WP_Error ) {
				return array(
					'ok'    => false,
					'error' => $create_result->get_error_message(),
				);
			}

			$existing = (int) $create_result;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $existing ) : null;
		if ( ! $order ) {
			return array(
				'ok' => false,
				'error' => 'order load failed: ' . $existing,
			);
		}

		if ( TGB_Pending_Order::ORDER_STATUS_COMPLETED === (string) $order->get_status() ) {
			// Replay — order already completed. No-op; Phase 9 hook does
			// not fire a second time.
			return array(
				'ok'       => true,
				'action'   => 'no_op_replay',
				'order_id' => $existing,
			);
		}

		// Transition to completed. WC's update_status fires its own hooks
		// (woocommerce_order_status_changed, etc.); listeners on those
		// hooks see the transition as a normal WC lifecycle event.
		$order->update_status(
			TGB_Pending_Order::ORDER_STATUS_COMPLETED,
			__( 'Crypto donation confirmed by The Giving Block webhook.', 'dfwc-companion' )
		);

		self::fire_phase9_hook( $order, $payload );

		return array(
			'ok'       => true,
			'action'   => 'completed',
			'order_id' => $existing,
		);
	}

	/**
	 * Fire the Phase 9 dfwc_companion_donation_submitted hook. Same signature
	 * as Submission_Tracker for cash donations — listeners (FluentCRM, GA4,
	 * etc.) see crypto donations identically to cash with `$context = 'crypto'`.
	 *
	 * @param mixed              $order   WC_Order instance (loosely typed for stub-friendliness).
	 * @param array<string,mixed> $payload Validated webhook payload.
	 */
	private static function fire_phase9_hook( $order, array $payload ): void {
		// Resolve campaign_id from the order's first line item meta. We
		// stored it indirectly — line item links to a WC product, that
		// product is the campaign's `wc_donation_product`. Reverse via
		// the same TGB_Pending_Order helpers.
		$campaign_id = self::resolve_campaign_id_from_order( $order );

		/**
		 * Fires when a crypto donation is confirmed via TGB webhook.
		 * Same signature as Submission_Tracker's cash + stock fires.
		 *
		 * @param int    $campaign_id
		 * @param string $interval    Always 'one_time' at v2.3.0 (recurring crypto deferred to v2.3.1+).
		 * @param float  $amount      USD value as reported by TGB.
		 * @param string $context     Always 'crypto'.
		 * @param string $currency    Always 'USD' (TGB's reporting unit).
		 * @param string $language    Always '' (webhook does not carry locale).
		 * @param string $reason      Always '' (success path; failure events do not call this method).
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- our prefixed hook
		do_action(
			'dfwc_companion_donation_submitted',
			$campaign_id,
			'one_time',
			(float) $payload['amount_usd'],
			'crypto',
			'USD',
			'',
			''
		);
	}

	/**
	 * Reverse-lookup: TGB project_id → companion campaign_id. Scans every
	 * campaign with crypto enabled looking for a per-campaign override
	 * matching the project_id; falls back to the global default mapping.
	 *
	 * O(N) over campaigns with crypto config — acceptable up to a few
	 * hundred campaigns; if we hit performance issues, add an index meta.
	 */
	private static function resolve_campaign_id_from_project( string $project_id ): int {
		if ( '' === $project_id ) {
			return 0;
		}

		// Query campaigns whose `_dfwc_companion_overrides` JSON contains
		// the project_id. Using meta_query LIKE here because the value is
		// nested inside a serialized array. Safe (escaped) but coarse.
		$query = new \WP_Query(
			array(
				'post_type'      => 'wc-donation',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'meta_query'     => array(
					array(
						'key'     => '_dfwc_companion_overrides',
						'value'   => $project_id,
						'compare' => 'LIKE',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$overrides = get_post_meta( (int) $post_id, '_dfwc_companion_overrides', true );
			if ( is_array( $overrides ) && isset( $overrides['crypto']['tgb_project_id'] )
				&& $project_id === (string) $overrides['crypto']['tgb_project_id']
			) {
				return (int) $post_id;
			}
		}

		// Global default fallback — when the project_id matches the
		// org-wide default, use the first crypto-enabled campaign as the
		// attribution target. This is a heuristic for "donor came in
		// without a specific campaign" and may be revisited in v2.3.x.
		$global = \DFWC\Companion\Config\Config_Resolver::get_global_settings();
		if ( (string) ( $global['tgb_default_project_id'] ?? '' ) === $project_id ) {
			$fallback = new \WP_Query(
				array(
					'post_type'              => 'wc-donation',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( ! empty( $fallback->posts ) ) {
				return (int) $fallback->posts[0];
			}
		}

		return 0;
	}

	/**
	 * Resolve the campaign id from an order's first line item. Uses the
	 * line item's product_id → reverse-find the campaign whose
	 * `wc_donation_product` post-meta matches.
	 *
	 * @param mixed $order WC_Order instance.
	 */
	private static function resolve_campaign_id_from_order( $order ): int {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return 0;
		}
		$items = $order->get_items();
		if ( empty( $items ) ) {
			return 0;
		}
		$first      = reset( $items );
		$product_id = is_object( $first ) && method_exists( $first, 'get_product_id' )
			? (int) $first->get_product_id()
			: 0;
		if ( 0 === $product_id ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'wc-donation',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'meta_query'             => array(
					array(
						'key'     => 'wc_donation_product',
						'value'   => $product_id,
						'compare' => '=',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}
}
