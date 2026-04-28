# Architecture — Current State (v1.1.0)

> **Audience:** new contributors, the WCDonation team if they review for upstream integration, and future-self after a long break from the code.
> **Updated:** 2026-04-28, post-v1.1.0 release.

This document describes the current architectural surface. Sections 1–10 cover the v0.6.6 baseline (the core augmentation pattern that hasn't changed). Section 11 summarizes what the v0.7.0 → v1.1.0 work added on top.

---

## 1. What this plugin does

Donations for WooCommerce Companion adds an interval-first donation form (One-time / Monthly / Annually with separate preset amounts per interval — the Charity:Water / NPR / Wikipedia pattern) on top of [Donation for WooCommerce](https://woocommerce.com/products/donations-for-woocommerce/) by WPExperts.

The companion **augments** the parent plugin instead of replacing it. Parent's full donation form (cause selector, gift aid, processing fee, tributes, e-card, donor-wall) renders unmodified; the companion overlays a 3-tab interval UI on top of parent's amount + recurring controls and writes through to parent's existing hidden inputs at submit time. Parent's `donation_to_order` AJAX pipeline runs unchanged — cart, order, subscription, email receipt, gateway interaction are 100% parent's responsibility.

The companion modifies **zero lines** of the parent plugin. Everything is built on parent's documented action/filter surface plus DOM mutation scoped to `.wc-donation-in-action` on the frontend.

## 2. High-level structure

```
donations-for-woocommerce-companion/
├── donations-for-woocommerce-companion.php    Main plugin file: headers, constants, autoloader, bootstrap
├── wpml-config.xml                             WPML compatibility declaration (Phase 0 deliverable)
├── readme.txt                                  WordPress.org-style readme
├── package.json                                Dev dependencies (Playwright, wp-env)
├── playwright.config.ts                        E2E test config
├── includes/
│   ├── Autoloader.php                          Hand-rolled PSR-4-ish autoloader for DFWC\Companion
│   ├── Plugin.php                              Singleton bootstrap; instantiates submodules
│   ├── Engine_Detector.php                     Detect WCS / WPS SFW / none
│   ├── Config_Resolver.php                     Read per-campaign meta; infer defaults
│   ├── Admin/
│   │   ├── Meta_Box.php                        Per-campaign config UI + save handler
│   │   ├── Assets.php                          Conditional admin asset enqueue
│   │   └── Self_Check.php                      Cached health probes; admin notices
│   └── Frontend/
│       ├── Renderer.php                        Wraps parent shortcode output with overlay marker
│       ├── Shortcode.php                       [dfwc_recurring_donation campaign_id="N"]
│       ├── Block.php                           Server-rendered Gutenberg block
│       ├── Elementor_Adapter.php               Conditional Elementor registration
│       ├── Elementor_Widget.php                Elementor widget delegating to Renderer
│       ├── Context_Augmenter.php               Auto-augments parent's renders in 6 contexts
│       ├── Submit_Guard.php                    Server-side amount range enforcement
│       └── Assets.php                          Frontend asset registration + dfwcCompanion localize
├── templates/
│   └── meta-box-intervals.php                  Admin meta box markup
├── assets/
│   ├── js/
│   │   ├── dfwc-overlay.js                     Donor-side DOM overlay (594 LOC)
│   │   ├── dfwc-admin.js                       Admin meta box behavior
│   │   └── dfwc-admin-tab-injector.js          Relocates meta box into parent's "Recurring" tab; auto-syncs Display Type
│   ├── css/
│   │   ├── dfwc-overlay.css                    Donor-side overlay styles
│   │   └── dfwc-admin.css                      Admin meta box styles
│   └── blocks/
│       └── recurring-donation/                 Gutenberg block source
├── languages/
│   └── dfwc-companion.pot                      Translation template (~79 strings)
└── tests/
    ├── parent-contract.test.php                CI watcher: SHA-256 of 16 parent line ranges
    ├── parent-contract.baseline.json           Locked hashes
    ├── playwright.config.ts                    E2E config (WPS SFW + none fixtures)
    └── smoke-*.php                             Smoke harnesses
```

**Total production code (zip contents):** ~4,100 LOC PHP/JS/CSS, 64 KB zipped.

## 3. Bootstrap flow

`Plugin::boot()` runs on `plugins_loaded` priority 20 (after parent's bootstrap at priority 10). Boot order:

1. **PHP version pre-flight** (in main plugin file before namespace code loads). PHP < 7.4 → admin notice, return. Done before autoloader registers so PHP 7.0 hosts get a clean notice instead of a typed-properties parse error.
2. **Autoloader registration.** Hand-rolled `spl_autoload_register` mapping `DFWC\Companion\*` to `includes/*.php` (Title_Case files).
3. **HPOS + cart-blocks compatibility declaration** on `before_woocommerce_init`. Both declared compatible.
4. **Dependency guards** in `Plugin::boot()`:
   - `defined( 'WC_DONATION_VERSION' )` false → admin notice, return
   - `version_compare( WC_DONATION_VERSION, '3.9.8', '<' )` → warning notice (don't return)
   - `class_exists( 'WooCommerce' )` false → admin notice, return
5. **Engine detection.** `Engine_Detector::detect()` returns `'wcs'` / `'wps_sfw'` / `'none'`.
6. **Submodule wiring.** Instantiates `Admin\Meta_Box`, `Admin\Assets`, `Admin\Self_Check`, `Frontend\Shortcode`, `Frontend\Block`, `Frontend\Assets`, `Frontend\Submit_Guard`, `Frontend\Context_Augmenter`, conditionally `Frontend\Elementor_Adapter`.

`Plugin::boot()` does NOT pre-create empty submodule stubs (a v0.1 anti-pattern, learned-from). Each submodule has real behavior the moment it's instantiated.

## 4. Save flow (admin → DB)

```
Admin clicks "Update" on a wc-donation campaign post
    │
    ▼
parent's classic-editor save runs (priority 10 on save_post_wc-donation)
    │  parent reads $_POST['wc-donation-recurring'] → writes meta
    │  parent reads other fields → writes various meta keys
    │  parent fires `wc_donation_after_save_campaign_meta` action
    ▼
Meta_Box::save() runs (priority 20 on `wc_donation_after_save_campaign_meta`)
    │  also runs (priority 20 on `save_post_wc-donation`) as block-editor fallback
    │
    ├── nonce check (`dfwc_companion_save_meta`, _dfwc_nonce)
    ├── capability check (`current_user_can( 'edit_post', $post_id )`)
    ├── DOING_AUTOSAVE bail
    │
    ├── sanitize_interval_block × 3 intervals (one_time, monthly, annual)
    │       For each: enabled, presets[amount/label], min, max, default_index,
    │       cta_template, custom_amount_enabled
    │
    ├── update_post_meta(_dfwc_companion_intervals, $config)
    │
    ├── If monthly OR annual enabled:
    │     update_post_meta(wc-donation-recurring, 'user')   ← critical for parent's AJAX gate
    │     update_post_meta(_subscription_period, $primary_period)
    │     update_post_meta(_subscription_period_interval, '1')
    │     update_post_meta(_subscription_length, '0')
    │     auto_configure_product_subscription()             ← writes _wps_sfw_product='yes'
    │                                                          + cadence meta on linked WC product
    │
    ├── sanitize_display + update_post_meta(_dfwc_companion_display)
    │
    ▼
Done. Admin redirected back to post edit screen with "Updated" notice.
```

The admin tab injector (JS, runs DOMContentLoaded after page render) keeps parent's `<select name="wc-donation-recurring">` visible inside the relocated tab and auto-sets it to `'user'` whenever monthly or annual is enabled. This ensures `$_POST['wc-donation-recurring']='user'` arrives at parent's save handler so parent's own meta writes are correct (v0.6.5 structural fix).

## 5. Render flow (donor sees form)

Six render contexts trigger augmentation. Each fires a parent action hook before/after rendering:

```
single-campaign permalink   →  wc_donation_before_single_add_donation     /  wc_donation_after_single_add_donation
[wc_woo_donation] shortcode →  wc_donation_before_shortcode_add_donation  /  wc_donation_after_shortcode_add_donation
parent's donation widget    →  wc_donation_before_widget_add_donation     /  wc_donation_after_widget_add_donation
parent's checkout block     →  wc_donation_before_checkout_add_donation   /  wc_donation_after_checkout_add_donation
parent's cart shortcode     →  wc_donation_before_cart_add_donation       /  wc_donation_after_cart_add_donation
parent's cart block         →  wc_donation_before_cart_add_donation_block /  wc_donation_after_cart_add_donation_block
```

Plus three companion-driven contexts: `[dfwc_recurring_donation]` shortcode, the Gutenberg block, the Elementor widget. These delegate to `Renderer::render( $campaign_id )` which calls parent's `[wc_woo_donation]` internally and wraps the output.

```
Donor visits page rendering parent's donation form
    │
    ▼
Parent fires `wc_donation_before_*_add_donation` action
    │
    ▼
Context_Augmenter::emit_open( $context, $campaign_id )
    │
    ├── Renderer::is_inside_render() check (re-entrancy guard)
    │       True if our own Renderer is already wrapping → skip (avoid double-wrap)
    │
    ├── Config_Resolver::is_configured( $campaign_id )
    │       False → skip (don't augment campaigns admin hasn't opted into)
    │
    ├── apply_filters( 'dfwc_should_augment_parent_form', ... )
    │       Lets third parties opt out per campaign × context
    │
    ├── Assets::enqueue() — pull in overlay JS + CSS late
    │
    ├── Renderer::build_overlay_attributes( $campaign_id )
    │       Returns: engine, enabled_intervals, active_interval, form_config, display
    │
    └── echo '<div class="dfwc-overlay" data-dfwc-overlay-target ...>'
            data-config: JSON of form_config
            data-intervals: JSON of enabled_intervals
            data-display: JSON of display
            data-engine: 'wcs' | 'wps_sfw' | 'none'
            data-active-interval: 'one_time' | 'monthly' | 'annual'
            data-campaign-id: integer
            data-context: render context label
    │
    ▼
Parent's render output flushes inside the wrapper (parent's full form HTML)
    │  Includes:
    │    .wc-donation-in-action
    │      ├─ .row1 (amount block — preset selector + custom amount)
    │      ├─ .donation-is-recurring (parent's checkbox; visible only when wc-donation-recurring='user')
    │      ├─ .donation_subscription / .wc_donation_subscription_table (recurring controls)
    │      ├─ hidden inputs: wc-donation-price, wc-donation-cause, .wc_donation_camp, .wp_rand
    │      ├─ cause selector, gift aid, processing fee, tributes, e-card, donor-wall
    │      └─ .wc-donation-f-submit-donation (submit button)
    │
    ▼
Parent fires `wc_donation_after_*_add_donation` action
    │
    ▼
Context_Augmenter::emit_close — echo '</div>'
    │
    ▼
Page response sent to browser
    │
    ▼
dfwc-overlay.js DOMContentLoaded:
    │
    ├── Find every [data-dfwc-overlay-target]
    │
    ├── For each:
    │     │
    │     ├── Locate parent's anchors via querySelector inside .wc-donation-in-action:
    │     │     priceInputs, recurringCheckbox, subPeriod, wpsNumber, submitBtn, etc.
    │     │
    │     ├── Build our 3-tab UI from data-config
    │     │     Renders preset radio grid + custom amount input + tab buttons
    │     │
    │     ├── Insert UI before parent's .row1 (respecting parent's Frontend Ordering)
    │     │
    │     ├── Hide: .row1, .donation_subscription, .wc_donation_subscription_table, .subscription-options
    │     │
    │     ├── Apply display options (hide .campaign-title / .block-campaign-thumbnail, replace cause heading)
    │     │
    │     ├── Bind handlers: tab click, preset radio change, custom amount input
    │     │
    │     └── On state change, write to parent's hidden inputs:
    │           wc-donation-price = state.amount
    │           recurring checkbox = state.interval !== 'one_time'
    │           subPeriod / wpsInterval = 'month' or 'year'
    │           submitBtn.textContent = applied CTA template
    │
    ▼
Donor clicks parent's submit button
    │
    ▼
Parent's frontend.js click handler reads form values, AJAXes to wp-ajax/donation_to_order
    │
    ▼
Parent's AJAX handler (class-wcdonationorder.php:add_donation_to_order_action)
    │  Reads $_POST: amount, campaign_id, is_recurring, period/interval/length keys
    │  At line 1655: gates recurring metadata application on wc-donation-recurring === 'user'
    │  Adds line item to WC cart with amount + (if recurring) subscription metadata
    │
    ▼
Response: { response: 'success', cart_url: '...' }
    │
    ▼
Parent's frontend.js redirects: window.location.href = response.cart_url
    │
    ▼
Donor lands on cart page with their donation as a WC line item
```

`dfwc-overlay.js` never re-implements parent's submit logic. It only mutates parent's existing form state. This is the core of "augment, not replace" — parent's tested AJAX pipeline runs untouched.

## 6. Engine detection

`Engine_Detector::detect()`:

```php
if ( class_exists( '\WC_Subscriptions' ) )                return self::ENGINE_WCS;
if ( class_exists( '\Subscriptions_For_Woocommerce' ) )   return self::ENGINE_WPS;
return self::ENGINE_NONE;
```

WCS preferred when both present (parent's preference per `class-wcdonation.php:171`). The engine flag drives:
- Admin meta box: monthly/annual tabs disabled when `none`
- Donor overlay: `data-engine` attribute; recurring tabs disabled in JS when `none`
- Submit guard: server-side validates recurring submits only when an engine is active
- Auto-configure-product-subscription: writes WCS-style or WPS-SFW-style meta on the linked product

Both engines support the same set of intervals (week/month/year + integer multiples), so the donor-side POST data ships **both** WCS-style (`new_period`, `new_interval`, `new_length`) and WPS-SFW-style (`wps_sfw_subscription_*`) keys for every recurring submit. Parent's AJAX handler reads only the relevant set based on `class_exists`. This avoids gating-by-engine at render time (D2 in the original master plan).

## 7. Self-check

`Admin\Self_Check` runs cached probes on `admin_init`:

- `has_action( 'wp_ajax_donation_to_order' )` — parent's AJAX handler bound
- `defined( 'WC_DONATION_VERSION' )` — parent loaded
- File-size heuristic on parent's hooked files (within ±5% of expected)

Results cached in transient `dfwc_self_check` for 12 hours. Failures surface as transient-backed admin notices on the campaign list and edit screens.

Phase 2 of v0.7.0 expands this into a first-class `Admin\Diagnostics_Page` and refactors `Self_Check` to delegate. See `parent-contract.md` and `plans/v2/03-phase-2-contract-diagnostics.md`.

## 8. Parent contract surface

The companion attaches to 16 specific line ranges in parent v3.9.8 files. Each is hashed in `tests/parent-contract.baseline.json` via SHA-256. CI fails when any range changes. See `parent-contract.md` for the full table.

The most important entries:

| Parent file | Lines | What we depend on |
|---|---|---|
| `class-wcdonationorder.php` | 1540-1820 | AJAX handler shape (POST keys, response shape) |
| `class-wcdonationorder.php` | 1655 | The `'user'` gate that downgrades to one-time when meta wrong |
| `class-wcdonationcampaignsetting.php` | 1739-1744 | `_wps_sfw_product` written from `wc-donation-recurring` POST |
| `class-wcdonationcampaignsetting.php` | 614-628 | Single-form render hook firing |
| `class-wcdonationcampaignsetting.php` | 920-935 | Shortcode render hook firing |
| `frontend-donation-amount-disp.php` | (whole file) | `.row1` template structure we hide |
| `frontend-donation-subscription-disp.php` | (whole file) | Recurring controls we hide |

## 9. WPML status

`wpml-config.xml` ships at the plugin root declaring our custom-field meta keys with `action="copy"` (structurally identical across translations). v0.7.0+ extends this file with admin-text option keys and taxonomy declarations as those features land.

The `WPML_Strings` helper (Phase 3 deliverable) wraps WPML's String Translation API with a no-op fallback for monolingual sites. Every admin-defined string registered on save and translated on render.

Multi-currency is Phase 6 territory — `Currency_Preset_Resolver` consults WCML at render time, falls back to WC base currency when WCML inactive.

See `plans/v2/AA-wpml-integration.md` for the full integration spec and per-phase touchpoints.

## 10. Known limitations (as of v1.1.0)

- **PHP 7.4 minimum.** Some hosts on 7.0–7.3 cannot install. Documented in readme; clean activation notice on lower versions.
- **Single subscription engine at a time.** WCS preferred over WPS SFW when both active.
- **Parent v4.x untested.** Self-check warns on major bump.
- **WPML untested in CI.** Optional fixture gated on `DFWC_WPML_ZIP_URL` repo secret. Manual QA only.
- **Plugin Check skipped in CI** when `DFWC_PARENT_ZIP_URL` secret is unset (the parent plugin is paid; not on wp.org).
- **No automated screenshots for wp.org submission** — manual capture required.

## 11. v0.7.0 → v1.1.0 surface (the v2 plan)

The architecture sections above describe the v0.6.6 baseline — the core augment-don't-replace pattern that hasn't changed. The v0.7.0 → v1.1.0 work added on top:

### 11.1 Layered config model (Phase 3, v0.7.0)

`Config_Resolver` moved from a single-meta-key reader to a four-layer resolver:

```
Defaults  →  Global settings  →  Named template  →  Campaign overrides
```

Sequential arrays (presets) replace wholesale; associative arrays deep-merge. **Detached** campaigns get a frozen snapshot and ignore future template changes. Backward compat: legacy `_dfwc_companion_intervals` meta is read as a fallback when no template + no overrides exist; first save in v0.7+ migrates to `_dfwc_companion_overrides` automatically.

New modules:

- `Config\Defaults` — canonical default factory.
- `Config\Template_Config` — value object for one named template.
- `Config\Template_Repository` — read/write the templates option, register WPML strings.
- `Config\Campaign_Config_Repository` — per-campaign meta read/write, override-delta computation, template apply/detach/reset.
- `Config\Migration_Service` — idempotent schema migrations tracked via `dfwc_companion_schema_version`.

New admin surface: **Donations Companion** parent menu under `WooCommerce` with three submenus — Settings, Templates (list + edit), Diagnostics.

### 11.2 Parent-contract diagnostics (Phase 2, v0.7.0)

`Parent_Form_Contract_Checker` runs ~13 health checks (WC + parent + engine + WPML + asset registration) and produces a `Parent_Form_Contract_Report` with per-check results. Cached 12h via transient. Surfaced via:

- `Admin\Diagnostics_Page` — full result grid + copy-paste-ready Markdown support report.
- `Admin\Self_Check` — refactored to a thin admin-notice surface that delegates to the checker.
- `Frontend\Context_Augmenter` — skips augmentation when the contract is broken (donor sees parent's vanilla form rather than a half-broken UI). Override via `dfwc_companion_contract_skip_augment` filter.

### 11.3 Campaign taxonomies + directory grid (Phase 4, v0.8.0)

Six taxonomies on `wc-donation`: `dfwc_cause`, `dfwc_region`, `dfwc_country`, `dfwc_program`, `dfwc_sponsorship_type`, `dfwc_urgency`. Default starter terms seeded once via `Taxonomy\Campaign_Taxonomies::SEEDED_OPTION` flag.

Donor-facing directory grid via `[dfwc_campaign_grid]` shortcode + `dfwc-companion/campaign-grid` Gutenberg block + Elementor "Donation Campaign Grid" widget. REST live search at `GET /wp-json/dfwc-companion/v1/grid` with 60-req/min/IP rate limit.

### 11.4 Donor impact messaging (Phase 5, v0.9.0)

Per-preset `impact_label` (free-form text translatable via WPML) with four display modes (inline / below_button / tooltip / card). Single `is_featured` preset per interval enforced server-side. Per-interval `subtitle`, `annual_equivalency` (with `{amount}` / `{annual_amount}` token substitution), and `custom_amount_impact_label`.

### 11.5 Per-currency presets (Phase 6, v1.0.0)

`Config\Currency_Preset_Resolver` — render-time second pass on top of `Config_Resolver`. Sparse `currency_overrides` map per interval block. WCML primary, WC base fallback, filter-based extension for WCPay / Aelia. `Frontend\Submit_Guard` re-resolves under active currency before validating min/max.

### 11.6 Advanced intervals (Phase 7, v1.0.0)

Four extra interval keys gated by global toggle: `weekly`, `quarterly`, `semiannual`, `custom`. `Config\Engine_Interval_Map` is the single source of truth for the interval → engine cadence translation. Custom interval admin-defines `custom_period` + `custom_interval` + translatable `custom_label`. Donor-side overflow menu ("More options ▾") when ≥5 intervals enabled.

### 11.7 Live admin preview pane (Phase 8, v0.9.0)

`REST\Preview_REST_Controller` — admin-only POST endpoint, rate-limited to 10 req/sec/user. `Frontend\Preview_Renderer` produces a self-contained HTML snippet with a mock-parent scope so the same overlay JS runs in the iframe as on donor pages. Defense in depth on submit: server-side `Submit_Guard` rejects `dfwc_preview` POST flag; overlay JS sees `data-preview="1"` and disables submit; iframe's mock submit ships with `disabled` attribute.

`Validation\Template_Validator` — centralized config sanitizer used by the preview REST endpoint (and reusable for v0.10+ Templates_Page / Meta_Box deduplication).

### 11.8 Event hooks (Phase 9, v1.1.0)

Six WordPress action hooks at key points in the donor flow:

- `dfwc_companion_form_viewed`, `dfwc_companion_interval_selected`, `dfwc_companion_preset_selected`, `dfwc_companion_custom_amount_entered` — donor-side JS events that batch to a public, rate-limited `track` REST endpoint.
- `dfwc_companion_donation_submitted`, `dfwc_companion_donation_failed` — server-side at the form-submit boundary via parent's `wc_donation_alter_donate_response` filter.

`Analytics\Privacy_Guard` — allow-list sanitizer enforcing aggregate-only data + PII-strip. `REST\Track_REST_Controller` — public, rate-limited to 100 events/IP/min via hashed-IP transients. `Analytics\Submission_Tracker` — hooks parent's response filter for the success/fail events.

### 11.9 Release readiness (Phase 11, v1.0.0)

`uninstall.php` — opt-in cleanup respecting `preserve_data_on_uninstall`. `CLI\CLI_Commands::register` — `wp dfwc-companion health` command. Comprehensive docs suite under `docs/`. GitHub repo polish (issue templates, PR template, SECURITY.md, CODE_OF_CONDUCT.md, README.md).

### 11.10 Updated bootstrap

`Plugin::boot()` now instantiates ~22 submodules vs. the v0.6.6's ~11. Order of instantiation:

1. `Config\Migration_Service::maybe_migrate()` — runs first, idempotent.
2. Admin: `Meta_Box`, `Assets`, `Admin_Menu`, `Settings_Page`, `Templates_Page`, `Bulk_Actions`, `Diagnostics_Page`, `Self_Check`, `Preview_Controller`.
3. Frontend: `Shortcode`, `Block`, `Assets`, `Submit_Guard`, `Context_Augmenter`, `Elementor_Adapter`.
4. Taxonomy: `Campaign_Taxonomies`, `Campaign_Grid_Shortcode`, `Campaign_Grid_Block`, `Directory_Assets`.
5. REST: `Grid_REST_Controller`, `Preview_REST_Controller`, `Track_REST_Controller`.
6. Analytics: `Submission_Tracker`.
7. CLI: `CLI_Commands::register()` (no-op outside WP-CLI).

### 11.11 Updated structure tree (v1.1.0)

```
includes/
├── Autoloader.php
├── Plugin.php
├── Engine_Detector.php
├── Admin/
│   ├── Admin_Menu.php           Parent submenu
│   ├── Assets.php
│   ├── Bulk_Actions.php         Phase 3 — campaign-list bulk apply / detach / reset
│   ├── Diagnostics_Page.php     Phase 2 — health-check report grid
│   ├── Meta_Box.php
│   ├── Preview_Controller.php   Phase 8
│   ├── Self_Check.php           thin admin-notice surface
│   ├── Settings_Page.php        Phase 3 — global settings UI
│   └── Templates_Page.php       Phase 3 — list + edit named templates
├── Analytics/
│   ├── Privacy_Guard.php        Phase 9
│   └── Submission_Tracker.php   Phase 9
├── CLI/
│   └── CLI_Commands.php         Phase 11
├── Config/
│   ├── Campaign_Config_Repository.php   Phase 3
│   ├── Config_Resolver.php
│   ├── Currency_Preset_Resolver.php     Phase 6
│   ├── Defaults.php             Phase 3 — canonical default factory
│   ├── Engine_Interval_Map.php  Phase 7
│   ├── Migration_Service.php    Phase 3 — idempotent migrations
│   ├── Template_Config.php      Phase 3
│   └── Template_Repository.php  Phase 3
├── Contracts/                   Phase 2 — diagnostic checker
│   ├── Parent_Form_Contract.php
│   ├── Parent_Form_Contract_Checker.php
│   ├── Parent_Form_Contract_Report.php
│   └── Parent_Form_Contract_Result.php
├── Frontend/
│   ├── Assets.php
│   ├── Block.php
│   ├── Campaign_Card_Renderer.php           Phase 4
│   ├── Campaign_Directory_Renderer.php      Phase 4
│   ├── Campaign_Grid_Block.php              Phase 4
│   ├── Campaign_Grid_Shortcode.php          Phase 4
│   ├── Context_Augmenter.php
│   ├── Directory_Assets.php                 Phase 4
│   ├── Elementor_Adapter.php
│   ├── Elementor_Campaign_Grid_Widget.php   Phase 4
│   ├── Elementor_Widget.php
│   ├── Preview_Renderer.php                 Phase 8
│   ├── Renderer.php
│   ├── Shortcode.php
│   └── Submit_Guard.php
├── I18n/
│   └── WPML_Strings.php         Phase 3 — WPML String Translation wrapper
├── REST/
│   ├── Grid_REST_Controller.php       Phase 4
│   ├── Preview_REST_Controller.php    Phase 8
│   └── Track_REST_Controller.php      Phase 9
├── Taxonomy/
│   ├── Campaign_Query_Builder.php     Phase 4
│   └── Campaign_Taxonomies.php        Phase 4
└── Validation/
    └── Template_Validator.php         Phase 8
```

**Total production code (zip contents at v1.1.0):** ~10,000 LOC PHP/JS/CSS, ~196 KB zipped.

---

## 12. Where to look for things

| Question | File |
|---|---|
| What hooks does the companion attach to? | `parent-contract.md` |
| What's the donor flow from preset click to cart? | This file, §5 |
| How do I add a new preset field? | `Meta_Box::sanitize_interval_block`, `templates/meta-box-intervals.php`, `Renderer::build_form_config`, `dfwc-overlay.js` preset rendering loop |
| How do I add a new diagnostic check? | Phase 2 deliverable; see `plans/v2/03-phase-2-contract-diagnostics.md` |
| How do I make a string translatable? | `__()` / `esc_html__()` / `esc_attr__()` with text domain `dfwc-companion`; if admin-defined, also register with WPML via `WPML_Strings::register()` (Phase 3+) |
| Why doesn't my filter run? | Check action priority. Parent fires hooks at default priority 10; companion attaches at 1 (early) for context wrappers, 20 (late) for save handlers. |
| How do I run tests? | `composer check` (Phase 1+) or `npm run test:e2e` for Playwright |
