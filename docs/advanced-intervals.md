# Advanced Giving Intervals

> Available from **v1.0.0**. Off by default — donors continue to see the standard One-time / Monthly / Annually tabs unless an admin opts in.

The companion supports four additional cadences beyond the standard three:

| Interval | Cadence | Engine POST keys (WCS) | Engine POST keys (WPS SFW) |
|---|---|---|---|
| **Weekly** | every week | `new_period=week`, `new_interval=1` | `wps_sfw_subscription_interval=week`, `wps_sfw_subscription_number=1` |
| **Quarterly** | every 3 months | `new_period=month`, `new_interval=3` | `wps_sfw_subscription_interval=month`, `wps_sfw_subscription_number=3` |
| **Semi-annually** | every 6 months | `new_period=month`, `new_interval=6` | `wps_sfw_subscription_interval=month`, `wps_sfw_subscription_number=6` |
| **Custom cadence** | admin-defined | admin-defined `new_period` + `new_interval` | admin-defined |

Both supported subscription engines (WooCommerce Subscriptions, Subscriptions For WooCommerce) accept all four — the cadence is just a `(period, multiplier)` tuple they hand off to the renewal scheduler.

---

## Use cases

| Use case | Recommended interval |
|---|---|
| Weekly tithing for a faith community | **Weekly** |
| Quarterly mission support / board engagement | **Quarterly** |
| Twice-a-year membership renewals | **Semi-annually** |
| "Every 6 weeks" sponsorship cycles | **Custom** with `period=week`, `interval=6` |
| Bi-monthly newsletter-pledge | **Custom** with `period=month`, `interval=2` |

The custom interval also covers daily cadences (`period=day`, `interval=1`) when an engine and gateway permit it. We don't ship a built-in "Daily" tab because most engines / gateways throttle daily charges and the donor UX rarely benefits.

---

## Enabling

### 1. Flip the global toggle

`WooCommerce → Donations Companion → Settings → Advanced giving intervals`

Check **Enable advanced giving intervals (weekly, quarterly, semi-annually, custom cadence)** and save.

This single toggle gates the admin UI. Donor pages render advanced tabs only when:
- the global toggle is on,
- a subscription engine is active, AND
- the campaign / template has the specific interval enabled.

A diagnostic on the **Diagnostics** admin page warns when the global toggle is on but no subscription engine is present.

### 2. Enable per template or campaign

After flipping the global toggle, the meta box (per-campaign) and Templates page now show four extra interval tabs at the end. Each tab carries the same controls as the standard three:

- Enable checkbox (off by default)
- Preset amounts (with labels, impact labels, featured flag)
- Custom-amount min/max
- CTA template
- Per-currency overrides (when WCML is active)
- Donor impact messaging

### 3. Configure the custom interval

When you enable the **Custom** tab, an extra "Custom cadence" sub-section appears with:

- **Every N \[day | week | month | year]** — the engine cadence
- **Donor-facing label** — free-form text donors see (e.g. *every 6 weeks*). Translatable via WPML.

The CTA template supports a `{custom_label}` token that substitutes the donor-facing label at render time:

```
Donate {amount} {custom_label}
```

— renders as *Donate $25 every 6 weeks*.

---

## Donor experience

Donor-side rendering is gated three ways:

1. **Global toggle** off → only One-time / Monthly / Annually tabs visible. (Default install — no UI change.)
2. **Global toggle** on, but specific interval **disabled** at the campaign / template level → that tab is hidden for donors.
3. **Global toggle** on, interval enabled, but **no subscription engine** active → the recurring tab is hidden (engine gating in `Frontend\Renderer::resolve_enabled_intervals`).

When ≤4 intervals are enabled, donors see them as side-by-side inline tabs. When ≥5 are enabled, the overlay JS automatically rolls everything past the first three into a **"More options ▾"** dropdown that surfaces on click. The threshold is intentional — narrow donor screens (mobile, sidebar widget) start to wrap awkwardly past 4 tabs.

---

## Programmatic control

### Filters

```php
// Force-enable advanced intervals via code (override global toggle).
add_filter( 'dfwc_companion_advanced_intervals_enabled', '__return_true' );
```

```php
// Narrow which intervals a particular engine can serve. Useful when a
// specific WPS SFW or WCS install can't handle a cadence (rare).
add_filter( 'dfwc_companion_engine_supported_intervals', function ( $intervals, $engine ) {
    if ( 'wps_sfw' === $engine ) {
        // Drop daily / weekly cadences for sites that don't want them.
        return array_diff( $intervals, array( 'weekly', 'custom' ) );
    }
    return $intervals;
}, 10, 2 );
```

```php
// Inspect / mutate a resolved interval cadence before the donor form ships
// it to the engine. Good for logging or for forcing a particular cadence on
// a specific campaign.
add_filter( 'dfwc_companion_resolved_currency_block', function ( $resolved ) {
    // ... your logic here ...
    return $resolved;
}, 10, 4 );
```

### Constants

```php
DFWC\Companion\Config\Config_Resolver::INTERVAL_WEEKLY     // 'weekly'
DFWC\Companion\Config\Config_Resolver::INTERVAL_QUARTERLY  // 'quarterly'
DFWC\Companion\Config\Config_Resolver::INTERVAL_SEMIANNUAL // 'semiannual'
DFWC\Companion\Config\Config_Resolver::INTERVAL_CUSTOM     // 'custom'
```

### Helpers

```php
use DFWC\Companion\Config\Engine_Interval_Map;

// (period, multiplier) for any interval — null for one_time.
Engine_Interval_Map::for_interval( 'quarterly' );
// => [ 'period' => 'month', 'interval' => 3 ]

Engine_Interval_Map::for_interval( 'custom', $block );
// => reads $block['custom_period'] / $block['custom_interval'].

// Both engine key sets, ready to ship as the donor's AJAX POST body.
Engine_Interval_Map::ajax_keys( 'weekly' );
// => [ 'is_recurring' => 'yes', 'new_period' => 'week', 'new_interval' => '1', ... ]
```

---

## Backward compatibility

- Existing v0.6.x — v0.9.0 campaigns render unchanged. The advanced-interval slots are present in storage from v1.0.0 forward but default to `enabled: false`, so they don't appear in the donor form.
- Flipping the global toggle off after configuring advanced intervals retains the saved configuration. Donors stop seeing the advanced tabs immediately; flipping the toggle back on restores them.
- The per-campaign meta-box save handler walks all 7 interval slots regardless of the toggle, so admins can pre-configure intervals before flipping the toggle without losing data.

---

## WPML

These translatable strings are registered with WPML String Translation when admin-defined for an advanced interval (under the existing "Donations Companion" domain):

| String | Key |
|---|---|
| `cta_template` | `{namespace}.{interval}.cta_template` |
| `subtitle` | `{namespace}.{interval}.subtitle` |
| `annual_equivalency` | `{namespace}.{interval}.annual_equivalency` |
| `custom_amount_impact_label` | `{namespace}.{interval}.custom_amount_impact_label` |
| `custom_label` (custom interval only) | `{namespace}.custom.custom_label` |
| Per-preset `label` / `impact_label` | `{namespace}.{interval}.presets.{idx}.{field}` |

`{namespace}` is the template ID for template-defined strings, `_campaign:<id>` for per-campaign overrides, or `_global` for global-default strings.

The cadence itself (`custom_period` / `custom_interval`) is config, not content — not translated.

---

## Submit-side validation

`Frontend\Submit_Guard` rejects donor submits whose `(new_period, new_interval)` tuple doesn't match any enabled interval on the campaign. Examples:

- Donor posts `new_period=week, new_interval=1` against a campaign with no Weekly tab → 422 reject.
- Donor posts `new_period=week, new_interval=6` against a campaign with custom enabled at `week × 6` → accepted as Custom.
- Donor posts `new_period=fortnight` (invalid) → 422 reject.

This means even a determined attacker scraping the donor form can't manufacture a cadence the admin didn't authorize.

---

## Caveats

- **Daily cadence** (`period=day`) ships as an option in the custom interval but isn't documented as a use case — most gateways and engines throttle daily charges. Test on a staging site before production.
- **Engine swap** (WCS → WPS SFW or vice versa) preserves saved configuration. Re-saving the campaign rewrites the engine-specific subscription meta automatically.
- **Custom interval changes** require a re-save to update the linked product's subscription cadence meta. The companion does this automatically on `wc_donation_after_save_campaign_meta`.
