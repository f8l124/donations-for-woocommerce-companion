# HPOS + Cart/Checkout Block Compatibility

> Both declared in `Plugin::declare_compatibility()` on the `before_woocommerce_init` action. The companion is a "fully compatible" plugin under WooCommerce's compatibility framework.

---

## What HPOS is

**High-Performance Order Storage** is WooCommerce's modern order storage backend. Instead of storing each order as a `shop_order` post in `wp_posts` + serialized post meta, HPOS uses dedicated `wc_orders` and `wc_order_addresses` tables for faster queries and better data integrity.

WooCommerce 8.2+ ships HPOS-by-default for new installs. Existing sites can opt in via WC Settings → Advanced → Features → "High-Performance Order Storage."

Plugins that touch order data (read or write) must declare HPOS compatibility, or WC's safety net will warn admins on activation.

---

## How the companion declares compatibility

```php
// includes/Plugin.php
public function declare_compatibility(): void {
    if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DFWC_COMPANION_FILE, true );
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DFWC_COMPANION_FILE, true );
}
```

Hooked on `before_woocommerce_init` so the declaration runs before WC's compatibility scanner. The third arg `true` means "compatible." `DFWC_COMPANION_FILE` is the path to the main plugin file (matches the WC API contract).

Both `custom_order_tables` (HPOS) and `cart_checkout_blocks` (the modern WooCommerce Cart and Checkout Gutenberg blocks) are declared.

---

## Why the companion is HPOS-compatible

The companion stores its data on `wc-donation` campaign posts (a parent-plugin post type), not on orders:

| Where the companion writes | Type | Affected by HPOS? |
|---|---|---|
| `wp_posts` (`wc-donation` post type) | Campaign config | ❌ HPOS only relocates `shop_order` posts |
| `wp_postmeta` for `wc-donation` campaigns | Campaign config | ❌ HPOS only relocates order meta |
| `wp_options` (templates, settings) | Plugin-wide | ❌ |
| `wp_terms` / `wp_term_taxonomy` (campaign taxonomies) | Phase 4 | ❌ |
| Transients (rate-limit caches) | Plugin internals | ❌ |

The companion never reads or writes order data directly. When a donor submits, the parent plugin's `donation_to_order` AJAX handler is what creates the WC order — and the parent plugin handles HPOS-vs-legacy storage internally. The companion's role ends at the cart-line-creation boundary.

So HPOS-compatible isn't a special accommodation — it's a side effect of the companion's clean separation from order data.

---

## Verification

The Diagnostics page (`Donations Companion → Diagnostics`) doesn't have a dedicated HPOS check — there's nothing companion-side to verify because the declaration is the only contract. To confirm:

1. Activate WooCommerce + Donation for WooCommerce + the companion.
2. WC Admin → Status → Tools → "WooCommerce Compatibility" — the companion should appear in the green "Compatible" list, never in the orange "Possibly incompatible" or red "Incompatible" lists.
3. Toggle HPOS on/off in WC Settings → Advanced → Features. The companion should activate cleanly under both states with zero notices.

If the companion appears in the "Possibly incompatible" list, the declaration didn't run. Check that:

- WooCommerce ≥ 7.1 (when `FeaturesUtil` shipped).
- The companion is active before WC's compatibility scanner runs (the `before_woocommerce_init` hook ensures this).
- No fatal errors are blocking `Plugin::boot()` (check `wp-content/debug.log`).

---

## Cart/Checkout Block compatibility

The modern WooCommerce Cart and Checkout blocks (in the `WooCommerce` block library) are the default cart UI for WP 6.0+. Some plugins that hook into the legacy `[woocommerce_cart]` shortcode break under the block-based cart.

The companion's role at cart time is:

1. The donor submits via parent's `donation_to_order` AJAX handler.
2. Parent creates a WC cart line item.
3. The donor lands on the cart page (whether shortcode-based or block-based).

Steps 2 and 3 don't involve the companion. The companion is "compatible" in the sense that it doesn't interfere with cart rendering — there's nothing companion-side that hooks into the cart UI.

Tested:

- ✅ `[woocommerce_cart]` shortcode-based cart
- ✅ `<!-- wp:woocommerce/cart /-->` block-based cart
- ✅ `<!-- wp:woocommerce/checkout /-->` block-based checkout

The companion's E2E spec `tests/e2e/09-cart-block.spec.ts` verifies a one-time donation lands as a cart line item correctly under the block-based cart.

---

## What about future WC compatibility flags?

WooCommerce occasionally adds new compatibility flags (Subscriptions in the future, multi-store, etc.). The companion's declaration list will grow as those land — typically adding one line per flag in `Plugin::declare_compatibility()`.

If a new flag relates to features the companion doesn't touch, declaring `true` (compatible) is the default safe choice. If a flag relates to features the companion DOES touch, the declaration needs review — sometimes "fully compatible" requires code changes, not just a declaration.

---

## What the companion does NOT do

Several patterns that would break HPOS / Cart Block compatibility, which the companion deliberately avoids:

- ❌ **No `$wpdb->query` against `wp_postmeta` for orders.** All order meta access (which the companion never does anyway) would go through `$order->get_meta()` / `$order->update_meta_data()`.
- ❌ **No `WP_Query` with `'post_type' => 'shop_order'`.** The companion has no order-listing UI.
- ❌ **No assumption that `get_post( $order_id )` returns a `WP_Post`.** Under HPOS, orders aren't posts.
- ❌ **No JS that targets cart-shortcode-specific selectors.** The companion's overlay JS targets `.wc-donation-in-action` (parent's container) which is rendered by the parent plugin, not by WC core.

---

## Compatibility matrix

The full compatibility matrix lives at [`compatibility-matrix.md`](architecture/compatibility-matrix.md). HPOS / Cart Block coverage:

| WC version | HPOS state | Cart UI | Status |
|---|---|---|---|
| 7.1+ | Off (legacy posts) | Shortcode | ✅ Tested |
| 7.1+ | Off (legacy posts) | Block-based | ✅ Tested |
| 8.2+ | On (HPOS) | Shortcode | ✅ Tested |
| 8.2+ | On (HPOS) | Block-based | ✅ Tested |

---

## What if HPOS breaks something downstream?

If the parent plugin's order creation fails under HPOS (parent reads / writes order data; the companion doesn't), that's a parent-plugin issue. File at the parent plugin's support channel.

If WooCommerce changes the `FeaturesUtil` API (renames `declare_compatibility`, changes the signature, etc.), the parent-contract diagnostic in the companion's Diagnostics page will surface it as a warning. The fallback: the declaration silently skips when `FeaturesUtil` isn't available, so the companion doesn't break — it just shows up in WC's "Possibly incompatible" list until the API stabilizes.

---

## Summary

HPOS + Cart/Checkout Block compatibility is essentially free for the companion because:

1. The companion's data lives on campaign posts, not orders.
2. The companion never queries or mutates order tables.
3. The companion never injects markup into the cart UI.

The two-line declaration is the entire contract. Verification is "the green list in WC Status." Maintenance is "add a new line if WC ships a new flag."
