<?php
/**
 * Cause_Raised_Aggregator — per-cause raised totals from existing WC order data.
 *
 * Read-side only; never mutates state. The submit_guard / closure
 * renderer / admin diagnostics call `for_cause()` and get a USD-
 * denominated total. Three-layer lookup:
 *
 *   1. Per-request memo (closure-renderer hits the same cause many
 *      times per render).
 *   2. 5-minute transient (covers cross-request consistency cheaply).
 *   3. Single SQL aggregate against wp_woocommerce_order_items +
 *      wp_woocommerce_order_itemmeta + the orders table (HPOS-aware
 *      branch).
 *
 * Aggregation logic:
 *   - Find the campaign's linked WC product (`wc_donation_product`).
 *   - Sum `_line_subtotal` across all paid orders' line items where
 *     product matches AND (line item has `_dfwc_companion_cause_id` =
 *     the cause id  OR  line item has `Cause` meta = the cause name —
 *     legacy fallback for orders placed before v2.4.0).
 *   - Order status filter: wc_get_is_paid_statuses() — matches
 *     parent's own raised-tally semantics.
 *
 * Invalidation:
 *   Listens on `woocommerce_order_status_completed` (most common
 *   completion path). Iterates the order's line items, finds any
 *   carrying our cause-id meta (or the legacy Cause meta with a
 *   resolvable name), and invalidates those caches. The 5-minute TTL
 *   is the long-stop for invalidation gaps (other status transitions,
 *   manual order edits, refunds).
 *
 * HPOS: line-item storage is unchanged across HPOS migration. Only
 * the orders-table JOIN differs (wp_posts vs wp_wc_orders); branched
 * via OrderUtil::custom_orders_table_usage_is_enabled when present.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Cause_Raised_Aggregator {

	private const TRANSIENT_PREFIX = 'dfwc_cause_raised_';
	private const TTL_SECONDS      = 5 * MINUTE_IN_SECONDS;
	private const META_LINE_ITEM_ID = '_dfwc_companion_cause_id';
	private const META_LEGACY_CAUSE = 'Cause';

	/**
	 * Per-request memo. Reset between tests via reset_memo().
	 *
	 * @var array<string,float>
	 */
	private static array $memo = array();

	public function __construct() {
		// Most cash + crypto donation completions land here.
		add_action( 'woocommerce_order_status_completed', array( $this, 'invalidate_for_order' ), 20, 1 );
		// Some flows transition processing → completed (Stripe captures).
		add_action( 'woocommerce_order_status_processing', array( $this, 'invalidate_for_order' ), 20, 1 );
		// Refunds shift money away — invalidate so re-aggregation excludes them.
		add_action( 'woocommerce_order_status_refunded', array( $this, 'invalidate_for_order' ), 20, 1 );
	}

	/**
	 * USD-denominated raised total for a specific cause on a specific
	 * campaign. Returns 0.0 when no data, when the campaign has no
	 * linked product, or when the cause id doesn't resolve to a name
	 * (newly minted cause with no donations yet).
	 */
	public static function for_cause( int $campaign_id, string $cause_id ): float {
		if ( $campaign_id < 1 || '' === $cause_id ) {
			return 0.0;
		}

		$key = self::cache_key( $campaign_id, $cause_id );

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$cached = get_transient( $key );
		if ( false !== $cached && is_numeric( $cached ) ) {
			self::$memo[ $key ] = (float) $cached;
			return self::$memo[ $key ];
		}

		$raised = self::aggregate( $campaign_id, $cause_id );
		set_transient( $key, $raised, self::TTL_SECONDS );
		self::$memo[ $key ] = $raised;
		return $raised;
	}

	/**
	 * Invalidate the cache for a single (campaign, cause) pair. Public
	 * so other modules (admin reconciliation flows, manual edits) can
	 * reach in cleanly.
	 */
	public static function invalidate( int $campaign_id, string $cause_id ): void {
		$key = self::cache_key( $campaign_id, $cause_id );
		delete_transient( $key );
		unset( self::$memo[ $key ] );
	}

	/**
	 * Order-completion hook callback. Walks the order's line items,
	 * finds those carrying our cause meta (or the legacy `Cause` meta
	 * with a resolvable name), invalidates those caches.
	 */
	public function invalidate_for_order( int $order_id ): void {
		if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}
			$product_id  = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$campaign_id = self::resolve_campaign_id_from_product( $product_id );
			if ( 0 === $campaign_id ) {
				continue;
			}

			// Companion cause-id wins.
			$cause_id = (string) $item->get_meta( self::META_LINE_ITEM_ID, true );
			if ( '' !== $cause_id ) {
				self::invalidate( $campaign_id, $cause_id );
				continue;
			}

			// Legacy fallback — line item has parent's `Cause` meta
			// with the cause name. Resolve to companion cause-id via
			// Cause_Identity, invalidate.
			$cause_name = (string) $item->get_meta( self::META_LEGACY_CAUSE, true );
			if ( '' === $cause_name ) {
				continue;
			}
			$resolved = Cause_Identity::id_for_name( $campaign_id, $cause_name );
			if ( null !== $resolved ) {
				self::invalidate( $campaign_id, $resolved );
			}
		}
	}

	/**
	 * Reset per-request memo. Tests rely on this; production code does
	 * not need to call.
	 */
	public static function reset_memo(): void {
		self::$memo = array();
	}

	/**
	 * Cache key. md5 of cause_id keeps the key under wp_options'
	 * 191-char limit (cause_id is a UUID — short — but defensive
	 * anyway in case future ids grow).
	 */
	public static function cache_key( int $campaign_id, string $cause_id ): string {
		return self::TRANSIENT_PREFIX . $campaign_id . '_' . md5( $cause_id );
	}

	/**
	 * Run the SQL aggregate. Returns 0.0 on any failure (campaign has
	 * no linked product, cause id doesn't resolve to a name, db error).
	 */
	private static function aggregate( int $campaign_id, string $cause_id ): float {
		$product_id = self::resolve_linked_product_id( $campaign_id );
		if ( 0 === $product_id ) {
			return 0.0;
		}

		$cause_name = (string) Cause_Identity::name_for_id( $campaign_id, $cause_id );

		$paid_statuses = self::paid_statuses();
		if ( empty( $paid_statuses ) ) {
			return 0.0;
		}

		global $wpdb;

		// Build the IN-clause placeholders for paid statuses.
		$status_placeholders = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );

		if ( self::hpos_enabled() ) {
			// HPOS: orders live in wp_wc_orders. Status is a column.
			// Status values are bare strings ('completed') here, no 'wc-' prefix.
			$bare_statuses = array_map(
				static fn( $s ) => 0 === strpos( (string) $s, 'wc-' ) ? substr( (string) $s, 3 ) : (string) $s,
				$paid_statuses
			);

			$sql_args = array_merge(
				array( self::META_LINE_ITEM_ID, $cause_id, self::META_LEGACY_CAUSE, $cause_name, $product_id ),
				$bare_statuses
			);

			// $status_placeholders is built locally via array_fill('%s'),
			// not from any external input. The IN-clause pattern is the
			// canonical WP $wpdb->prepare workaround for dynamic-length
			// IN lists; values themselves still flow through prepare's
			// %s binders below.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(CAST(subtotal_meta.meta_value AS DECIMAL(20,4)))
					 FROM {$wpdb->prefix}woocommerce_order_items items
					 INNER JOIN {$wpdb->prefix}wc_orders orders
					         ON orders.id = items.order_id
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta product_meta
					         ON product_meta.order_item_id = items.order_item_id
					        AND product_meta.meta_key = '_product_id'
					 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta subtotal_meta
					         ON subtotal_meta.order_item_id = items.order_item_id
					        AND subtotal_meta.meta_key = '_line_subtotal'
					 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta cause_id_meta
					         ON cause_id_meta.order_item_id = items.order_item_id
					        AND cause_id_meta.meta_key = %s
					 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta cause_name_meta
					         ON cause_name_meta.order_item_id = items.order_item_id
					        AND cause_name_meta.meta_key = %s
					 WHERE ( cause_id_meta.meta_value = %s OR cause_name_meta.meta_value = %s )
					   AND product_meta.meta_value = %d
					   AND orders.status IN ( {$status_placeholders} )",
					...$sql_args
				)
			);
			// phpcs:enable
		} else {
			// Non-HPOS: orders live in wp_posts as shop_order. Status
			// values are prefixed ('wc-completed') in post_status.
			$prefixed_statuses = array_map(
				static fn( $s ) => 0 === strpos( (string) $s, 'wc-' ) ? (string) $s : 'wc-' . (string) $s,
				$paid_statuses
			);

			$sql_args = array_merge(
				array( self::META_LINE_ITEM_ID, $cause_id, self::META_LEGACY_CAUSE, $cause_name, $product_id ),
				$prefixed_statuses
			);

			// $status_placeholders is built locally via array_fill('%s'),
			// not from any external input. The IN-clause pattern is the
			// canonical WP $wpdb->prepare workaround for dynamic-length
			// IN lists; values themselves still flow through prepare's
			// %s binders below.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT SUM(CAST(subtotal_meta.meta_value AS DECIMAL(20,4)))
					 FROM {$wpdb->prefix}woocommerce_order_items items
					 INNER JOIN {$wpdb->posts} posts
					         ON posts.ID = items.order_id
					        AND posts.post_type = 'shop_order'
					 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta product_meta
					         ON product_meta.order_item_id = items.order_item_id
					        AND product_meta.meta_key = '_product_id'
					 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta subtotal_meta
					         ON subtotal_meta.order_item_id = items.order_item_id
					        AND subtotal_meta.meta_key = '_line_subtotal'
					 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta cause_id_meta
					         ON cause_id_meta.order_item_id = items.order_item_id
					        AND cause_id_meta.meta_key = %s
					 LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta cause_name_meta
					         ON cause_name_meta.order_item_id = items.order_item_id
					        AND cause_name_meta.meta_key = %s
					 WHERE ( cause_id_meta.meta_value = %s OR cause_name_meta.meta_value = %s )
					   AND product_meta.meta_value = %d
					   AND posts.post_status IN ( {$status_placeholders} )",
					...$sql_args
				)
			);
			// phpcs:enable
		}

		return null === $total ? 0.0 : (float) $total;
	}

	/**
	 * Detect HPOS at runtime. WC 7.1+ ships OrderUtil; older sites
	 * default to non-HPOS. Function exists check keeps PHPStan happy
	 * when WC stubs don't include it.
	 */
	private static function hpos_enabled(): bool {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ) {
			return false;
		}
		return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Paid statuses per WC's own conventions. Falls back to a sensible
	 * pair when wc_get_is_paid_statuses isn't available (test bootstrap).
	 *
	 * @return array<int,string>
	 */
	private static function paid_statuses(): array {
		if ( function_exists( 'wc_get_is_paid_statuses' ) ) {
			$statuses = wc_get_is_paid_statuses();
			if ( is_array( $statuses ) ) {
				return $statuses;
			}
		}
		return array( 'processing', 'completed' );
	}

	/**
	 * Reverse-lookup: WC product → companion campaign id. Returns 0
	 * when no campaign owns the product. Used by the order-completion
	 * invalidator to determine which cause caches need flushing.
	 */
	private static function resolve_campaign_id_from_product( int $product_id ): int {
		if ( $product_id < 1 ) {
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

	/**
	 * Read parent's `wc_donation_product` post meta. Same path
	 * Goal_State uses to find the campaign's WC product target.
	 */
	private static function resolve_linked_product_id( int $campaign_id ): int {
		$value = get_post_meta( $campaign_id, 'wc_donation_product', true );
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
