<?php
/**
 * TGB_Pending_Order — create + look up the WC order that records a crypto
 * donation.
 *
 * Two responsibilities:
 *
 *   create( array $payload ): int|WP_Error
 *     Create an `on-hold` WC order tied to a TGB donation. Idempotent —
 *     a duplicate donation_id returns the existing order id rather than
 *     creating a second order. Race-safe — the webhook handler (13.D)
 *     can call create() before this REST controller does (or vice versa)
 *     and only one order ends up in the database.
 *
 *   find_existing_by_donation_id( string $tgb_id ): ?int
 *     Looks up an existing order by `_dfwc_companion_crypto_donation_id`
 *     line-item meta. Returns the order id, or null if no match. Used by
 *     create() for idempotency and by the webhook handler for status
 *     transitions.
 *
 * Persistence: standard wc_create_order() + add_product() flow. The order
 * carries `payment_method = 'dfwc_crypto'` (registered as a hidden gateway
 * by TGB_Payment_Gateway). All crypto-specific fields ride as line-item
 * meta with the `_dfwc_companion_crypto_*` prefix.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Gateways;

defined( 'ABSPATH' ) || exit;

final class TGB_Pending_Order {

	public const META_DONATION_ID = '_dfwc_companion_crypto_donation_id';
	public const META_CURRENCY    = '_dfwc_companion_crypto_currency';
	public const META_AMOUNT      = '_dfwc_companion_crypto_amount';
	public const META_CONTEXT     = '_dfwc_companion_context';
	public const META_PROJECT_ID  = '_dfwc_companion_crypto_project_id';

	public const ORDER_STATUS_PENDING   = 'on-hold';
	public const ORDER_STATUS_COMPLETED = 'completed';

	/**
	 * Sanitize raw donor-side payload from the REST endpoint into a
	 * canonical create-order payload. Returns
	 * `array{ok:true,data:array<string,mixed>}` on success or
	 * `array{ok:false,errors:array<string,string>}` on validation failure.
	 *
	 * @param array<string,mixed> $raw
	 * @return array{ok:bool,data?:array<string,mixed>,errors?:array<string,string>}
	 */
	public static function sanitize_payload( array $raw ): array {
		$errors = array();

		$donation_id = isset( $raw['donation_id'] ) ? sanitize_text_field( (string) $raw['donation_id'] ) : '';
		// Loose format validation — TGB owns the id format. Cap length to
		// prevent DB-column overflows and discourage abuse.
		if ( '' === $donation_id || strlen( $donation_id ) > 255 || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $donation_id ) ) {
			$errors['donation_id'] = __( 'Invalid donation id.', 'dfwc-companion' );
		}

		$campaign_id = isset( $raw['campaign_id'] ) ? absint( $raw['campaign_id'] ) : 0;
		if ( $campaign_id < 1 ) {
			$errors['campaign_id'] = __( 'Campaign id is required.', 'dfwc-companion' );
		}

		$project_id = isset( $raw['project_id'] ) ? sanitize_text_field( (string) $raw['project_id'] ) : '';
		if ( strlen( $project_id ) > 255 ) {
			$errors['project_id'] = __( 'Project id too long.', 'dfwc-companion' );
		}

		$currency = isset( $raw['currency'] ) ? strtoupper( sanitize_text_field( (string) $raw['currency'] ) ) : '';
		// Crypto coin tickers — alphanumeric, up to 16 chars (BTC, ETH, USDC,
		// MATIC, etc.). Allow empty (TGB sometimes reports just USD value).
		if ( '' !== $currency && ! preg_match( '/^[A-Z0-9]{1,16}$/', $currency ) ) {
			$errors['currency'] = __( 'Invalid currency code.', 'dfwc-companion' );
		}

		$amount_crypto = isset( $raw['amount_crypto'] ) ? (float) $raw['amount_crypto'] : 0.0;
		if ( $amount_crypto < 0 ) {
			$errors['amount_crypto'] = __( 'Crypto amount must be non-negative.', 'dfwc-companion' );
		}

		$amount_usd = isset( $raw['amount_usd'] ) ? (float) $raw['amount_usd'] : 0.0;
		// Cap at $10M as sanity check; legitimate single crypto gifts in
		// the multi-million range trigger TGB's KYC + manual review and
		// don't flow through the normal widget commit path anyway.
		if ( $amount_usd <= 0 || $amount_usd > 10000000.0 ) {
			$errors['amount_usd'] = __( 'USD amount must be greater than 0 and less than $10,000,000.', 'dfwc-companion' );
		}

		if ( ! empty( $errors ) ) {
			return array(
				'ok' => false,
				'errors' => $errors,
			);
		}

		return array(
			'ok'   => true,
			'data' => array(
				'donation_id'   => $donation_id,
				'campaign_id'   => $campaign_id,
				'project_id'    => $project_id,
				'currency'      => $currency,
				'amount_crypto' => $amount_crypto,
				'amount_usd'    => $amount_usd,
			),
		);
	}

	/**
	 * Look up an existing order by TGB donation_id. Returns the order id
	 * or null. Single SQL query against the order-itemmeta table; works
	 * identically on HPOS and non-HPOS sites because line-item storage is
	 * unchanged across the HPOS migration.
	 */
	public static function find_existing_by_donation_id( string $tgb_donation_id ): ?int {
		if ( '' === $tgb_donation_id ) {
			return null;
		}

		global $wpdb;
		$order_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT items.order_id
				 FROM {$wpdb->prefix}woocommerce_order_items items
				 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta meta
				         ON meta.order_item_id = items.order_item_id
				 WHERE meta.meta_key = %s AND meta.meta_value = %s
				 LIMIT 1",
				self::META_DONATION_ID,
				$tgb_donation_id
			)
		);

		return $order_id ? (int) $order_id : null;
	}

	/**
	 * Create an on-hold WC order recording a crypto donation. Returns the
	 * order id. Idempotent — if an order already exists for the same TGB
	 * donation_id, returns its id rather than creating a duplicate.
	 *
	 * Order shape:
	 *   - status         = on-hold
	 *   - payment_method = dfwc_crypto
	 *   - currency       = USD (TGB's reporting unit; documented constraint)
	 *   - line item      = the campaign's linked WC product, qty 1, price = amount_usd
	 *   - line meta      = TGB donation id, crypto coin, crypto amount, context
	 *
	 * Status transition to `completed` happens in the webhook handler
	 * (TGB_Webhook_Handler in 13.D).
	 *
	 * @param array<string,mixed> $payload Sanitized payload from sanitize_payload().
	 * @return int|\WP_Error Order id, or WP_Error with diagnostic.
	 */
	public static function create( array $payload ) {
		$existing = self::find_existing_by_donation_id( (string) $payload['donation_id'] );
		if ( null !== $existing ) {
			return $existing;
		}

		$product_id = self::resolve_linked_product_id( (int) $payload['campaign_id'] );
		if ( 0 === $product_id ) {
			return new \WP_Error(
				'no_linked_product',
				__( 'Campaign has no linked WooCommerce product.', 'dfwc-companion' )
			);
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return new \WP_Error(
				'product_not_found',
				__( 'Linked product could not be loaded.', 'dfwc-companion' )
			);
		}

		$order = function_exists( 'wc_create_order' )
			? wc_create_order( array( 'status' => self::ORDER_STATUS_PENDING ) )
			: null;
		if ( ! $order || $order instanceof \WP_Error ) {
			return new \WP_Error(
				'order_create_failed',
				__( 'Could not create the WooCommerce order.', 'dfwc-companion' )
			);
		}

		$amount_usd = (float) $payload['amount_usd'];

		// Add the campaign's linked product as a line item with the USD
		// value as the price. add_product returns the line-item id.
		$line_item_id = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => $amount_usd,
				'total'    => $amount_usd,
			)
		);

		if ( ! $line_item_id ) {
			$order->delete( true );
			return new \WP_Error(
				'line_item_failed',
				__( 'Could not add the line item to the order.', 'dfwc-companion' )
			);
		}

		// Set order metadata. Currency forced to USD because TGB reports in
		// USD regardless of the WC store's base currency. Cross-currency
		// reconciliation is documented as a v2.3.x concern.
		$order->set_currency( 'USD' );
		$order->set_payment_method( TGB_Payment_Gateway::METHOD_ID );
		$order->set_payment_method_title( __( 'Crypto via The Giving Block', 'dfwc-companion' ) );

		// Attach crypto-specific meta to the line item so reports +
		// receipts can surface the original coin/amount alongside the
		// USD-denominated order total.
		wc_add_order_item_meta( (int) $line_item_id, self::META_DONATION_ID, (string) $payload['donation_id'] );
		wc_add_order_item_meta( (int) $line_item_id, self::META_CONTEXT, 'crypto' );
		if ( '' !== (string) $payload['currency'] ) {
			wc_add_order_item_meta( (int) $line_item_id, self::META_CURRENCY, (string) $payload['currency'] );
		}
		if ( $payload['amount_crypto'] > 0 ) {
			wc_add_order_item_meta( (int) $line_item_id, self::META_AMOUNT, (string) $payload['amount_crypto'] );
		}
		if ( '' !== (string) $payload['project_id'] ) {
			wc_add_order_item_meta( (int) $line_item_id, self::META_PROJECT_ID, (string) $payload['project_id'] );
		}

		$order->calculate_totals( false );
		$order->save();

		return (int) $order->get_id();
	}

	/**
	 * Read the campaign post's linked WC product id. Parent plugin stores
	 * the relationship as `wc_donation_product` post meta on the campaign;
	 * we read directly to avoid coupling on parent's API.
	 */
	private static function resolve_linked_product_id( int $campaign_id ): int {
		$value = get_post_meta( $campaign_id, 'wc_donation_product', true );
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
