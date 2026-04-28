# Recurring Subscription Engines

> The companion does not handle recurring billing itself. It feeds the parent plugin's existing AJAX pipeline with the right cadence parameters, and the parent plugin's existing integration with one of two subscription engines does the actual scheduling, billing, and renewal.

You need exactly one of:

| Engine | Cost | License | wp.org listing |
|---|---|---|---|
| **WooCommerce Subscriptions** (WCS) | Paid (~$239/yr) | GPL | [woocommerce.com](https://woocommerce.com/products/woocommerce-subscriptions/) |
| **Subscriptions for WooCommerce** by WPS (WPS SFW) | Free | GPL | [wp.org](https://wordpress.org/plugins/subscriptions-for-woocommerce/) |

Activate exactly one. The companion auto-detects via `Engine_Detector::detect()` and feeds the appropriate AJAX key set at submit time. Both engines are equally supported — there's no functional gap.

---

## Which engine should I pick?

### Pick **Subscriptions for WooCommerce** (WPS SFW, free) if:

- Budget is a constraint (it's a free plugin on wp.org)
- You want to ship recurring donations today without a paid commitment
- Your site doesn't need WCS-specific advanced features (like prorated upgrades / downgrades, free trials, signup fees beyond what WPS offers, or specific Woo gateway integrations not bundled in WPS)

### Pick **WooCommerce Subscriptions** (WCS, paid) if:

- You're already paying for it for other product subscriptions on the same site
- You need the larger ecosystem of compatible Woo extensions (some third-party gateways have WCS-specific addons)
- Your team has WCS expertise from prior projects
- You want Automattic-backed support contracts

For most nonprofits starting fresh, **WPS SFW** is the right choice. The companion sends both engines' POST keys in every recurring submit anyway (master plan deviation D2), so swapping engines later doesn't require companion-side changes — just deactivate one and activate the other; existing campaigns continue to work.

---

## Installation

### Subscriptions for WooCommerce (free)

1. WordPress admin → **Plugins → Add New → Search "Subscriptions for WooCommerce"** by WPS
2. Install + activate
3. Verify via `wp dfwc-companion health` — the `subscription_engine` check should turn green and report `engine=wps_sfw`

### WooCommerce Subscriptions (paid)

1. Purchase from [woocommerce.com/products/woocommerce-subscriptions/](https://woocommerce.com/products/woocommerce-subscriptions/)
2. Download the zip; install via WordPress admin → **Plugins → Add New → Upload Plugin** → activate
3. WCS pulls in `WooCommerce Helper` for license activation; follow Woo's standard licensing flow
4. Verify via `wp dfwc-companion health` — should report `engine=wcs`

---

## What changes when an engine is active

The companion's behavior:

| State | Behavior |
|---|---|
| No engine active | Recurring tabs (monthly / annual / weekly / quarterly / semi-annual / custom) render with `aria-disabled="true"`. One-time still works. Admin notice surfaces on the campaign edit screen. |
| WCS active | Engine detection: `engine=wcs`. Donor submits include `is_recurring`, `new_period`, `new_interval`, `new_length` POST keys. Linked product gets `subscription` term in `product_type` taxonomy + `_subscription_period` / `_subscription_period_interval` / `_subscription_length` meta. |
| WPS SFW active | Engine detection: `engine=wps_sfw`. Donor submits include `wps_sfw_subscription_number`, `wps_sfw_subscription_interval`, `wps_sfw_subscription_expiry_number`, `wps_sfw_subscription_expiry_interval`. Linked product gets `_wps_sfw_product='yes'` + cadence meta. |
| Both active | WCS wins (parent plugin's preference at `class-wcdonation.php:171`). The companion follows parent's choice. |

Donor submits ship **both** engine key sets in every request (master plan deviation D2). The parent reads only the relevant set based on `class_exists()`. Sending both is harmless and avoids a per-render DB query to determine the engine.

---

## Linked product configuration

Subscription engines look at the linked WooCommerce **product's** subscription configuration, not just the cart-line meta. The parent plugin links each `wc-donation` campaign to a hidden WooCommerce product (created at campaign-publish time). For recurring donations to actually renew, that product must be configured as a subscription product.

The companion handles this automatically via `Meta_Box::auto_configure_product_subscription`:

### WCS path

```php
wp_set_object_terms( $product_id, 'subscription', 'product_type' );
update_post_meta( $product_id, '_subscription_period', $primary_period );
update_post_meta( $product_id, '_subscription_period_interval', $primary_multiplier );
update_post_meta( $product_id, '_subscription_length', '0' );
// _subscription_price NOT set — donor's amount is the price.
```

### WPS SFW path

```php
update_post_meta( $product_id, '_wps_sfw_product', 'yes' );
update_post_meta( $product_id, '_wps_sfw_users', 'user' );
update_post_meta( $product_id, 'wps_sfw_subscription_number', $primary_multiplier );
update_post_meta( $product_id, 'wps_sfw_subscription_interval', $primary_period );
update_post_meta( $product_id, 'wps_sfw_subscription_expiry_number', '' );      // empty = open-ended
update_post_meta( $product_id, 'wps_sfw_subscription_expiry_interval', '' );
```

Triggered every time the campaign meta box is saved with monthly/annual/weekly/quarterly/semi-annual/custom enabled. If the auto-configure runs but the product still isn't recognized as a subscription, the meta box surfaces a yellow diagnostic warning with the actual meta values it read — see [`troubleshooting.md`](troubleshooting.md#symptom-yellow-warning-linked-product-is-not-yet-recognized-as-a-subscription-product).

---

## Engine swap

Switching from one engine to the other is supported. Existing campaigns keep their companion config; the companion writes the appropriate engine-specific subscription meta on the next save.

**Steps:**

1. Deactivate the old engine.
2. Activate the new engine.
3. Edit each campaign that has recurring intervals enabled and click **Update**. The save handler re-runs `auto_configure_product_subscription` against the new engine.
4. Spot-check via `wp post meta get <product_id> _wps_sfw_product` (after switch to WPS SFW) or `WC_Subscriptions_Product::is_subscription( $product_id )` (after switch to WCS).

Active subscriptions on the old engine continue under the old engine's renewal logic until they expire. New donors who land after the swap go through the new engine. Mixed-engine subscription rosters are normal during a swap window.

For sites with many campaigns, the bulk-update can be done via WP-CLI:

```bash
# WordPress.org-friendly: re-save every published wc-donation campaign.
# This re-runs the companion's auto-configure on each linked product.
wp post list --post_type=wc-donation --post_status=publish --format=ids \
  | xargs -d ' ' -I{} wp post update {} --post_status=publish
```

---

## Cadence support

| Interval | WCS shape | WPS SFW shape |
|---|---|---|
| Monthly | `new_period=month`, `new_interval=1` | `wps_sfw_subscription_interval=month`, `wps_sfw_subscription_number=1` |
| Annually | `new_period=year`, `new_interval=1` | `wps_sfw_subscription_interval=year`, `wps_sfw_subscription_number=1` |
| Weekly (Phase 7, opt-in) | `new_period=week`, `new_interval=1` | `wps_sfw_subscription_interval=week`, `wps_sfw_subscription_number=1` |
| Quarterly (Phase 7) | `new_period=month`, `new_interval=3` | `wps_sfw_subscription_interval=month`, `wps_sfw_subscription_number=3` |
| Semi-annually (Phase 7) | `new_period=month`, `new_interval=6` | `wps_sfw_subscription_interval=month`, `wps_sfw_subscription_number=6` |
| Custom (Phase 7, admin-defined) | admin-chosen `period` + admin-chosen `interval` | same |

`Config\Engine_Interval_Map` is the single source of truth. Both engines accept all six cadences natively — there's no engine-specific feature gap.

---

## Open-ended vs. fixed-length

Donations are open-ended by default — the donor signs up to give monthly until they cancel. The companion enforces this:

- **WCS**: `new_length=0` (forever)
- **WPS SFW**: empty `wps_sfw_subscription_expiry_number` and `wps_sfw_subscription_expiry_interval`

Fixed-length subscriptions (e.g., "annual sponsorship for one year then auto-cancel") are not currently exposed in the donor-facing UI. Admins can enable parent's fixed-length controls on the campaign edit screen if they need this — the companion's `wc-donation-recurring='user'` setting still gates the donor flow correctly, but the donor will not see length-selection UI from the companion.

---

## Gateways

The companion does **not** configure payment gateways. Whichever gateway your subscription engine integrates with is what donors see at checkout.

Common pairings:

| Engine | Gateway pattern |
|---|---|
| WCS + Stripe | Use the official [WooCommerce Stripe gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/). WCS supports it natively. |
| WCS + PayPal | Use [WooCommerce PayPal Payments](https://wordpress.org/plugins/woocommerce-paypal-payments/). |
| WPS SFW + Stripe | WPS bundles a Stripe addon. Install via WPS's settings page after activating WPS SFW. |
| WPS SFW + PayPal | WPS supports PayPal via WC's standard PayPal gateway. |

Other gateways (Authorize.net, Square, Braintree, etc.) require gateway-specific subscription support — check the gateway plugin's compatibility before relying on recurring donations.

The companion isn't involved in gateway configuration. It hands the cart over to the parent plugin's AJAX handler, which builds a WC order in WC's standard cart pipeline. The donor then proceeds through WC checkout, picks a gateway, and the gateway handles the recurring billing per its own setup.

---

## Renewal lifecycle

After a donor's initial donation succeeds:

1. **WCS**: creates a `shop_subscription` post with status `active`. WCS schedules the next payment via Action Scheduler. On renewal date, WCS triggers the gateway to charge the saved payment method.

2. **WPS SFW**: creates a custom post-type entry with the cadence meta. WPS schedules renewals via WP-Cron + the gateway's recurring API.

The companion has no role in renewals. Once the initial donation is in WC's pipeline, renewal billing is the engine's responsibility.

If a renewal fails (expired card, etc.), the engine emits its own hooks (`woocommerce_subscription_payment_failed` for WCS, similar for WPS SFW). Wire your CRM / dunning logic to those hooks — see [`event-hooks.md`](event-hooks.md) for the companion's own donor-flow hooks if you also want to track *initial* donation events.

---

## Troubleshooting

See [`troubleshooting.md`](troubleshooting.md) for symptom-by-symptom guidance. The most common engine-specific issues:

- **Donor's recurring donation gets charged once, then never renews** — usually `_wps_sfw_product='no'` (or absent) on the linked product. Save the campaign once.
- **Cart shows the donation but the order doesn't include subscription metadata** — `wc-donation-recurring` meta is wrong; should be `'user'`. Check via `wp post meta get <id> wc-donation-recurring`.
- **Submission succeeds but the engine's subscription dashboard doesn't show the new subscription** — the linked product isn't a subscription product. The companion auto-corrects on save; if it doesn't stick, look for hooks on `woocommerce_process_product_meta` overriding our writes.

---

## Companion's role vs. engine's role — at a glance

| Concern | Companion | Engine |
|---|---|---|
| Donor-facing form UX (intervals, presets) | ✅ | — |
| Cadence translation (interval → period/interval) | ✅ via `Engine_Interval_Map` | — |
| Linked product subscription configuration | ✅ auto on save | — (relies on companion's writes) |
| Cart line item creation | — | ✅ via parent + WC |
| Subscription post creation | — | ✅ |
| Renewal scheduling | — | ✅ via Action Scheduler (WCS) / WP-Cron (WPS SFW) |
| Gateway charging | — | — (gateway's responsibility) |
| Donor email receipts | — | ✅ via parent + WC |
| PDF receipts | — | ✅ via parent |
| Subscription cancellation UI | — | ✅ in WC My Account |

The companion's job is **donor-facing UX + correct meta writes**. Everything cart-side and beyond is parent + engine + WC + gateway.
