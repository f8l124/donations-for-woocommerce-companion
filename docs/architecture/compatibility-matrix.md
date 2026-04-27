# Compatibility Matrix

> **Audience:** admins evaluating whether the companion fits their stack; maintainers tracking what's tested where.
> **Updated:** 2026-04-27 against v0.6.6.
> **Legend:** ✅ tested in CI or local manual QA · ⚠️ untested but expected to work · ❌ known incompatible

## WordPress

| Version | Status | Notes |
|---|---|---|
| 6.2 | ✅ | Declared minimum |
| 6.4 | ✅ | Block themes tested |
| 6.5 | ✅ | `Requires Plugins:` header honored |
| 6.6 | ⚠️ | Untested but expected |
| 6.7 | ✅ | Latest tested |

## PHP

| Version | Status | Notes |
|---|---|---|
| 7.4 | ✅ | Declared minimum; clean activation notice on lower versions |
| 8.0 | ⚠️ | Expected to work; no breaking changes used |
| 8.1 | ⚠️ | Expected; some PHPStan-flagged spots may surface |
| 8.2 | ⚠️ | Expected; deprecation surface unaudited |
| 8.3 | ⚠️ | Expected; not exercised |

## WooCommerce

| Version | Status | Notes |
|---|---|---|
| 5.0 | ⚠️ | Declared minimum; not actively tested |
| 8.x | ✅ | Tested |
| 9.x | ✅ | Latest tested |

### HPOS (High-Performance Order Storage)
| Mode | Status | Notes |
|---|---|---|
| Disabled | ✅ | Default WC behavior |
| Enabled | ✅ | Companion declares compatibility on `before_woocommerce_init`; touches no order meta directly |
| Compatibility mode | ⚠️ | Expected; not actively tested |

## Parent plugin (Donation for WooCommerce)

| Version | Status | Notes |
|---|---|---|
| 3.9.x older | ⚠️ | Self-check warns on version below 3.9.8 |
| 3.9.8 | ✅ | Tested baseline; all 17 contract spans hashed |
| 3.10+ (3.x) | ⚠️ | Self-check warns; manual test recommended |
| 4.x | ❌ until tested | Self-check warns loudly; major-version contract must be re-validated |

## Subscription engine

| Engine | Status | Notes |
|---|---|---|
| None | ✅ | Recurring tabs disabled in admin meta box; donor sees one-time tab only |
| Subscriptions For WooCommerce (WPS, free) | ✅ | Primary CI fixture; full E2E coverage |
| WooCommerce Subscriptions (Automattic, paid) | ⚠️ | Manual QA only — paid plugin can't be in CI per license |
| Both active simultaneously | ✅ | WCS preferred; WPS SFW falls through |

## Cart UI

| Mode | Status | Notes |
|---|---|---|
| Classic shortcode cart | ✅ | Tested |
| Cart Block | ✅ | Companion declares `cart_checkout_blocks` compat |
| Mixed (classic cart, block checkout) | ⚠️ | Untested |

## Checkout UI

| Mode | Status | Notes |
|---|---|---|
| Classic checkout | ✅ | Tested |
| Checkout Block | ✅ | Companion declares compat |

## Render contexts

| Context | Status | Notes |
|---|---|---|
| Single-campaign permalink | ✅ | Auto-augmented via `wc_donation_before/after_single_add_donation` |
| Parent's `[wc_woo_donation]` shortcode | ✅ | Auto-augmented via `wc_donation_before/after_shortcode_add_donation` |
| Companion's `[dfwc_recurring_donation]` shortcode | ✅ | Renderer wraps parent shortcode internally |
| Companion's Gutenberg block | ✅ | `dfwc-companion/recurring-donation` block |
| Companion's Elementor widget | ✅ | `dfwc-recurring-donation` widget |
| Parent's donation widget | ⚠️ | Auto-augmented via `wc_donation_before/after_widget_add_donation` — untested in CI |
| Parent's cart-side donation prompt | ⚠️ | Auto-augmented; untested in CI |
| Parent's cart-block donation prompt | ⚠️ | Auto-augmented; untested in CI |
| Parent's checkout donation prompt | ⚠️ | Auto-augmented; untested in CI |

## Page builders

| Builder | Status | Notes |
|---|---|---|
| Elementor (Free) | ✅ | Companion ships an Elementor widget |
| Elementor Pro | ✅ | Same widget |
| Beaver Builder | ⚠️ | Generic shortcode `[dfwc_recurring_donation]` should work |
| Divi | ⚠️ | Same |
| Bricks | ⚠️ | Same |
| Gutenberg (block editor only) | ✅ | Companion ships a block |

## Themes

| Theme | Status | Notes |
|---|---|---|
| Hello Elementor | ✅ | Tested baseline (user's primary theme) |
| Twenty Twenty-Four (block) | ⚠️ | Block theme; expected to work |
| Twenty Twenty-Three | ⚠️ | Untested |
| Astra | ⚠️ | Untested |
| GeneratePress | ⚠️ | Untested |
| Kadence | ⚠️ | Untested |
| OceanWP | ⚠️ | Untested |
| Storefront (WC default) | ⚠️ | Untested but should be straightforward |

## Caching

| Plugin / Service | Status | Notes |
|---|---|---|
| LiteSpeed Cache (Hostinger default) | ⚠️ | Page caching requires manual purge after admin save; documented in `docs/troubleshooting.md` |
| WP Rocket | ⚠️ | Same caveat applies |
| W3 Total Cache | ⚠️ | Same |
| WP Super Cache | ⚠️ | Same |
| Cloudflare full-page caching | ⚠️ | Same; admin should bypass cache for `/wp-admin/` and donation campaign pages |

The `data-config` JSON is baked into the page HTML — when admins save changes, the cached HTML still serves the old config until cache invalidates. Documented as a known limitation.

## Multilingual

| Plugin | Status | Notes |
|---|---|---|
| WPML | ✅ | `wpml-config.xml` ships at plugin root; v0.7.0+ extends with String Translation registration. Optional CI fixture gated on `DFWC_WPML_ZIP_URL` repo secret. |
| Polylang | ⚠️ | Functionally similar to WPML but different API. Documented workaround in `docs/wpml.md` (v1.0.0+); native support deferred to v1.1+ |
| TranslatePress | ⚠️ | Untested; admin-defined strings may not be picked up |

## Multi-currency

| Plugin | Status | Notes |
|---|---|---|
| WPML WooCommerce Multilingual (WCML) | ⚠️ → ✅ in v1.0.0 (Phase 6) | Primary multi-currency target |
| WCPay Multi-Currency | ⚠️ | Filter-based extension via `dfwc_companion_active_currency` (Phase 6); documented snippet |
| Aelia Currency Switcher | ⚠️ | Same — filter-based |
| WC default base currency | ✅ | Always works as fallback |

## Operating systems / hosting

| Stack | Status | Notes |
|---|---|---|
| Hostinger LiteSpeed | ✅ | User's production environment |
| Apache + PHP-FPM | ⚠️ | Expected to work |
| Nginx + PHP-FPM | ⚠️ | Expected to work |
| Local-by-Flywheel | ⚠️ | Dev environment; expected |
| WP Engine | ⚠️ | Aggressive object cache may need flush after save |
| Pantheon | ⚠️ | Untested |
| Kinsta | ⚠️ | Untested |
| Cloudways | ⚠️ | Untested |

## Browsers (donor-facing overlay JS)

| Browser | Status | Notes |
|---|---|---|
| Chrome 90+ | ✅ | Tested |
| Firefox 88+ | ✅ | Tested |
| Safari 14+ | ⚠️ | Some CSS `:has()` features used; fallbacks present |
| Edge (Chromium) | ✅ | Same as Chrome |
| iOS Safari 14+ | ⚠️ | Should work; not actively tested |
| Android Chrome | ⚠️ | Same |

## Accessibility

| Concern | Status | Notes |
|---|---|---|
| Keyboard navigation | ✅ | Tab/arrow keys cycle through interval tabs; preset buttons are radios |
| Screen reader (NVDA / JAWS) | ⚠️ | ARIA labels present; not actively audited |
| Reduced motion | ⚠️ | Limited animation; respects `prefers-reduced-motion` where animations exist |
| Color contrast | ⚠️ | Varies by theme; companion uses inheritable CSS variables |
| RTL languages (Arabic / Hebrew) | ⚠️ → ✅ in v1.0.0 (Phase 6 + 11) | Logical CSS properties; QA matrix gains RTL test |

## Known incompatibilities (❌)

- **Parent v4.x** without manual contract validation — parent could restructure any of the 17 hashed spans. Self-check warns; manual test required before production use.

## How to read this matrix

A row marked ⚠️ doesn't mean the companion is broken on that combination — it means we haven't actively tested. If you're running a ⚠️ combination and something doesn't work, please [open an issue](https://github.com/f8l124/donations-for-woocommerce-companion/issues) with your stack details. Most ⚠️ rows transition to ✅ as users report successful production use.

A row marked ❌ is a known limitation we won't fix without specific work documented in the relevant phase plan.
