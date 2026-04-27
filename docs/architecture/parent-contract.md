# Parent Contract Reference

> **Audience:** maintainers reviewing parent plugin updates; the WCDonation team if they review for upstream integration.
> **Updated:** 2026-04-27 against parent v3.9.8.
> **Authority:** The CI watcher in `tests/parent-contract.test.php` is the live source of truth. This document describes the contract narratively. When the doc and the watcher disagree, regenerate the watcher's baseline (`php tests/parent-contract.update-baseline.php`), then update this doc.

---

## 1. What is the parent contract?

The companion plugin attaches to **17 specific spans** across the parent Donation for WooCommerce plugin's PHP and JS files. Each span is hashed in `tests/parent-contract.baseline.json` via SHA-256. CI runs the watcher on every PR; mismatches fail the build with a clear "parent restructured X — review companion impact" message.

This document explains *what each span contains* and *why the companion depends on it*, so when CI fails, the maintainer knows where to look in the companion code.

## 2. The 17 spans

### 2.1 AJAX surface — `class-wcdonationorder.php`

#### Lines 1540–1820: `add_donation_to_order_action()`
The full body of parent's AJAX handler for `wp_ajax_donation_to_order` and `wp_ajax_nopriv_donation_to_order`. Reads the POST body, validates the donor's selection, builds a WC cart line item (regular for one-time, subscription line for recurring), returns a JSON response with `cart_url`.

**Companion dependency:** `dfwc-overlay.js` doesn't re-implement this. Instead, the overlay populates parent's hidden inputs and recurring controls, then lets parent's existing submit click handler (`assets/js/frontend.js:351-500`) read those values and AJAX to this handler. If parent ever rewrites this method, the companion's POST data still arrives via the same submit handler — but if parent renames the AJAX action or restructures the response shape, the donor flow breaks.

**What to check on parent update:** `wp_ajax_donation_to_order` action name unchanged; response shape still contains `cart_url`; POST keys still include `amount`, `campaign_id`, `is_recurring`, `new_period`, `new_interval`, `new_length`, `wps_sfw_subscription_*`.

#### Line 1655: the `'user'` gate

```php
$RecurringDisp = get_post_meta( $campaign_id, 'wc-donation-recurring', true );
if ( 'user' === $RecurringDisp ) {
    // apply recurring metadata from POST
}
```

This single line gates whether parent applies recurring metadata. If `wc-donation-recurring` is `'disabled'` or `'enabled'`, parent ignores POST `is_recurring=yes` and silently treats every recurring submit as one-time.

**Companion dependency:** the meta box save handler force-writes `wc-donation-recurring='user'` whenever monthly or annual is enabled (master plan deviation D1). The admin tab injector also keeps parent's "Display Type" select visible in the relocated tab and auto-syncs its value to `'user'` so parent's own save handler writes it correctly (v0.6.5 structural fix).

**What to check on parent update:** the gate condition is unchanged. If parent removes the gate or changes the value `'user'` → something else, the companion needs updating.

### 2.2 Hook surface — `class-wcdonationcampaignsetting.php`

#### Lines 614–630: single-page form render
Parent's render path for the single-campaign permalink. The action `wc_donation_before_single_add_donation` fires before the form renders, `wc_donation_after_single_add_donation` after.

**Companion dependency:** `Frontend\Context_Augmenter` hooks both actions to wrap parent's output with `<div data-dfwc-overlay-target>`. Same selector (`.wc-donation-in-action`) inside.

**What to check on parent update:** the action hooks fire at the same point relative to parent's `<form>` element; the wrapper class `.wc-donation-in-action` is still in scope.

#### Lines 920–935 + 882–944: shortcode render
Parent's `[wc_woo_donation]` shortcode handler. Same actions fire (`wc_donation_before_shortcode_add_donation` / `_after_`). Companion's `Renderer::render()` method calls `do_shortcode( '[wc_woo_donation id="N"]' )` and wraps the result.

**Companion dependency:** shortcode signature accepts `id` attribute; output contains `.wc-donation-in-action`; before/after hooks fire at the same boundary.

#### Lines 1820–1840: `wc_donation_after_save_campaign_meta` action
Parent fires this action at the end of its campaign save flow. The companion's `Meta_Box::save()` hooks here at priority 20 — after parent's save, before the response renders.

**Companion dependency:** this action exists and fires reliably for every save (classic editor + block editor REST). `Meta_Box::save()` also hooks `save_post_wc-donation` at priority 20 as a block-editor fallback.

#### Lines 1739–1744: `_wps_sfw_product` derivation

```php
$RecurringDisp = get_post_meta( $post_id, 'wc-donation-recurring', true );
$wps_sfw_product = 'disabled' === $RecurringDisp ? 'no' : 'yes';
$wps_sfw_user = 'user' === $RecurringDisp ? $RecurringDisp : 'no';
wps_sfw_update_meta_data( $prod_id, '_wps_sfw_product', $wps_sfw_product );
wps_sfw_update_meta_data( $prod_id, '_wps_sfw_users', $wps_sfw_user );
```

Parent reads `wc-donation-recurring` from post meta (which was just-written from `$_POST` at line 1462) and writes `_wps_sfw_product` / `_wps_sfw_users` based on the value.

**Companion dependency:** the v0.6.5 structural fix relies on this exact logic. The admin tab injector ensures `$_POST['wc-donation-recurring']='user'` arrives, then parent's own save flow writes `_wps_sfw_product='yes'` naturally. If parent inverts the logic or changes the meta keys, the companion needs updating — but more fundamentally, this is the bug we surfaced for the WCDonation team in the outreach email: parent is reading meta that it just wrote from POST one function call earlier, when reading directly from $_POST would be simpler and avoid the silent-no-op when admins hide the field.

### 2.3 Frontend localize — `class-wcdonationproces.php` lines 324–355

Parent's `wp_localize_script` call exposing `wcOrderScript.donationToOrder.{action,nonce,ajaxUrl}` to JS. The companion's `Frontend\Assets` declares its own `dfwcCompanion` localize on a separate handle, but uses the same `_wcdnonce` action when generating the nonce (parent reads it via `check_ajax_referer( '_wcdnonce', 'nonce' )` in the AJAX handler).

**What to check on parent update:** the nonce action name (`_wcdnonce`) is unchanged.

### 2.4 WPS SFW expiry handling — `class-wcdonationsubscriptionfree.php` lines 78–110

Parent's WPS SFW integration treats an empty `wps_sfw_subscription_expiry_number` field as "open-ended forever" — that's the convention companion uses for monthly/annual donations (no end date). WCS uses `new_length=0` for the same.

**Companion dependency:** auto-configure-product writes empty expiry fields. Donor-side overlay JS sends empty expiry in the POST body for recurring submits. If parent changes the convention (e.g., requires an explicit `999999` instead of empty), the companion needs updating.

### 2.5 Frontend templates

#### `frontend-order-donation.php` lines 1–100 + 400–420
The donor form template top. Defines `.wc-donation-in-action` wrapper, the `$blocks` array that parent uses to render sub-blocks in admin-configured order (Frontend Ordering setting), plus the title + image render block.

**Companion dependency:** `.wc-donation-in-action` is the scope our overlay JS searches for parent's anchors. `.campaign-title` and `.block-campaign-thumbnail` are hidden by display options when admin opts out of showing them.

#### `frontend-donation-cause-disp.php` lines 1–24
Parent's cause-selector block. Renders `<h3 class="wc-donation-title">Select Cause</h3>` plus the cause dropdown.

**Companion dependency:** the `.row2 h3.wc-donation-title` text is replaced by the overlay JS when admin sets a custom Cause section heading via display options.

#### `frontend-donation-amount-disp.php` lines 1–183
The amount selector block. Defines `.row1` wrapper, hidden price input (`name="wc-donation-price"`, `class="donate_{id}_{rand}"`), the optional custom amount input (`.grab-donation` / `.wc-donation-f-donation-other-value`), and the hidden `wc-donation-cause` input.

**Companion dependency:** the overlay JS hides `.row1` and writes to all the named hidden inputs. If parent renames `wc-donation-price` or restructures the input scope, the overlay can't write the donor's amount.

#### `frontend-donation-subscription-disp.php` lines 1–178
The recurring block. Defines `.donation-is-recurring` checkbox, WCS `_subscription_period` / `_period_interval` / `_length` selects, WPS SFW `wps_sfw_subscription_*` fields. Wrapped in `.wc_donation_subscription_table` (WPS SFW) or `.row3` (WCS).

**Companion dependency:** the overlay JS hides the wrappers and writes to the named inputs based on the donor's selected interval. Critical: this template only renders when `wc-donation-recurring='user'` (per parent's logic at line 1539 of the campaign settings file).

#### `frontend-donation-button-disp.php` lines 14–22
Parent's submit button + the hidden `.wc_donation_camp` (campaign ID) and `.wp_rand` (per-form random suffix) inputs.

**Companion dependency:** the overlay JS reads `.wc_donation_camp` and `.wp_rand` to build the AJAX submit, and binds a capture-phase guard on `.wc-donation-f-submit-donation` that halts submit if amount is invalid.

### 2.6 Submit handler — `assets/js/frontend.js`

#### Lines 351–500: `addDonationToOrder()` + click handler
Parent's `addDonationToOrder( type, amount, ... )` function and the `jQuery( document ).on('click', '.wc-donation-f-submit-donation', ...)` handler. Reads form values, AJAXes to `donation_to_order`, redirects on success.

**Companion dependency:** the overlay JS does NOT re-implement this. It populates parent's hidden inputs so this function reads the correct values, then lets parent's click handler fire normally. The `addDonationToOrder` POST shape (parameter order, key names) must remain stable for the donor flow to work.

#### Lines 860–900: extra click validator
Parent's secondary click validator that checks `wps_sfw_subscription_number >= 1` before allowing submit. The overlay JS populates `wps_sfw_subscription_number=1` (always) when interval is recurring, so this validator passes.

## 3. Bugs in the parent surface (open for upstream)

Two bugs surfaced during v0.6.x development. Both surfaced to the WCDonation team in the outreach email; both have working companion workarounds.

### Bug 1: Silent recurring downgrade
`class-wcdonationorder.php:1655` gates recurring metadata application on `wc-donation-recurring === 'user'`. If the meta is `'disabled'` or `'enabled'`, parent silently treats every recurring submit as one-time even when POST `is_recurring=yes` is present.

**Fix:** companion force-sets the meta to `'user'` when monthly/annual enabled (`Meta_Box::save()`).

**Upstream fix would be:** read `is_recurring` from `$_POST` directly, ignore the meta gate. Or: remove the gate entirely.

### Bug 2: `_wps_sfw_product` derived from post meta after writing it from POST

`class-wcdonationcampaignsetting.php:1462` writes `wc-donation-recurring` from `$_POST['wc-donation-recurring']`. Then `class-wcdonationcampaignsetting.php:1739` reads the same meta back and writes `_wps_sfw_product` based on it.

If a UI hides or omits the `wc-donation-recurring` field, the field POSTs its default (`'disabled'`), parent writes that meta, then immediately reads it and writes `_wps_sfw_product='no'`. Result: donor flow downgrades silently.

**Fix:** companion's admin tab injector keeps the field visible and force-syncs its value to `'user'` (v0.6.5).

**Upstream fix would be:** read `wc-donation-recurring` from `$_POST` at line 1739 instead of from post meta. Or: unconditionally seed `'user'` when the campaign has interval config set.

## 4. How to update the contract baseline

When parent ships a new version and the line ranges shift legitimately (e.g., a refactor that doesn't change the surface we depend on), regenerate the baseline:

```bash
php tests/parent-contract.update-baseline.php
```

Required: a copy of the parent plugin extracted at `tests/donation-for-woocommerce/` (gitignored). The script reads each line range, computes the SHA-256, and writes `tests/parent-contract.baseline.json`. Commit the regenerated file with a clear message: `"chore: regenerate parent contract baseline against v3.10.0"`.

When the line ranges shift in a way that changes the surface (e.g., parent refactors the AJAX handler), the companion needs corresponding updates BEFORE the baseline regenerates. Otherwise, regenerating just locks in the broken state.

## 5. Future contract entries

Phases 4, 6, 7 may add new contract entries as the companion attaches to additional parent surfaces:

- Phase 4 (taxonomies + directory) — likely no new entries (taxonomies are independent of parent)
- Phase 6 (per-currency presets) — possible entry if WCML hooks into parent's render
- Phase 7 (advanced intervals) — possible entry on parent's WCS interval validation if we discover a parent gate similar to the line-1655 gate but for non-monthly/annual cadences

Each phase plan calls out new contract entries explicitly. See `plans/v2/`.
