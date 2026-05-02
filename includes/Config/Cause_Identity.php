<?php
/**
 * Cause_Identity — companion-managed stable IDs for parent's per-cause data.
 *
 * The parent plugin (Donation for WooCommerce) stores causes as parallel
 * arrays on the campaign post (`donation-cause-names`, `-desc`, `-img`)
 * indexed by position. There is no native cause id — the cause name is
 * the only natural key. That breaks aggregation when admins rename a
 * cause (existing orders carry the old name string).
 *
 * v2.4.0 mints a stable id per cause and threads it through:
 *
 *   - Companion meta `_dfwc_companion_cause_ids` on the campaign post —
 *     position-indexed array, lazily synced to parent's name-array on
 *     first read. New causes get freshly minted IDs; deleted ones are
 *     pruned. Renames preserve the existing ID.
 *
 *   - WC cart-item key `_dfwc_companion_cause_id` — added by the
 *     `woocommerce_add_cart_item_data` listener when the donor's cart
 *     item carries parent's `cause_name`. Looked up by name → companion id.
 *
 *   - WC order-item meta `_dfwc_companion_cause_id` — copied from cart
 *     item to order line item by the
 *     `woocommerce_checkout_create_order_line_item` listener. This is
 *     the persistence target for cause-aware aggregation queries.
 *
 * Forward queries (per-cause raised aggregation, closure check) use the
 * companion id; legacy queries (orders without our id meta) fall back
 * to matching by parent's `Cause` line-item meta against the cause name
 * string. Documented as the v2.4.0 backfill limitation.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Cause_Identity {

	public const META_CAMPAIGN_IDS  = '_dfwc_companion_cause_ids';
	public const META_LINE_ITEM_ID  = '_dfwc_companion_cause_id';
	public const PARENT_NAMES_META  = 'donation-cause-names';
	public const CART_ITEM_KEY      = '_dfwc_companion_cause_id';

	public function __construct() {
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'inject_cart_item_id' ), 20, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist_to_line_item' ), 20, 4 );
	}

	/**
	 * Return the position-indexed map of cause IDs for a campaign. Lazily
	 * syncs to parent's name-array: new causes get fresh IDs; positions
	 * with no corresponding parent name are pruned. Renames preserve the
	 * existing ID at that position.
	 *
	 * @return array<int,string>
	 */
	public static function for_campaign( int $campaign_id ): array {
		$names    = self::parent_names( $campaign_id );
		$existing = self::raw_ids( $campaign_id );
		$dirty    = false;

		// Mint missing IDs for positions that have a parent cause name.
		foreach ( $names as $pos => $name ) {
			if ( '' === (string) $name ) {
				continue;
			}
			if ( empty( $existing[ $pos ] ) ) {
				$existing[ $pos ] = self::generate_id();
				$dirty            = true;
			}
		}

		// Prune IDs for positions that no longer exist in parent's array
		// (admin deleted a cause).
		foreach ( array_keys( $existing ) as $pos ) {
			if ( ! isset( $names[ $pos ] ) || '' === (string) $names[ $pos ] ) {
				unset( $existing[ $pos ] );
				$dirty = true;
			}
		}

		if ( $dirty ) {
			update_post_meta( $campaign_id, self::META_CAMPAIGN_IDS, $existing );
		}

		return $existing;
	}

	/**
	 * Resolve a cause name → companion id for a given campaign. Returns
	 * null when the campaign has no cause matching the name (e.g., the
	 * cart item references a cause that was deleted between add-to-cart
	 * and checkout). Caller treats null as "fall back to legacy name match
	 * during aggregation."
	 */
	public static function id_for_name( int $campaign_id, string $cause_name ): ?string {
		if ( '' === $cause_name ) {
			return null;
		}
		$names = self::parent_names( $campaign_id );
		$ids   = self::for_campaign( $campaign_id );
		foreach ( $names as $pos => $name ) {
			if ( (string) $name === $cause_name && isset( $ids[ $pos ] ) ) {
				return (string) $ids[ $pos ];
			}
		}
		return null;
	}

	/**
	 * Reverse: companion id → cause name for a given campaign. Used by
	 * the closure-mode renderer when it needs to display "this cause"
	 * (the name) given an id from the donor's selection.
	 */
	public static function name_for_id( int $campaign_id, string $cause_id ): ?string {
		if ( '' === $cause_id ) {
			return null;
		}
		$names = self::parent_names( $campaign_id );
		$ids   = self::for_campaign( $campaign_id );
		foreach ( $ids as $pos => $id ) {
			if ( (string) $id === $cause_id && isset( $names[ $pos ] ) ) {
				return (string) $names[ $pos ];
			}
		}
		return null;
	}

	/**
	 * Position lookup — given a cause id, return its parent-array index.
	 * Useful for closure renderers that need to skip a position in the
	 * parent's emitted cause picker.
	 */
	public static function position_for_id( int $campaign_id, string $cause_id ): ?int {
		if ( '' === $cause_id ) {
			return null;
		}
		$ids = self::for_campaign( $campaign_id );
		foreach ( $ids as $pos => $id ) {
			if ( (string) $id === $cause_id ) {
				return (int) $pos;
			}
		}
		return null;
	}

	/**
	 * Lookup id by parent-array position. Lighter-weight than
	 * id_for_name when caller already knows the position (e.g., the
	 * meta-box admin UI iterating in render order).
	 */
	public static function id_for_position( int $campaign_id, int $position ): ?string {
		$ids = self::for_campaign( $campaign_id );
		return isset( $ids[ $position ] ) ? (string) $ids[ $position ] : null;
	}

	/**
	 * Cart-side hook: when a parent donation cart item is added with a
	 * `cause_name`, attach our stable id alongside. Resolves
	 * campaign_id from the cart item data (parent attaches it as
	 * `wc_donation_camp` per its template).
	 *
	 * @param array<string,mixed> $cart_item_data
	 * @param int                 $product_id
	 * @param int                 $variation_id
	 * @return array<string,mixed>
	 */
	public function inject_cart_item_id( $cart_item_data, $product_id, $variation_id ): array {
		if ( ! is_array( $cart_item_data ) || empty( $cart_item_data['cause_name'] ) ) {
			return is_array( $cart_item_data ) ? $cart_item_data : array();
		}

		$campaign_id = self::resolve_campaign_from_cart_item( $cart_item_data, (int) $product_id );
		if ( 0 === $campaign_id ) {
			return $cart_item_data;
		}

		$cause_name = (string) $cart_item_data['cause_name'];
		$cause_id   = self::id_for_name( $campaign_id, $cause_name );
		if ( null === $cause_id ) {
			// Cause not found — donor selected a cause that was deleted
			// between page-load and add-to-cart, or parent's data is
			// stale. Skip; legacy name-match aggregation will catch it.
			return $cart_item_data;
		}

		$cart_item_data[ self::CART_ITEM_KEY ] = $cause_id;
		return $cart_item_data;
	}

	/**
	 * Order-side hook: copy our cause id from cart-item data onto the
	 * created order's line item meta. Runs at priority 20 so parent's
	 * existing add at priority 10 has already populated the line item
	 * with `Cause` (the name); we add the stable id alongside it.
	 *
	 * @param object              $item          WC_Order_Item_Product
	 * @param string              $cart_item_key
	 * @param array<string,mixed> $values        Cart item data
	 * @param object              $order         WC_Order
	 */
	public function persist_to_line_item( $item, $cart_item_key, $values, $order ): void {
		if ( ! is_array( $values ) || empty( $values[ self::CART_ITEM_KEY ] ) ) {
			return;
		}
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}
		$item->add_meta_data( self::META_LINE_ITEM_ID, (string) $values[ self::CART_ITEM_KEY ] );
	}

	/**
	 * Read parent's `donation-cause-names`. Parent's storage quirk:
	 * `update_post_meta` is called with a single-row array value, so
	 * `get_post_meta( $id, 'donation-cause-names', false )` returns
	 * `[ [array of names] ]` in production (single-row meta whose value
	 * is itself an array). Some shapes also surface as a flat
	 * `[name1, name2, ...]` (e.g., `get_post_meta($id, $key, true)`).
	 * We normalize both — if the first element is itself an array,
	 * unwrap it; otherwise treat the top-level as the names array.
	 *
	 * @return array<int,string>
	 */
	private static function parent_names( int $campaign_id ): array {
		$raw = get_post_meta( $campaign_id, self::PARENT_NAMES_META, false );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}
		// Wrapped shape (parent's actual storage): unwrap.
		$source = is_array( $raw[0] ?? null ) ? $raw[0] : $raw;
		$out    = array();
		foreach ( $source as $pos => $name ) {
			$out[ (int) $pos ] = (string) $name;
		}
		return $out;
	}

	/**
	 * @return array<int,string>
	 */
	private static function raw_ids( int $campaign_id ): array {
		$raw = get_post_meta( $campaign_id, self::META_CAMPAIGN_IDS, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $pos => $id ) {
			$out[ (int) $pos ] = (string) $id;
		}
		return $out;
	}

	/**
	 * Resolve the campaign_id from a cart item. Parent attaches the
	 * campaign id under `wc_donation_camp` per its add-to-cart shape;
	 * fall back to looking it up from the linked product's
	 * `wc_donation_product` reverse mapping.
	 *
	 * @param array<string,mixed> $cart_item_data
	 */
	private static function resolve_campaign_from_cart_item( array $cart_item_data, int $product_id ): int {
		if ( ! empty( $cart_item_data['wc_donation_camp'] ) ) {
			return (int) $cart_item_data['wc_donation_camp'];
		}
		// Reverse-resolve via product → campaign meta.
		if ( $product_id > 0 ) {
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
			if ( ! empty( $query->posts ) ) {
				return (int) $query->posts[0];
			}
		}
		return 0;
	}

	/**
	 * Generate a stable id. Uses wp_generate_uuid4 when WP is loaded;
	 * falls back to bin2hex(random_bytes) so the function is testable
	 * without WP. Both produce sufficiently-unique 32+ char strings.
	 */
	private static function generate_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// Last resort — combine uniqid + microtime. Not as strong
			// but never throws. WP runtime always has wp_generate_uuid4
			// so this branch is essentially test-only fallback insurance.
			return md5( uniqid( (string) microtime( true ), true ) );
		}
	}
}
