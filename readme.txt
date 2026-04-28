=== Donations for WooCommerce Companion ===
Contributors: davidstells
Tags: woocommerce, donations, recurring, subscriptions, fundraising
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an interval-first donation form (one-time / monthly / annually) on top of the Donation for WooCommerce plugin, without modifying it.

== Description ==

This is a *companion* plugin for [Donation for WooCommerce](https://wordpress.org/plugins/donation-for-woocommerce/). It adds the modern donor-facing form pattern — three side-by-side options for **One-time**, **Monthly**, and **Annually** with separate preset amount tiers per interval — that nonprofits like Charity:Water, NPR, and Wikipedia have made the standard for donation pages.

The companion plugin works by feeding the parent plugin's existing AJAX pipeline with the right parameters, so all downstream wiring (cart, order, subscription, emails, reports, PDF receipts) keeps working untouched. Recurring billing is handled by the parent plugin's existing integration with either WooCommerce Subscriptions (paid) or Subscriptions For WooCommerce by WPS (free) — the companion auto-detects which is active.

= Key features =

* Per-campaign admin meta box: independently configure preset amounts, custom-amount min/max, and CTA text for each interval
* Donor-facing interval-first form via three integration points — shortcode, Gutenberg block, OR Elementor widget — all calling the same renderer for consistent behavior
* Auto-detects WooCommerce Subscriptions vs Subscriptions For WooCommerce; sends both engines' AJAX key sets in one request so it works no matter which is active
* Graceful degradation: when no recurring engine is installed, monthly/annual tabs are visibly disabled, one-time still works
* Optional override of the parent plugin's single-campaign template via opt-in per-campaign meta
* HPOS-compatible and WooCommerce Cart/Checkout Block-compatible
* Self-check probe surfaces an admin notice if a parent plugin update breaks the integration contract
* Production security baseline: nonces on all forms, capability gates on admin, output escaping, server-side amount range enforcement, no direct SQL, no remote runtime fetches

== Installation ==

1. Install and activate the [Donation for WooCommerce](https://wordpress.org/plugins/donation-for-woocommerce/) plugin (required parent).
2. Install and activate either [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) or [Subscriptions For WooCommerce](https://wordpress.org/plugins/subscriptions-for-woocommerce/). Either is fine — the companion auto-detects.
3. Install and activate this companion plugin.
4. Edit a donation campaign and configure the **"Interval-First Donation Form"** meta box: enable the intervals you want, set preset amounts, set min/max for custom amounts.
5. Add the form to a page using one of:
   * Shortcode: `[dfwc_recurring_donation campaign_id="123"]`
   * Gutenberg block: search for **"Recurring Donation"** in the block inserter
   * Elementor widget: search for **"Recurring Donation"** in the Elementor widget panel

== Frequently Asked Questions ==

= Do I need to buy WooCommerce Subscriptions? =

No. The free **Subscriptions For WooCommerce** plugin by WPS works fully. The companion auto-detects which engine you have and feeds the right AJAX parameters in either case.

= Will this plugin break when the parent plugin updates? =

The companion only attaches to documented action and filter hooks the parent plugin already exposes (47+ of them). A built-in self-check runs on every admin page load and shows you a notice if any parent plugin update changes a contract the companion depends on. A CI watcher in the project's GitHub Actions runs weekly to catch parent updates the moment they ship.

= Why does the recurring donation become a one-time charge sometimes? =

The most common cause: the WooCommerce product the parent plugin links to your campaign isn't configured as a subscription product. The meta box shows you a yellow warning when this happens. Fix: edit the linked product via the parent plugin's product editor and mark it as a subscription product.

= Can I use this on a campaign that the parent's "Recurring" setting has set to "Disabled"? =

When you enable Monthly or Annually here, the companion automatically sets the parent plugin's "Recurring" setting on that campaign to **"User chooses"**. This is required for the parent's AJAX handler to actually process recurring submits. The "Companion: Form Mode" side meta box explains this.

= What happens to my data if I deactivate the companion? =

Companion data is stored as post meta (`_dfwc_companion_intervals`, `_dfwc_companion_form_mode`) on your campaign posts. It's left intact if you deactivate the plugin. If you reactivate later, your config returns; if you uninstall, the parent plugin's behavior reverts to its default form.

= Does this work with Stripe? =

Yes, as long as your subscription engine works with Stripe. WooCommerce Subscriptions works with the official WooCommerce Stripe gateway out of the box. Subscriptions For WooCommerce works with WPS's bundled Stripe addon. The companion does not configure Stripe directly.

== Compatibility ==

* PHP 7.4+ (8.3 tested)
* WordPress 6.2+ (6.7 tested)
* WooCommerce 5.0+ (10.2 tested)
* Donation for WooCommerce 3.9.8+
* Compatible with HPOS (High-Performance Order Storage)
* Compatible with the WooCommerce Cart/Checkout Block

== Privacy ==

This plugin stores no donor data. Configuration data (interval presets, etc.) is saved as post meta on `wc-donation` campaign posts. Donation orders, payment records, and donor information are handled by the parent plugin and your subscription engine.

== Known limitations ==

* Companion does NOT support the parent's "fixed admin recurring" mode. Interval-first UX is donor-choice by design. Admins who want fixed recurring should keep using the parent plugin's own form on those campaigns.
* Per-currency preset amounts not supported in v1; presets render in the store base currency.
* The optional template-replacement mode uses an output-buffering pattern around the parent's documented action hooks (because the parent doesn't expose a "skip default form" filter). The CI watcher monitors this for breakage.

== Changelog ==

= 0.7.0 =
Headline release. Solves the admin-scale problem: a nonprofit with 50+ campaigns can now configure once and apply to many at a time.

* **New: layered config model.** Campaigns now resolve config through four layers: plugin defaults → global settings → named template → campaign overrides. Sequential arrays (presets) replace wholesale; associative arrays deep-merge. Detached campaigns get a frozen snapshot and ignore future template changes.
* **New: named templates.** Create reusable configuration packages ("School Sponsorship", "Emergency Relief", "General Fund") at *WooCommerce → Donations Companion → Templates*. Each template defines preset amounts, min/max, CTA template, custom-amount toggle, and display options for all three intervals.
* **New: bulk apply.** Three new bulk actions on the wc-donation campaign list: "Apply template: <name>" (one entry per template), "Reset Companion settings", "Detach from template". Per-campaign capability check; surfaces applied/skipped counts via dismissible admin notice.
* **New: global settings.** *WooCommerce → Donations Companion → Settings* lets admins set a default template applied to new campaigns and toggle data preservation on plugin uninstall.
* **New: override-aware save.** When you save a campaign with a template assigned, only the fields you actually changed land in the override meta. If you don't change anything, future template edits propagate normally — honest inheritance.
* **New: parent-contract diagnostics page.** *WooCommerce → Donations Companion → Diagnostics* surfaces 12 health checks (WC + parent + engine + WPML + asset registration) with status pills, suggested remediations, and a copy-paste-ready Markdown support report (path-stripped, pipe-escaped, allow-listed context).
* **New: frontend gating.** When the contract is broken (parent inactive, AJAX action missing, etc.), the donor-side overlay falls back to parent's vanilla form rather than producing a half-broken UI. Override via `dfwc_companion_contract_skip_augment` filter for edge cases.
* **New: WPML integration.** Comprehensive coverage per `plans/v2/AA-wpml-integration.md`. `wpml-config.xml` declares all custom fields and admin-text strings. Templates and campaign overrides register translatable strings with WPML String Translation under stable keys (`<template_id>.<interval>.<field>`). Strings translate at render time. Monolingual sites pay zero overhead.
* **New: maintenance branch.** v0.6.x lives on its own branch for hotfix backports without v0.7.x contamination.
* **New: CI hardening.** Composer + WPCS + PHPStan (level 5) + PHPUnit + Plugin Check + parent-contract watcher + Playwright + zip-install-validation, all gated on PRs and main pushes. Release workflow auto-publishes the zip on tag push, with version-consistency check across the four canonical sources.
* **New: CONTRIBUTING.md** documenting local setup, running checks, code style, commit messages, PR flow, security reporting.
* **New: comprehensive architecture docs** at `docs/architecture/` covering current state, parent contract reference, and compatibility matrix.
* **Refactor:** `Self_Check` is now a thin admin-notice surface that delegates to `Parent_Form_Contract_Checker`. One source of truth, one cache, no drift between notice copy and Diagnostics page copy.
* **Refactor:** `Config_Resolver` moved from `DFWC\Companion\Config_Resolver` to `DFWC\Companion\Config\Config_Resolver` (sub-namespace). Public surface preserved.
* **Test coverage:** 37 → 98 unit tests, 100 → 245 assertions. New test bootstrap with real-but-minimal filter/action/transient implementation.
* **Backward compat:** existing v0.6.x campaigns continue to render exactly as before. Legacy `_dfwc_companion_intervals` and `_dfwc_companion_display` meta is read by the resolver as a fallback layer; on the next admin save in v0.7.0+, the legacy keys migrate to `_dfwc_companion_overrides` automatically.

= 0.6.6 =
* Fix: per-interval "Allow donors to enter a custom amount" toggle was re-enabling itself on every save when unchecked. The save-side sanitizer used `isset()`-with-default-`true` to derive the boolean, but HTML form-checkbox semantics send nothing in `$_POST` for an unchecked checkbox — so `isset()` was false and the default-`true` branch fired. Switched to `! empty()` (missing = false), matching the pattern used for the main "Offer X donations" enable checkbox in the same method.

= 0.6.5 =
* Fix: definitive resolution of the "linked product is not configured as a subscription" warning that 0.6.1–0.6.4 chased through five increasingly defensive layers. Root cause was structural, not a race: the parent plugin's save handler at `class-wcdonationcampaignsetting.php:1739-1744` reads `wc-donation-recurring` from post meta and writes `_wps_sfw_product` / `_wps_sfw_users` based on that value. Our 0.2.0 admin tab injector hid parent's "Display Type" select via `display:none`, so its default value `'disabled'` was POSTing → parent wrote `_wps_sfw_product='no'` on every save → our subsequent override fought the wrong battle.
* The fix flips the strategy: don't hide parent's recurring control — auto-sync it. The admin tab injector now keeps `<select name="wc-donation-recurring">` visible inside the relocated tab, watches our monthly/annual enable checkboxes, and forces the select to `'user'` (then disables it for visual feedback). Parent's own save handler now writes `_wps_sfw_product='yes'` naturally, with no override needed.
* Removed the v0.6.4 defensive hooks: `save_post_wc-donation` priority 9999 and `woocommerce_process_product_meta` priority 99. They fought a race that was actually a missing $_POST value, and added 60+ lines of fragile last-write-wins code.
* Removed the v0.6.3 `error_log` diagnostic in `auto_configure_product_subscription`. No longer needed — parent's natural flow handles the marker meta. Method retained for the WPS SFW cadence meta + WCS product_type taxonomy term, both of which parent does not manage on its own.
* UX: when monthly or annual is enabled on a campaign, the admin sees parent's "Display Type" select set to `'User chooses'` and disabled, with an italic "Auto-managed by Donations for WooCommerce Companion. Disable Monthly and Annually above to unlock." hint below it. Single source of truth, fully transparent.

= 0.6.4 =
* Fix: subscription-product meta now wins the last-write race against WPS SFW's default. Previously, our save handler wrote `_wps_sfw_product='yes'` correctly, but the parent plugin's `class-wcdonationsetting` save handler then called `wp_update_post` on the linked product later in the same save flow (`class-wcdonationsetting.php:681`). That nested update triggered `woocommerce_process_product_meta`, which fires WPS SFW's handler at priority 10. WPS SFW reads `$_POST['_wps_sfw_product']` — but we're saving a campaign, not a product, so the field is absent and the handler defaults to writing `'no'`. Last write wins; our `'yes'` got clobbered.
* Two new hooks defend against that race:
  - `save_post_wc-donation` at priority 9999 runs after every priority-10 handler completes, then re-asserts our subscription meta one final time.
  - `woocommerce_process_product_meta` at priority 99 catches any product save where WC processes the product (including parent's nested `wp_update_post`) and re-writes our values immediately after WPS SFW's default-`'no'`.
* Both hooks gate on `Config_Resolver::is_configured()` + recurring enabled, so non-companion products are unaffected.

= 0.6.3 =
* Diagnostic: warning text in the meta box now includes the actual values read for the WPS SFW subscription markers (`_wps_sfw_product`, `_wps_sfw_users`) so we can see at a glance whether auto-config wrote them or not.
* Belt-and-suspenders: auto-config now ALSO calls `update_post_meta` directly after `wps_sfw_update_meta_data`, in case the helper takes an unexpected path on some site configs. WP dedupes same-value writes; harmless redundancy.
* When `WP_DEBUG_LOG` is enabled, auto-config logs a single line to `wp-content/debug.log` after each save with the product ID, campaign ID, period, and read-back values. Lets us verify auto-config actually ran (vs. a save handler short-circuit) without exposing internals to end users.

= 0.6.2 =
* Fix: 0.6.1's product auto-config wrote the wrong meta key. Inspecting the WPS SFW source confirms the engine's "this is a subscription product" marker is `_wps_sfw_product='yes'`, not `_wps_sfw_users='user'` — that latter key is the parent plugin's internal tracking, used in `class-wcdonationorder.php:1601` to decide which subscription POST keys to read. Both 0.6.1's auto-config and the warning detection were checking parent's internal key. Result: product never got recognized by the subscription engine; warning persisted; recurring donations silently downgraded to one-time despite the "fix" in 0.6.1.
  - Auto-config now writes `_wps_sfw_product='yes'` (engine marker) AND keeps `_wps_sfw_users='user'` (parent compat) plus the `wps_sfw_subscription_*` cadence meta.
  - Warning detection now checks `_wps_sfw_product='yes'` so it clears correctly after the next save.
* Internal: new `tests/smoke-product-config.php` smoke harness invokes the private auto-config method via Reflection and asserts the written meta. Confirms in wp-env that `_wps_sfw_product=yes`, `_wps_sfw_users=user`, `interval=month`, `number=1` all land on the linked product.

= 0.6.1 =
* Fix: when monthly or annual is enabled on a campaign, the linked WC product is now auto-configured as a subscription product. Previously, parent's "Recurring Donations" tab held the controls that did this — but v0.2.0's tab-injector hid those controls, leaving admins with no UI path to configure the product. Donations would silently downgrade to one-time charges because the subscription engine looks at the product's own subscription configuration, not just the POST data.
  - WPS SFW path: writes `_wps_sfw_users='user'` plus `wps_sfw_subscription_number/interval/expiry_*` meta on the product.
  - WCS path: sets the product type taxonomy to `subscription` and seeds `_subscription_period/_period_interval/_length` meta.
  - No-op when no recurring engine is installed.
* As a result, the "linked product is not configured as a subscription product" warning in the meta box now goes away after the first save with monthly or annual enabled.

= 0.6.0 =
* New: per-interval "Allow donors to enter a custom amount" toggle (defaults checked). Admins can now force preset-only amount selection on monthly while still allowing free entry on one-time, etc. The min/max range still applies for preset-amount validation when custom is off.
* New: Display Options fieldset in the meta box with three controls — "Show campaign title", "Show campaign image" (both default checked, preserve existing behavior), and a "Cause section heading" text field (leave blank for parent's default "Select Cause" text).
* New: `_dfwc_companion_display` post meta key stores the display options as a separate concern from per-interval config.
* Internal: overlay JS reads a new `data-display` attribute carrying display options as JSON; on init, it hides parent's `.campaign-title` / `.block-campaign-thumbnail` per admin choice and replaces parent's `.row2 h3.wc-donation-title` text via `textContent` (XSS-safe) when a custom heading is set.
* Internal: parent-contract baseline grows from 14 to 16 locked line ranges. New entries: parent's title+image render block at `frontend-order-donation.php:400-420` and the entire cause block at `frontend-donation-cause-disp.php:1-24`. Watcher fails CI if either restructures.

= 0.5.1 =
* Fix: overlay UI now inserts at parent's amount-block position (replacing the `.row1` block in place) instead of being prepended above the entire form. Parent's "Frontend Ordering" admin choice (Cause → Amount → Subscription → Tribute → …) is now respected: wherever the admin placed "Donation Amount" in the sequence, our interval-first UI takes that spot. Cause selector, image, title, and other parent blocks now appear in their admin-configured order around the overlay instead of after it.

= 0.5.0 =
* New: auto-augmentation across all six render paths the parent plugin uses — single-campaign permalink, parent's `[wc_woo_donation]` shortcode, donation widget, donation-on-cart, donation-on-cart-block, and donation-on-checkout. Previously only our `[dfwc_recurring_donation]` shortcode/block/widget triggered augmentation; now any place parent renders a donation form will get the interval-first overlay automatically (when the campaign has companion config saved).
* New: per-campaign + per-context opt-out filter `dfwc_should_augment_parent_form` (signature: `apply_filters( 'dfwc_should_augment_parent_form', bool $augment, int $campaign_id, string $context )` where context is one of single/shortcode/widget/checkout/cart/cart_block).
* New: gating — campaigns without saved companion config render parent's vanilla form unchanged. Augmentation only kicks in for campaigns the admin explicitly opted into via the meta box.
* New: i18n — `languages/dfwc-companion.pot` template ships with the plugin; ~71 translatable strings.
* Internal: `Frontend\Context_Augmenter` is the new auto-augmentation hook handler. Re-entrancy-safe: when our `Renderer::render()` is wrapping (driven from `[dfwc_recurring_donation]`), the augmenter checks `Renderer::is_inside_render()` and skips its own wrapping to avoid double-wrapping.
* Internal: `Renderer` exposes `build_overlay_attributes()` and `wrap_with_overlay()` so the same overlay payload shape is used by all render paths.

**Upgrade action:** if you previously embedded `[dfwc_recurring_donation]` directly in a campaign's post content because the auto-render didn't augment, you can remove it now — parent's auto-render on the campaign permalink will be augmented automatically. Leaving the shortcode in place will produce two augmented forms on the same page; remove one.

= 0.4.0 =
* Architectural refactor: companion now AUGMENTS the parent's donation form instead of replacing it. Cause selector, campaign image, processing fee, gift aid, tributes, e-card recipient, and donor-wall integration that the parent plugin renders are now visible alongside our interval-first tabs.
* Implementation: parent plugin renders its full form via its existing render path; on every page that contains our shortcode/block/Elementor widget, a tiny vanilla-JS overlay (`assets/js/dfwc-overlay.js`) finds parent's amount block and recurring controls in the DOM, hides them, and mounts our 3-tab interval UI in their place. As the donor changes our tabs/presets/custom amount, the overlay writes to parent's existing hidden inputs. Parent's existing submit handler runs unchanged — the AJAX call to `donation_to_order` happens through parent's tested pipeline.
* Removed: `Form_Replacer` class and its `wc_donation_before/after_*_add_donation` ob_start/ob_get_clean wrapping. The 0.3.0 `dfwc_should_replace_parent_form` filter is gone.
* Removed: legacy `_dfwc_companion_form_mode` post meta read path + the `META_KEY_FORM_MODE` / `FORM_MODE_*` constants in `Config_Resolver`. The post meta on legacy campaigns is left in the DB unread (allows rolling back to 0.3.0 if needed).
* Removed: deleted templates/recurring-donation-form.php, assets/js/dfwc-form.js, assets/css/dfwc-form.css. Replaced by the overlay JS+CSS at assets/{js,css}/dfwc-overlay.{js,css}.
* New: `tests/parent-contract.baseline.json` covers six additional file/line ranges in the parent — every selector the overlay depends on is locked. CI's parent-contract watcher fails loudly if the parent restructures any of: `.wc-donation-in-action` wrapper, `.row1` amount block, hidden price inputs, recurring checkbox, `_subscription_*` selects, WPS SFW fields, or the submit handler at `assets/js/frontend.js:470`.
* Block editor preview short-circuit: the Gutenberg block renders a placeholder card in the editor instead of triggering parent's frontend-only render path through `ServerSideRender`.
* Power-user opt-out: don't enqueue the overlay assets (filter `wp_enqueue_scripts` to dequeue `dfwc-overlay`) on pages where you want parent's form unmodified. Per-page granularity instead of the per-campaign toggle 0.3.0 had.

**Upgrade action required:** if you put `[dfwc_recurring_donation campaign_id="..."]` directly in a campaign's post content during 0.1.x–0.3.x, leave it there — it now produces the augmented form. Don't ALSO place `[wc_woo_donation]` on the same page; you'd get two forms.

= 0.3.0 =
* Improvement: parent plugin's donation form is now ALWAYS replaced by the interval-first form on every render path (single-campaign permalink, [wc_woo_donation] shortcode, widget, and WC checkout context). The previous per-campaign opt-in caused dual-form display when sites upgraded from 0.1.x — eliminated.
* Improvement: removed the "Companion: Form Mode" side meta box. Power users who specifically need to keep parent's form on certain campaigns can opt out via the new `dfwc_should_replace_parent_form` filter (signature: `apply_filters( 'dfwc_should_replace_parent_form', bool $replace, int $campaign_id )`).
* Improvement: Form_Replacer now wraps four parent render contexts (was two): single, shortcode, widget, checkout. Donor sees the same interval-first UX wherever parent would have rendered its form.
* Fix: legacy campaigns saved under 0.1.x with `_dfwc_companion_form_mode = shortcode_only` are no longer respected — the meta is left in the DB but ignored. Eliminates the "I upgraded but still see two forms" trap.

= 0.2.0 =
* Improvement: companion config UI is now relocated into the parent plugin's "Recurring Donations" tab via a small JS injector — admins see one unified place to configure recurring intervals instead of the two separate UIs in 0.1.x.
* Improvement: parent plugin's "Display Type" / "Recurring Text" / WPS-SFW interval controls inside the Recurring Donations tab are auto-hidden when the companion is configuring recurring intervals (avoids conflicting/duplicated controls).
* Improvement: form-mode default flipped from `shortcode_only` to `replace`. When you embed the companion's form via shortcode/block/widget, the parent plugin's default form on the same campaign's permalink page is now suppressed automatically. Existing campaigns that were explicitly set to `shortcode_only` keep that setting; only campaigns that never set a value get the new default.
* Improvement: side meta box "Form Mode" copy rewritten — both options shown clearly with a descriptive paragraph.
* Improvement: graceful fallback — if the parent plugin restructures its tab DOM in a future release, the JS injector logs a warning to console and leaves the companion meta box at its default location instead of breaking the page.
* Internal: smoke-test harness in `tests/smoke-save.php` validates class loading, engine detection, Renderer output (incl. the wp_unique_id replacement), and Meta_Box::save() invocation against a real wp-env instance.

= 0.1.1 =
* Fix: critical "Call to undefined function wp_doing_autosave()" fatal on campaign update — a hallucinated helper that doesn't exist in WordPress. Replaced with the canonical `defined('DOING_AUTOSAVE') && DOING_AUTOSAVE` check.
* Fix: replaced `wp_doing_ajax()` calls with the `DOING_AJAX` constant for consistency.
* Fix: replaced `wp_unique_id()` (WP 6.4+) with a self-contained UID generator so the plugin works on the declared minimum WP 6.2.

= 0.1.0 =
* Initial release.
* Per-campaign admin meta box for interval-first config.
* Donor-facing form via shortcode, Gutenberg block, and Elementor widget.
* Server-side amount-range guard.
* Self-check health probe + parent-contract CI watcher.
* HPOS + Cart Block compatibility declared.

== Upgrade Notice ==

= 0.7.0 =
Headline release: named templates + bulk apply for nonprofits running many campaigns. New Diagnostics page surfaces parent-plugin compatibility status with a copy-paste support report. Comprehensive WPML integration via wpml-config.xml + String Translation registration. Existing v0.6.x campaigns continue to work unchanged; legacy meta migrates to the new schema on next admin save. CI now runs PHPCS + PHPStan + Plugin Check on every PR; release zip auto-publishes on tag.

= 0.6.6 =
Fixes the per-interval "Allow custom amount" checkbox silently re-enabling itself every save. Unchecking and saving now correctly persists as off.

= 0.6.5 =
Definitive fix for the "linked product is not configured as a subscription" warning. Drops the v0.6.4 defensive hook stack; instead, the admin tab injector now keeps parent's "Display Type" select visible (locked to "User chooses" while monthly/annual are enabled), so parent's own save handler writes the WPS SFW marker meta correctly. Save any campaign once and the warning clears.

= 0.6.4 =
The subscription-product warning now resolves correctly after one save. Adds two late-running hooks that re-assert our `_wps_sfw_product='yes'` write after parent's nested `wp_update_post` triggers WPS SFW's default-`'no'` overwrite.

= 0.6.3 =
Diagnostic release for the persisting "linked product is not configured" warning. Warning now includes the actual meta read so we can see why detection thinks the product isn't a subscription.

= 0.6.2 =
Hotfix for 0.6.1: product auto-config now writes the correct WPS SFW marker meta key (`_wps_sfw_product=yes`) so the subscription engine actually recognizes the linked product. If you were on 0.6.1 and the warning persisted, save the campaign once on 0.6.2 and the warning clears.

= 0.6.1 =
Fixes the "linked product is not a subscription" warning by auto-configuring the linked WC product as a subscription product when you enable monthly or annual on a campaign. No admin action needed — just save the campaign once on 0.6.1.

= 0.6.0 =
Adds per-interval custom-amount toggle, campaign title/image show-hide, and cause heading customization in a new Display Options section of the meta box. All defaults preserve existing behavior — safe to upgrade.

= 0.5.1 =
Fixes overlay placement: the interval-first UI now drops into the parent plugin's amount-block position (respecting the admin's Frontend Ordering setting). Cause selector and other blocks now render in their configured positions around the overlay.

= 0.5.0 =
Auto-augmentation now extends to all six contexts where parent renders a donation form (cart, checkout, widget, etc.) — previously only our shortcode/block triggered it. Includes a `.pot` translation template. If you put `[dfwc_recurring_donation]` directly in a campaign's post content as a workaround in 0.4.x, you can remove it.

= 0.4.0 =
Major architectural refactor: companion now augments parent's form instead of replacing it. Cause selector, gift aid, processing fee, tributes, and other parent-rendered fields are now visible alongside our interval tabs. Safe to upgrade — overlay falls back gracefully (donor sees parent's unmodified form) if any required parent DOM element is missing.

= 0.3.0 =
Replacement of the parent plugin's donation form is now unconditional across single-campaign pages, shortcodes, widgets, and checkout contexts. Removes the form-mode opt-in that caused dual-form display on upgrade. If you need parent's form preserved on specific campaigns, use the new `dfwc_should_replace_parent_form` filter.

= 0.2.0 =
Major UX improvement: companion config moves into the parent plugin's Recurring Donations tab; default form mode flipped to "Replace" so you no longer see two donation forms on the same page. Safe to upgrade.

= 0.1.1 =
Critical fatal-error fix. Upgrade required if you've installed 0.1.0.

= 0.1.0 =
Initial release.
