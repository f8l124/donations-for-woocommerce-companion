# Troubleshooting

> First step for any issue: **Donations Companion → Diagnostics**. Or run `wp dfwc-companion health --format=markdown` from the command line. The diagnostic page surfaces 13 health checks with status pills and suggested remediations — most issues are obvious the moment you read the report.

---

## Quick triage

| Symptom | First thing to check |
|---|---|
| Donor form looks unchanged from parent's vanilla form | Did you save the campaign meta box? Companion gates auto-augmentation on `Config_Resolver::is_configured()`. |
| Recurring donation submits as one-time | `wc-donation-recurring` post meta — should be `'user'`. Check at `wp post meta get <id> wc-donation-recurring`. |
| Yellow warning: "linked product is not configured as a subscription" | Save the campaign once. Auto-config writes the right meta keys on save. |
| Donor sees disabled monthly/annually tabs | No subscription engine is active. Install Subscriptions for WooCommerce (free) or WC Subscriptions (paid). |
| Per-currency UI doesn't appear in admin | WCML isn't reporting active currencies beyond base. Check WCML's currency-switcher config. |
| Live preview pane shows nothing | Nonce check failed (browser cached old admin) or REST endpoint blocked by security plugin. |
| Diagnostics page fails to load | Plugin Check `subscription_engine` warning — but the page itself should still render. Check WP_DEBUG_LOG for fatals. |

---

## Symptom: donor form looks like parent's vanilla form

The companion's interval-first UX appears only when:

1. The campaign has companion config saved (template assigned OR per-campaign overrides OR legacy v0.6.x meta).
2. A subscription engine is active (or no recurring intervals are enabled — one-time-only campaigns work without an engine).
3. The donor isn't on a context the companion deliberately skips (e.g., RSS feeds).

**Verify:**

```bash
wp post meta get <campaign_id> _dfwc_companion_overrides
wp post meta get <campaign_id> _dfwc_companion_template_id
wp post meta get <campaign_id> _dfwc_companion_intervals  # legacy fallback
```

If all three are empty, edit the campaign and save the meta box once. The save handler writes the `_dfwc_companion_overrides` meta and forces parent's `wc-donation-recurring='user'` if monthly/annual is enabled.

**Power-user opt-out:** if you've intentionally disabled the companion on a specific page via the `dfwc_should_augment_parent_form` filter, you'd see this exact symptom. Search your theme/mu-plugins for `dfwc_should_augment_parent_form`.

---

## Symptom: recurring donation gets billed once, then never renews

Two possible causes:

### Cause 1: `wc-donation-recurring` is wrong

Parent's AJAX handler (line 1655 of `class-wcdonationorder.php`) only applies recurring metadata when the campaign's `wc-donation-recurring` meta = `'user'`. If it's `'disabled'` or `'enabled'`, the parent silently downgrades every recurring submit to one-time.

```bash
wp post meta get <campaign_id> wc-donation-recurring
```

If the value is wrong, edit the campaign and save the meta box. The companion auto-corrects on save when monthly or annual is enabled.

If the value resets to `'disabled'` after save, you may have a custom integration POSTing to the parent's save handler — check for hooks on `wc_donation_after_save_campaign_meta`.

### Cause 2: linked product isn't a subscription product

Subscription engines look at the linked WooCommerce **product's** subscription configuration, not just the cart-line meta. The campaign's linked product must be:

- **WPS SFW**: have post meta `_wps_sfw_product = 'yes'`
- **WCS**: have `subscription` term in the `product_type` taxonomy

The companion's auto-configure (in `Meta_Box::auto_configure_product_subscription`) writes these on every save. If they're getting unset by another plugin, the meta box surfaces a yellow warning with diagnostic detail.

Verify:

```bash
# WPS SFW
wp post meta get <product_id> _wps_sfw_product

# WCS
wp eval "echo (WC_Subscriptions_Product::is_subscription( <product_id> ) ? 'yes' : 'no');"
```

---

## Symptom: yellow warning "linked product is not yet recognized as a subscription product"

This is the meta box's defensive warning for when the linked WC product hasn't been configured as a subscription product yet. Save the campaign once and the warning should clear.

**Diagnostic detail in the warning** (added in v0.6.3) shows the actual meta values read. Use it to distinguish:

- `_wps_sfw_product=''` → the auto-configure didn't write yet. Save the campaign once.
- `_wps_sfw_product='no'` → another plugin overrode our write. Look for hooks on `woocommerce_process_product_meta`.
- `_wps_sfw_product='yes'` but warning still shows → cache issue. Visit `Diagnostics → Re-check`.

---

## Symptom: monthly / annual tabs are visibly disabled

The companion gates recurring tabs on engine availability. If `Engine_Detector::detect()` returns `none`, monthly + annual tabs render with `aria-disabled="true"` and a tooltip pointing at the install link.

**Fix:** install one of:

- [Subscriptions for WooCommerce](https://wordpress.org/plugins/subscriptions-for-woocommerce/) (free, by WPS)
- [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/) (paid)

Verify via `wp dfwc-companion health` — the `subscription_engine` check should turn green.

---

## Symptom: per-currency preset UI doesn't appear

The companion shows the per-currency UI only when `Currency_Preset_Resolver::extra_currencies()` returns non-empty. It returns empty when:

- WCML isn't loaded.
- WCML is loaded but no currencies beyond base are enabled.
- A custom filter on `dfwc_companion_supported_currencies` returns just the base currency.

**Verify:**

```bash
# Check WCML active currencies
wp eval "var_dump( wcml_multi_currency()->get_active_currencies() );"
```

If WCML is configured correctly but the UI still doesn't appear, run the diagnostic page — the `wcml_present` check explains what's detected.

For non-WCML stacks (WCPay Multi-Currency, Aelia), see [`multi-currency.md`](multi-currency.md) for the filter snippets.

---

## Symptom: live preview pane shows blank

Several causes:

### REST endpoint blocked

Some security plugins (Wordfence, iThemes Security) restrict REST access by default. The preview endpoint requires `manage_woocommerce` cap, but the security plugin may block it before WP routes the request.

**Check:** browser DevTools → Network → XHR. Look for `POST /wp-json/dfwc-companion/v1/preview`. If it 403s or never appears, your security plugin is blocking REST.

**Fix:** whitelist the endpoint in your security plugin. The companion's preview is admin-only; it's safe to allow.

### Nonce expired

If you've left the campaign edit screen open for a long time, the WP nonce may have expired (default 24h). The preview's `X-WP-Nonce` is generated at page load.

**Fix:** reload the campaign edit screen.

### Iframe errored silently

Older browsers / restrictive Content-Security-Policy headers may block the `srcdoc` iframe. Check the browser console for CSP violations.

---

## Symptom: events aren't firing in PHP listeners

For Phase 9 event hooks, see the dedicated section in [`event-hooks.md`](event-hooks.md). Quick checklist:

1. `wp dfwc-companion health` — verify the parent plugin and engine checks pass.
2. `curl -X POST https://your-site.test/wp-json/dfwc-companion/v1/track -d '{"events":[{"type":"form_viewed","campaign_id":1}]}' -H "Content-Type: application/json"` — should return 200.
3. Browser DevTools → Network: confirm donor-side events POST to `/wp-json/dfwc-companion/v1/track` after preset clicks / tab changes.
4. Add `error_log( 'dfwc:listener-fired' )` inside your listener to confirm registration.

---

## Symptom: form submits but lands on a 404 cart page

The companion writes to parent's hidden cart inputs and lets parent's submit handler run unchanged. If parent's handler returns a `cart_url` that's a 404:

1. Check `WC()->cart` is initialized — some ultra-minimal themes skip cart initialization on certain page types.
2. Check the WC `Cart` page is actually configured: `wp option get woocommerce_cart_page_id`.
3. Manually visit `your-site.test/?wc-ajax=donation_to_order&action=donation_to_order` — should respond with JSON, not a 404.

If parent's AJAX action isn't bound at all, run `Diagnostics`. The `parent_ajax_action` check turns red and tells you to reactivate the parent plugin.

---

## Symptom: WPML translations don't appear in donor view

The resolver translates strings at render time via `WPML_Strings::translate`. Translations must be:

1. Registered with WPML String Translation. The companion does this automatically on every campaign / template save under the `Donations Companion` domain.
2. Translated by an admin via *WPML → String Translation*.
3. Active in WCML/WPML (the donor's language is detected via `WPML_Strings::current_language()`).

**Verify:**

```bash
wp eval "var_dump( apply_filters( 'wpml_active_languages', null, '' ) );"
```

If WPML reports no active languages, WPML isn't fully configured. Check *WPML → Languages*.

If WPML is active and a string registered correctly but the translation doesn't appear:

- Visit *WPML → String Translation* — filter by domain `Donations Companion`. If your string isn't there, save the campaign once to register it.
- Confirm the donor's language is set correctly via WCML's currency-switcher / language-switcher.

---

## Symptom: parent contract test failing in CI

The parent contract watcher (`tests/parent-contract.test.php`) hashes specific line ranges in the parent plugin and fails if they change. A failure means the parent plugin shipped a new release that restructured something the companion depends on.

**Fix:**

1. Pull the new parent zip.
2. Run `composer test:contract` locally.
3. Inspect the failing line ranges — verify the companion still works at runtime (test on wp-env).
4. If the new shape is functionally equivalent, regenerate the baseline: `composer test:contract:update`.
5. Commit the updated `tests/parent-contract.baseline.json`.
6. If the new shape breaks the companion, file an issue + cut a hotfix.

---

## Symptom: PHPStan errors after pulling main

PHPStan runs at level 5 with stubs for parent-plugin classes / WPML / WC-CLI. If you've pulled new code that references symbols not yet stubbed:

1. Check the error message — usually "unknown class X" or "unknown function Y".
2. Add a minimal stub to `tests/phpstan-bootstrap.php` matching the symbol's name + signature.
3. Re-run `composer analyze`.

The bootstrap is intentionally minimal — stubs only declare the methods/constants we actually call. PHPStan just needs the names to exist.

---

## Common admin notices

### "Donations for WooCommerce Companion requires WooCommerce to be active"

Self-explanatory — install + activate WooCommerce.

### "Donations for WooCommerce Companion requires the Donation for WooCommerce plugin to be active"

The parent plugin is paid (`woocommerce.com/products/donations-for-woocommerce/`). Not on wp.org.

### "Donations for WooCommerce Companion has not been tested with Donation for WooCommerce 4.x"

Soft warning — the companion may still work. Test the donor flow on staging before production.

### "Advanced intervals are enabled, but no subscription engine is active"

The global toggle is on but no engine is loaded. Either install an engine OR turn off the global toggle.

---

## Reading the support report

The Diagnostics page has a **"Copy support report"** button at the bottom that produces a Markdown table suitable for pasting into a GitHub issue or Slack thread. The report:

- Strips absolute filesystem paths (replaces with `<path>`)
- Caps long values at 500 chars
- Allow-lists context fields (only safe ones — version strings, engine slug, etc. — make it through)
- Includes the **Suggested actions** section pulling remediation copy from any non-pass results

Always include the support report when filing a bug. The maintainer can recreate ~80% of the diagnostic context from it.

---

## When to escalate

Open a [GitHub issue](https://github.com/f8l124/donations-for-woocommerce-companion/issues/new/choose) with the support report attached when:

- A diagnostic check is red ❌ AND the suggested remediation doesn't fix it.
- A donor flow that worked before now produces an error.
- You see a fatal error in `wp-content/debug.log` mentioning `DFWC\Companion\` namespace.

For security issues, use the private channel — see [`SECURITY.md`](../SECURITY.md).

---

## Last-resort reset

If the companion is in a broken state and you want to start over without losing parent-plugin data:

1. Settings → Donations Companion → Settings → uncheck "Preserve data on uninstall".
2. Plugins → Donations for WooCommerce Companion → Deactivate.
3. Plugins → Donations for WooCommerce Companion → Delete.
4. Reinstall the plugin from a fresh zip.

This wipes companion options + post meta + transients. Parent-plugin data (the `wc-donation` campaigns themselves, orders, products) is untouched. Step 1 is the key — without it, "preserve data" defaults to ON and Delete becomes a no-op cleanup.
