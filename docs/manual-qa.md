# Manual QA Checklist

Run before tagging any release. Playwright covers the integration surface; this checklist covers what automation can't (visual polish, real payment processors, accessibility, multi-currency, multi-theme).

> Working from a clean wp-env start (`npm run env:start:wps`) unless noted otherwise. Reset between runs with `npm run env:destroy && npm run env:start:wps`.

## Plugin lifecycle

- [ ] Activate companion via Plugins screen → no PHP notices in `WP_DEBUG_LOG`
- [ ] Deactivate parent (Donation for WooCommerce) → companion shows "requires Donation for WooCommerce" notice
- [ ] Reactivate parent → notice clears
- [ ] Deactivate WooCommerce → companion shows "requires WooCommerce" notice
- [ ] Reactivate WooCommerce → notice clears
- [ ] Activate companion on PHP 7.4 → no fatal
- [ ] Activate companion on PHP 8.3 → no deprecation warnings
- [ ] HPOS toggle on/off (Settings → Advanced → Features) → no errors
- [ ] WP-CLI: `wp plugin activate donations-for-woocommerce-companion` → success

## Engines

- [ ] WPS SFW only active → recurring tabs work, donations enroll as subscriptions
- [ ] WC Subscriptions only active → recurring tabs work, donations enroll as subscriptions
- [ ] Both active → WCS preferred (matches parent's preference)
- [ ] Neither active → recurring tabs disabled with notice, one-time still works
- [ ] Engine swap (deactivate one + activate other) → stored campaign config still valid, no DB writes triggered

## Admin meta box

- [ ] Edit a `wc-donation` campaign → meta box "Interval-First Donation Form" visible above existing parent meta boxes
- [ ] Side meta box "Companion: Form Mode" present
- [ ] Configure all three intervals with distinct presets (one-time $25/$50/$100, monthly $10/$25/$50, annual $100/$300/$1000); save → values round-trip
- [ ] After save: `wp post meta get <id> wc-donation-recurring` returns `user`
- [ ] After save: `wp post meta get <id> _subscription_period` returns `month` or `year`
- [ ] Drag-reorder presets → save → order persists
- [ ] Add 6 presets → all save
- [ ] Remove preset (down to 1) → can't remove the last one (button is no-op)
- [ ] Set default radio on preset → save → reload → still default
- [ ] Add preset with amount=0 → admin error notice; previous values intact
- [ ] Linked product NOT a subscription product, monthly enabled → yellow warning in meta box
- [ ] Engine missing, monthly enabled → engine notice with install link
- [ ] Editor user lacking `edit_post` for a campaign → meta box hidden

## Donor form — visual

- [ ] Place `[dfwc_recurring_donation campaign_id=N]` on a page → form renders with three tabs
- [ ] Tab cards visually distinct; active state clearly differentiated from inactive
- [ ] Preset buttons in 3-column grid (desktop), 2-column (mobile <480px)
- [ ] CTA spans full width, large enough touch target (≥44px height)
- [ ] Custom-amount input has currency symbol prefix
- [ ] Engine missing: monthly/annual tabs visibly disabled (greyed)
- [ ] Two shortcodes for two campaigns on one page → both work independently
- [ ] No CSS leaking out of `.dfwc-form` scope
- [ ] No JS console errors on page load

## Donor form — behavior

- [ ] Click each tab → corresponding panel becomes visible, others hidden
- [ ] Tab keyboard nav: Tab focuses tab → arrow keys move between tabs → Enter activates
- [ ] Click preset → CTA updates immediately ("Donate $25" / "$25/month" / "$300/year")
- [ ] Type custom amount → CTA updates after debounce (~200ms)
- [ ] Custom amount above max → input clamps to max on blur
- [ ] Custom amount below min → input clamps to min on blur (or rejected by browser)
- [ ] Submit one-time $50 → cart loads with $50 line item, no `billing_*` meta
- [ ] Submit monthly $25 → cart shows recurring line; `billing_period=month`, `billing_interval=1`
- [ ] Submit annual $300 → cart shows `billing_period=year`
- [ ] Spinner visible during fetch; CTA restores on response
- [ ] Submit with custom $0.50 (min=$5) → 422 inline error
- [ ] DevTools tampered POST (`is_recurring=yes` for one-time-only campaign) → 422 inline error
- [ ] Network error mid-submit (kill server) → friendly inline error, no white screen
- [ ] Stale nonce (leave page open >24h, then submit) → friendly error

## Themes & contexts

- [ ] WP 6.2 baseline + classic theme (Twenty Twenty-One)
- [ ] WP 6.7 + block theme (Twenty Twenty-Four)
- [ ] Storefront theme
- [ ] WooCommerce Cart Block enabled — line-item meta still displays
- [ ] WooCommerce Cart Block disabled (classic cart shortcode) — same
- [ ] Multi-currency plugin installed (Aelia or WC Currency Switcher) — amounts display in store base currency only (per `Known limitations`)
- [ ] RTL site (`is_rtl() === true`) — form lays out correctly
- [ ] Reduced-motion: tab/spinner animations slow or static

## Accessibility

- [ ] Lighthouse a11y score on the page containing the form: 95+
- [ ] Screen reader (NVDA / macOS VoiceOver):
  - Tabs announced as "tab"
  - Panels announced as "tabpanel"
  - State changes ("selected") announced
  - CTA announced with current amount + interval
  - Error messages announced via role="alert"
- [ ] Keyboard-only nav: full flow completable without mouse
- [ ] Focus indicators visible on all interactive elements

## Block editor

- [ ] Insert "Recurring Donation" block → renders placeholder
- [ ] Sidebar: campaign picker shows all `wc-donation` posts; selecting one renders preview via ServerSideRender
- [ ] Switching campaigns → preview updates
- [ ] Save post → frontend renders identical to shortcode
- [ ] `block.json` apiVersion 2 confirmed; no JS console errors in editor

## Elementor widget (only if Elementor installed)

- [ ] "Recurring Donation" widget appears in Elementor's panel
- [ ] SELECT2 campaign picker shows all `wc-donation` posts
- [ ] Preview in editor renders the form
- [ ] Save page → frontend renders identical to shortcode
- [ ] No errors when Elementor is deactivated mid-session (graceful degradation)

## Form replacement (D3)

- [ ] Set `_dfwc_companion_form_mode='replace'` on a campaign
- [ ] Visit single-campaign permalink → our form appears, parent's form absent
- [ ] HTML validates (no buffer leak, no orphan tags)
- [ ] Set back to `shortcode_only` → parent's form returns

## Self-check

- [ ] On healthy site → no notices on dashboard
- [ ] `wp transient delete dfwc_self_check` then load `/wp-admin/` → fresh probe runs
- [ ] Manually rename `donation_to_order` action handler in dev → notice appears within 12h
- [ ] Bump parent's `WC_DONATION_VERSION` to `4.0.0` in dev → warning notice appears
- [ ] Notices dismissible (click X)
- [ ] Notices NOT shown on Plugin Install screen, Update Core screen, or Customizer

## Real payments (optional, manual)

- [ ] WC Subscriptions + WC Stripe Gateway, test mode → complete monthly donation → subscription created in `wp-admin/edit.php?post_type=shop_subscription`
- [ ] WPS SFW + WPS Stripe addon, test mode → complete monthly donation → subscription enrolled
- [ ] Cancel a subscription → no companion-side errors
- [ ] Receive renewal payment → no companion-side errors

## Distribution zip

- [ ] `npm run build:zip` → `dist/donations-for-woocommerce-companion.zip` produced
- [ ] Zip < 200 KB
- [ ] `unzip -l dist/donations-for-woocommerce-companion.zip` shows NO entries from: `tests/`, `node_modules/`, `vendor/`, `.wolf/`, `.claude/`, `plans/`, `.git/`, `.github/`, `playwright.config.ts`
- [ ] Install zip on a fresh WP site → activate → meta box renders → form works

## CI

- [ ] Lint workflow green on push
- [ ] Parent-contract workflow green on push (matches current parent v3.9.8)
- [ ] Playwright `none` fixture green
- [ ] Playwright `wps` fixture green

## Sign-off

| Role | Name | Date |
|---|---|---|
| Tester | | |
| Approver | | |
