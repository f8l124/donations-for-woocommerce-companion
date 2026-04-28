# Multi-Currency Presets

> Available from **v1.0.0**. Define psychologically rounded preset amounts per currency so donors in non-base currencies see clean numbers, not auto-converted ones.

A nonprofit running a "$25/month for school supplies" campaign in the US wants a "£20/month" or "€22/month" equivalent shown to UK or EU donors — not the awkward "£19.78/month" that real-time currency conversion produces. Auto-converted numbers kill conversion. Phase 6 lets admins manually define per-currency amounts that resolve at render time based on the donor's active currency.

---

## How it works

| Layer | Behavior |
|---|---|
| **Storage** | Sparse `currency_overrides` map per interval block. Only fields that differ from the base block need to be saved (override `amount` only; labels / impact_label / featured / sort_order all inherit from the base preset). |
| **Active-currency lookup** | WPML WooCommerce Multilingual primary (`wcml_get_user_currency()`). WC base currency fallback. The `dfwc_companion_active_currency` filter is the final say so non-WCML stacks (WCPay Multi-Currency, Aelia Currency Switcher) can wire in via a 5-line snippet. |
| **Supported currencies** | When WCML is active, `wcml_multi_currency()->get_active_currencies()` defines the admin-UI dropdown. Without WCML, only the base currency is supported (admin UI is hidden). |
| **Donor-side resolution** | `Frontend\Renderer::build_form_config` resolves each interval block per the donor's active currency at render time. `Config_Resolver` itself stays cache-friendly and currency-agnostic. |
| **Defense** | `Submit_Guard` re-resolves the block under the active currency before validating amount min/max — a £5 donation in GBP isn't rejected against a base-USD threshold. |
| **Backward compat** | Pre-v1.0.0 campaigns render unchanged. Empty `currency_overrides` is the default; admins opt in by filling the per-currency UI. |

---

## Setup with WPML + WCML

### Prerequisites

1. **WPML core** + **WPML String Translation** + **WPML Translation Management** active.
2. **WPML WooCommerce Multilingual** (WCML) active — this is the multi-currency plugin in the WPML family.
3. WCML configured with at least one non-base currency in *WPML → WooCommerce Multilingual → Multi-currency*.

### Configuration

1. Edit a campaign or template.
2. For each interval that has presets, the meta box / template edit form now shows a **"Per-currency preset amounts"** section.
3. Each currency you've enabled in WCML gets a collapsible row:

```
▶ GBP — £
  Base $25  →  GBP amount: [ £20.00  ]
  Base $50  →  GBP amount: [ £40.00  ]
  Base $100 →  GBP amount: [ £80.00  ]
  Min: [ £5    ]   Max: [ £8000   ]
```

4. Empty rows fall back to the base amount. So if you want GBP donors to see preset amounts £20 / £40 / £80 but accept the base USD min/max thresholds, fill the preset rows and leave Min/Max empty.

### Donor experience

When a UK donor lands on the page (their browser locale resolves to GBP via WCML), the donor form renders with the GBP preset amounts. The CTA reads "Donate £20/month" with `wc_price()` formatting that respects WCML's GBP locale.

If WCML doesn't recognize the donor's locale (or the donor explicitly switches currency to one without overrides), the donor falls back to the base USD presets — `wc_price()` still renders in their selected currency, so a US donor sees `$25`, a German donor (no EUR overrides defined) sees `25.00 €` or whatever WCML's locale-aware formatter produces. The number is base-currency, the formatting is donor-currency.

---

## Setup with WCPay Multi-Currency

WooCommerce Payments includes a multi-currency feature. The companion doesn't natively detect it — WCPay's currency-switcher API differs from WCML's — but a small filter snippet wires it up:

```php
add_filter( 'dfwc_companion_active_currency', function ( $currency, $context ) {
    if ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
        $wcp = WC_Payments_Multi_Currency();
        if ( $wcp && method_exists( $wcp, 'get_selected_currency' ) ) {
            $selected = $wcp->get_selected_currency();
            if ( is_object( $selected ) && method_exists( $selected, 'get_code' ) ) {
                return strtoupper( $selected->get_code() );
            }
        }
    }
    return $currency;
}, 10, 2 );
```

Add to a small mu-plugin or your theme's `functions.php`. The companion's per-currency UI then surfaces the currencies WCPay has enabled (via `dfwc_companion_supported_currencies` — see below).

If WCPay doesn't expose its supported-currency list via a discoverable API, you can hardcode the list:

```php
add_filter( 'dfwc_companion_supported_currencies', function () {
    return array( 'USD', 'GBP', 'EUR', 'CAD', 'AUD' );
} );
```

---

## Setup with Aelia Currency Switcher

Aelia exposes the active currency via `WOOCS` global and a session value. Same filter pattern:

```php
add_filter( 'dfwc_companion_active_currency', function ( $currency ) {
    if ( class_exists( '\Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' ) ) {
        $aelia = \Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher::instance();
        if ( $aelia && method_exists( $aelia, 'get_selected_currency' ) ) {
            return strtoupper( $aelia->get_selected_currency() );
        }
    }
    return $currency;
} );
```

---

## Filter reference

### `dfwc_companion_active_currency`

```php
apply_filters( 'dfwc_companion_active_currency', string $currency, array $context ): string
```

Final say on the donor's active currency. WCML detection runs first (when active), then this filter, then WC base currency as the floor. `$context` is `[ 'context' => 'admin'|'frontend' ]`.

### `dfwc_companion_resolved_currency_block`

```php
apply_filters(
    'dfwc_companion_resolved_currency_block',
    array $resolved,
    array $base_block,
    string $currency,
    string $base_currency
): array
```

Final filter on a per-currency interval block after the resolver merges overrides. Useful for round-trip telemetry, A/B variant injection, or programmatic adjustments.

### `dfwc_companion_supported_currencies`

```php
apply_filters( 'dfwc_companion_supported_currencies', array $codes ): array
```

The currency-code list shown in the admin UI. Defaults to WCML's active set when WCML is loaded; just `[ base_currency ]` otherwise. Filterable for non-WCML stacks.

### `dfwc_companion_currency_override_amount` (reserved)

Reserved for an advanced custom resolver that wants to mutate the per-amount value (e.g., apply a custom rounding rule). Not currently emitted; stable signature shipped in v1.x for forward-compat.

---

## Cart-side behavior

When a donor in GBP clicks Donate, the parent plugin's existing `donation_to_order` AJAX handler runs unchanged. The amount that ends up in the cart is whatever the donor selected — £20 in this case. WooCommerce + WCML / WCPay / Aelia handle the cart-side currency context: the cart shows £20, the gateway charges in GBP if configured.

The companion never converts amounts. It only **selects** which preset the donor sees.

---

## Admin scope

| Where | Behavior |
|---|---|
| Campaign meta box | Per-currency UI appears below "Custom amount" for each enabled interval. Hidden on monolingual / single-currency sites. |
| Templates page edit form | Same UI — define per-currency once at the template level, propagates to every campaign assigned to that template. |
| Settings page (global defaults) | Currently no per-currency UI here — global defaults are base-currency only. Per-currency overrides cascade down from template + campaign layers. |

---

## WPML translation

Currency override amounts are **not** translatable strings — currency context is shared across languages, and converting a numeric amount via translation makes no sense. WCML itself handles cross-language currency.

What IS translatable across languages: per-preset `label` / `impact_label`, per-interval `cta_template` / `subtitle` / `annual_equivalency`. These translate the same way they do for base-currency presets — registered with WPML String Translation under the `Donations Companion` domain at save time.

---

## Common pitfalls

### "WCML doesn't show the per-currency section in my admin"

Check that WCML is configured with at least one currency beyond your base. The companion hides the per-currency UI on monolingual / single-currency sites — running `Currency_Preset_Resolver::extra_currencies()` returns empty unless WCML reports active currencies.

### "My GBP donor sees the base USD presets"

Three causes, in order of likelihood:

1. WCML isn't active or isn't reporting GBP as the donor's currency. Run `wp dfwc-companion health` — the WCML check should be green.
2. The campaign / template doesn't have GBP overrides defined. Edit the campaign and check the GBP collapsible row.
3. A custom filter on `dfwc_companion_active_currency` is overriding to base. Search your theme / mu-plugins for `dfwc_companion_active_currency`.

### "The cart line item shows £20 but my gateway charges in USD"

That's a WCML / WCPay / gateway configuration issue, not a companion issue. The companion writes the donor's selected amount (£20) into parent's cart pipeline. Whether the gateway charges in GBP or converts to USD depends on the gateway's multi-currency support — usually configured in the gateway's settings, not the companion.

### "Storage growth concern — 6 currencies × 5 presets × 3 intervals × 200 campaigns = 18,000 array rows"

Acceptable for nonprofit usage. The companion stores `currency_overrides` as nested arrays inside the existing `_dfwc_companion_overrides` post meta — same row, slightly larger payload. If real users hit problems at scale, a migration to a lazy-loaded table is on the v2.x roadmap.

---

## Programmatic config

For sites managing many campaigns in code rather than admin UI:

```php
$override = array(
    'monthly' => array(
        'enabled' => true,
        'presets' => array(
            array( 'amount' => 25.0, 'label' => '', 'impact_label' => 'Provides school supplies' ),
            array( 'amount' => 50.0, 'label' => '', 'impact_label' => 'Sponsors a teacher' ),
        ),
        'min' => 5.0,
        'max' => 1000.0,
        'currency_overrides' => array(
            'GBP' => array(
                'presets' => array(
                    array( 'amount' => 20.0 ),
                    array( 'amount' => 40.0 ),
                ),
                // min / max omitted — fall back to base.
            ),
            'EUR' => array(
                'presets' => array(
                    array( 'amount' => 22.0 ),
                    array( 'amount' => 45.0 ),
                ),
            ),
        ),
    ),
);

update_post_meta( $campaign_id, '_dfwc_companion_overrides', $override );
```

Or via the `Campaign_Config_Repository`:

```php
use DFWC\Companion\Config\Campaign_Config_Repository;

( new Campaign_Config_Repository() )->set_overrides( $campaign_id, $override );
```

The repo handles the `compute_override_delta` logic automatically — only changed fields persist as overrides; template / global inheritance for unchanged fields stays honest.
