=== Donations for WooCommerce Companion ===
Contributors: davidstells
Tags: woocommerce, donations, recurring, subscriptions, fundraising
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.6.0
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
