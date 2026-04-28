# Developer Hook Reference

> All filters and actions the companion exposes for programmatic extension. Stable through v1.x — signatures won't change without a major-version bump.

For Phase 9's six donor-flow event hooks (`dfwc_companion_form_viewed`, `dfwc_companion_donation_submitted`, etc.), see the dedicated [`event-hooks.md`](event-hooks.md). This file covers everything else.

---

## Filters

### `dfwc_should_augment_parent_form`

```php
apply_filters( 'dfwc_should_augment_parent_form', bool $augment, int $campaign_id, string $context ): bool
```

Per-campaign + per-context opt-out. Default `true` when the campaign has companion config saved (template, overrides, or legacy v0.6.x meta).

**`$context`** is one of: `single` | `shortcode` | `widget` | `checkout` | `cart` | `cart_block` | `block` | `elementor`.

```php
// Skip augmentation in the cart_block context for campaign 42.
add_filter( 'dfwc_should_augment_parent_form', function ( $augment, $campaign_id, $context ) {
    if ( 42 === $campaign_id && 'cart_block' === $context ) {
        return false;
    }
    return $augment;
}, 10, 3 );
```

### `dfwc_companion_resolved_config`

```php
apply_filters( 'dfwc_companion_resolved_config', array $config, int $campaign_id ): array
```

Final filter on the resolved per-campaign config — runs after all four layers (defaults → global → template → campaign overrides) have merged + WPML translation pass. Return value flows directly into `Frontend\Renderer::build_form_config`.

Use to inject programmatic adjustments without touching admin UI:

```php
add_filter( 'dfwc_companion_resolved_config', function ( $config, $campaign_id ) {
    // Force-disable annual on campaigns tagged "monthly-only".
    if ( has_term( 'monthly-only', 'dfwc_program', $campaign_id ) ) {
        $config['annual']['enabled'] = false;
    }
    return $config;
}, 10, 2 );
```

### `dfwc_companion_active_currency`

```php
apply_filters( 'dfwc_companion_active_currency', string $currency, array $context ): string
```

The donor's active currency. WCML detection runs first (when active), then this filter, then WC base currency. `$context` is `[ 'context' => 'admin'|'frontend' ]`.

Primary extension point for non-WCML multi-currency stacks. See [`multi-currency.md`](multi-currency.md) for WCPay / Aelia snippets.

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

Final filter on a per-currency interval block after the resolver merges overrides. Useful for telemetry, A/B variant injection, or programmatic adjustments.

### `dfwc_companion_supported_currencies`

```php
apply_filters( 'dfwc_companion_supported_currencies', array $codes ): array
```

Currency-code list shown in the admin per-currency UI. Defaults to WCML's active set; `[ base_currency ]` otherwise.

### `dfwc_companion_advanced_intervals_enabled`

```php
apply_filters( 'dfwc_companion_advanced_intervals_enabled', bool $enabled ): bool
```

Final say on whether advanced intervals (weekly / quarterly / semiannual / custom) are enabled. Reads the global toggle by default; this filter lets you force-enable via code without flipping the UI toggle.

```php
// Enable advanced intervals via code only on multisite subsite 3.
add_filter( 'dfwc_companion_advanced_intervals_enabled', function ( $enabled ) {
    return $enabled || get_current_blog_id() === 3;
} );
```

### `dfwc_companion_engine_supported_intervals`

```php
apply_filters( 'dfwc_companion_engine_supported_intervals', array $intervals, string $engine ): array
```

Per-engine narrowing of supported intervals. Both engines support all 7 intervals natively; this filter lets sites with custom engine constraints opt out:

```php
// On a site running an old WPS SFW version that doesn't handle weekly cadences:
add_filter( 'dfwc_companion_engine_supported_intervals', function ( $intervals, $engine ) {
    if ( 'wps_sfw' === $engine ) {
        return array_diff( $intervals, array( 'weekly', 'custom' ) );
    }
    return $intervals;
}, 10, 2 );
```

### `dfwc_companion_active_language_codes`

```php
apply_filters( 'dfwc_companion_active_language_codes', array $codes ): array
```

Allow-list for language codes that flow through `Privacy_Guard::sanitize_language`. Default = WPML's active languages + the site locale (split on `_` so `en_US` exposes both `en_US` and `en`).

### `dfwc_companion_contract_skip_augment`

```php
apply_filters( 'dfwc_companion_contract_skip_augment', bool $skip, Parent_Form_Contract_Report $report ): bool
```

When the parent-contract diagnostic reports broken status, `Frontend\Context_Augmenter` skips augmentation by default — the donor sees parent's vanilla form. This filter lets you override that behavior (e.g., for staging environments where you want to test even with broken contracts).

```php
add_filter( 'dfwc_companion_contract_skip_augment', '__return_false' );
```

### `dfwc_companion_contracts`

```php
apply_filters( 'dfwc_companion_contracts', array $contracts ): array
```

The list of `Parent_Form_Contract` entries shown on the Diagnostics page. Allows third-party integrations to add custom checks (e.g., "is your tracking pixel loaded?"):

```php
use DFWC\Companion\Contracts\Parent_Form_Contract;
use DFWC\Companion\Contracts\Parent_Form_Contract_Result;

add_filter( 'dfwc_companion_contracts', function ( $contracts ) {
    $contracts[] = new Parent_Form_Contract(
        'my_pixel',
        __( 'Pixel loaded', 'my-text-domain' ),
        __( 'Custom analytics pixel must be loaded.', 'my-text-domain' ),
        Parent_Form_Contract::SEVERITY_WARNING,
        function () {
            $loaded = wp_script_is( 'my-pixel', 'enqueued' );
            return $loaded
                ? Parent_Form_Contract_Result::pass( 'my_pixel' )
                : Parent_Form_Contract_Result::warn( 'my_pixel', 'Pixel not enqueued.' );
        }
    );
    return $contracts;
} );
```

---

## Actions

### `dfwc_companion_terms_seeded`

```php
do_action( 'dfwc_companion_terms_seeded' )
```

Fires once when default starter taxonomy terms are seeded on first activation. No args — use `Campaign_Taxonomies::seeded_terms()` to inspect what was created.

### `dfwc_companion_contract_report_built`

```php
do_action( 'dfwc_companion_contract_report_built', Parent_Form_Contract_Report $report )
```

Fires after `Parent_Form_Contract_Checker` builds a fresh diagnostic report (before transient caching). Lets third-party monitors (Sentry, Datadog, Healthchecks.io) ingest the report.

```php
add_action( 'dfwc_companion_contract_report_built', function ( $report ) {
    if ( $report->is_broken() ) {
        wp_remote_post( 'https://hooks.slack.com/...', array(
            'body' => wp_json_encode( array(
                'text' => 'DFWC contract broken: ' . $report->overall_status,
            ) ),
            'blocking' => false,
        ) );
    }
} );
```

### `dfwc_companion_contract_report_cleared`

```php
do_action( 'dfwc_companion_contract_report_cleared' )
```

Fires when an admin clicks "Re-check" on the Diagnostics page. No args.

### Phase 9 event hooks

Six donor-flow events documented in [`event-hooks.md`](event-hooks.md):

- `dfwc_companion_form_viewed`
- `dfwc_companion_interval_selected`
- `dfwc_companion_preset_selected`
- `dfwc_companion_custom_amount_entered`
- `dfwc_companion_donation_submitted`
- `dfwc_companion_donation_failed`

---

## Constants worth knowing

| Constant | What |
|---|---|
| `DFWC_COMPANION_VERSION` | Plugin version string (`'1.1.0'`) |
| `DFWC_COMPANION_PATH` | Absolute filesystem path to the plugin dir, with trailing slash |
| `DFWC_COMPANION_URL` | Plugin URL with trailing slash |
| `DFWC_COMPANION_BASENAME` | `donations-for-woocommerce-companion/donations-for-woocommerce-companion.php` |
| `DFWC_COMPANION_MIN_PARENT_VERSION` | `'3.9.8'` — the parent plugin version we test against |
| `DFWC_COMPANION_MIN_PHP` | `'7.4'` |

Class constants worth knowing:

```php
DFWC\Companion\Config\Config_Resolver::META_KEY_INTERVALS    // '_dfwc_companion_intervals' (legacy)
DFWC\Companion\Config\Config_Resolver::META_KEY_OVERRIDES    // '_dfwc_companion_overrides'
DFWC\Companion\Config\Config_Resolver::META_KEY_TEMPLATE     // '_dfwc_companion_template_id'
DFWC\Companion\Config\Config_Resolver::META_KEY_DETACHED     // '_dfwc_companion_detached'
DFWC\Companion\Config\Config_Resolver::OPTION_KEY_GLOBAL     // 'dfwc_companion_global_settings'

DFWC\Companion\Config\Config_Resolver::INTERVAL_ONE_TIME     // 'one_time'
DFWC\Companion\Config\Config_Resolver::INTERVAL_MONTHLY      // 'monthly'
DFWC\Companion\Config\Config_Resolver::INTERVAL_ANNUAL       // 'annual'
DFWC\Companion\Config\Config_Resolver::INTERVAL_WEEKLY       // 'weekly'
DFWC\Companion\Config\Config_Resolver::INTERVAL_QUARTERLY    // 'quarterly'
DFWC\Companion\Config\Config_Resolver::INTERVAL_SEMIANNUAL   // 'semiannual'
DFWC\Companion\Config\Config_Resolver::INTERVAL_CUSTOM       // 'custom'

DFWC\Companion\Engine_Detector::ENGINE_NONE                  // 'none'
DFWC\Companion\Engine_Detector::ENGINE_WCS                   // 'wcs'
DFWC\Companion\Engine_Detector::ENGINE_WPS                   // 'wps_sfw'

DFWC\Companion\Taxonomy\Campaign_Taxonomies::META_KEY_FEATURED  // '_dfwc_companion_featured'
```

---

## Public APIs

These classes expose stable static / public methods for site-specific extension code:

| Class | What |
|---|---|
| `DFWC\Companion\Config\Config_Resolver` | `resolve($id)`, `resolve_display($id)`, `is_configured($id)`, `intervals($include_advanced)`, `advanced_enabled()`, `trace_inheritance($id)` |
| `DFWC\Companion\Config\Currency_Preset_Resolver` | `resolve($block, $currency)`, `active_currency()`, `base_currency()`, `supported_currencies()`, `extra_currencies()`, `multi_currency_active()` |
| `DFWC\Companion\Config\Engine_Interval_Map` | `for_interval($key, $block)`, `ajax_keys($key, $block)`, `is_supported($key, $engine)` |
| `DFWC\Companion\Engine_Detector` | `detect()`, `supports_recurring()`, `recommended_install_url()` |
| `DFWC\Companion\I18n\WPML_Strings` | `wpml_active()`, `current_language()`, `register($key, $value)`, `translate($value, $key)` |
| `DFWC\Companion\Frontend\Renderer` | `render($id)`, `wrap_with_overlay($id, $inner, $context)`, `build_overlay_attributes($id)`, `format_cta($template, $amount, $interval, $block)` |
| `DFWC\Companion\Analytics\Privacy_Guard` | `sanitize_event($event)`, individual sanitizers for each field, `event_types()`, `contexts()`, `active_language_codes()` |

---

## REST API

Two endpoints. Both registered under namespace `dfwc-companion/v1`:

| Route | Method | Auth | Use |
|---|---|---|---|
| `/preview` | POST | `manage_woocommerce` | Live preview pane render |
| `/grid` | GET | `__return_true` (public) | Donor directory live search |
| `/track` | POST | `__return_true` (public) | Donor analytics ingest (Phase 9) |

The preview endpoint is rate-limited to 10 req/sec/user (admin-side). The grid endpoint is rate-limited to 60 req/min/IP. The track endpoint is rate-limited to 100 events/IP/min — events, not requests, are counted (a 50-event batch bumps the counter by 50).

---

## CLI

`wp dfwc-companion <subcommand>` — see [`includes/CLI/CLI_Commands.php`](../includes/CLI/CLI_Commands.php). Currently shipping:

```bash
wp dfwc-companion health [--format=table|json|yaml|csv|markdown] [--refresh]
```

Future subcommands (templates list/apply, cleanup, etc.) plug into the same `CLI_Commands` class without changing the registration.

---

## Conventions

- All hook names prefixed `dfwc_companion_` (or `dfwc_should_*` for opt-out filters).
- Phase 9 event hooks use the verb-tense pattern (`form_viewed`, `donation_submitted`).
- All translatable strings use the `dfwc-companion` text domain.
- All capability checks use `manage_woocommerce` for admin operations; `edit_post` for per-campaign meta-box operations.
- Public methods declared `public static` when stateless; instance methods only when state is genuinely held.
