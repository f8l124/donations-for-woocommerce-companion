# Donations for WooCommerce Companion

[![Release](https://img.shields.io/github/v/release/f8l124/donations-for-woocommerce-companion?label=release)](https://github.com/f8l124/donations-for-woocommerce-companion/releases/latest)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](#requirements)
[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759b)](#requirements)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-96588a)](#requirements)

The modern donor-facing form pattern — three side-by-side options for **One-time**, **Monthly**, and **Annually** with separate preset amount tiers per interval — built on top of the [Donation for WooCommerce](https://woocommerce.com/products/donations-for-woocommerce/) parent plugin **without modifying it**.

```
┌───────────────────────────────────────────────────────────────┐
│  ○ One-time      ● Monthly       ○ Annually                   │
│ ─────────────────────────────────────────────────────────     │
│  ○ $10           ○ $25 [Most popular]  ○ $50                  │
│       ○ Custom: $ ___________                                 │
│                                                               │
│  Provides school supplies for one student                     │
│                                                               │
│        [ Donate $25/month ]                                   │
└───────────────────────────────────────────────────────────────┘
```

The companion plugin feeds the parent plugin's existing AJAX pipeline with the right parameters, so all downstream wiring (cart, order, subscription, emails, reports, PDF receipts) keeps working untouched. Recurring billing is handled by either **WooCommerce Subscriptions** (paid) or **Subscriptions for WooCommerce by WPS** (free) — auto-detected.

---

## Why this exists

Donation for WooCommerce supports recurring donations *technically*, but only via a single legacy "Make this recurring" checkbox plus period/interval/length dropdowns. There's no built-in way to offer the modern donor pattern that nonprofits like Charity:Water, NPR, and Wikipedia have made standard. This companion adds it without forking the parent plugin — the parent keeps receiving auto-updates; the companion attaches to its 47+ documented action and filter hooks.

---

## What's in v1.1.0

| Feature | Details |
|---|---|
| **Interval-first donor form** | Side-by-side tabs with per-interval presets, min/max custom amounts, and CTA templating |
| **Layered config model** | Defaults → Global Settings → Named Templates → Campaign Overrides. Save once, apply to many. |
| **Per-currency presets** | "$25 / £20 / €22" — admins define amounts per currency; resolves at render time via WCML (or filter for WCPay/Aelia) |
| **Advanced cadences** (opt-in) | Weekly, quarterly, semi-annually, plus admin-defined custom ("every 6 weeks") |
| **Donor impact messaging** | Per-preset impact labels with four display modes, featured-preset badges, subtitles, annual-equivalency text |
| **Live admin preview pane** | Pixel-faithful donor-form preview on the campaign edit screen, Templates page, and Settings page |
| **Donor-facing campaign directory** | Six taxonomies, filterable grid via shortcode + Gutenberg block + Elementor widget, REST live search |
| **Event hooks** | Six WordPress action hooks for analytics / CRM / webhook integrations (GA4, FluentCRM, Zapier recipes shipped) |
| **WPML + WCML support** | All admin-defined strings registered with WPML; taxonomies translate per-term; per-currency presets resolve via WCML |
| **`wp dfwc-companion health` CLI** | Diagnostic report in JSON / table / markdown — pipe into monitoring agents |
| **Diagnostics admin page** | 13 health checks with status pills, suggested remediations, copy-paste-ready support report |

Full changelog: [`readme.txt`](readme.txt). Per-release notes: [`.release-notes.md`](.release-notes.md).

---

## Requirements

| Component | Minimum |
|---|---|
| WordPress | 6.2 |
| PHP | 7.4 |
| WooCommerce | 5.0 |
| Donation for WooCommerce (parent) | 3.9.8 |
| Subscription engine | WC Subscriptions OR Subscriptions for WooCommerce (WPS, free) — either works |

The companion is HPOS-compatible (High-Performance Order Storage) and Cart/Checkout-Block-compatible.

---

## Install

### From a release zip (recommended)

1. Download the latest zip from the [Releases page](https://github.com/f8l124/donations-for-woocommerce-companion/releases/latest).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → activate.
3. Visit **WooCommerce → Donations Companion → Diagnostics** to verify the install.

### From source

```bash
git clone https://github.com/f8l124/donations-for-woocommerce-companion.git
cd donations-for-woocommerce-companion
composer install     # dev tooling only — runtime ships without vendor/
npm install          # Playwright + wp-env
```

Then symlink or copy the directory into your site's `wp-content/plugins/`.

### Once it's available on WordPress.org

`Plugins → Add New → search for "Donations for WooCommerce Companion"` (planned post-v1.1.0).

---

## Usage in 60 seconds

1. **Edit a campaign** → fill in the **"Interval-First Donation Form"** meta box: enable the intervals you want, set preset amounts, set min/max for custom amounts.
2. **Place the form** wherever donors should see it — three options:
   - Auto-augment: the companion automatically wraps parent's existing form rendering on campaign permalinks, the parent's `[wc_woo_donation]` shortcode, and the parent's widget. No further action needed.
   - Shortcode: `[dfwc_recurring_donation campaign_id="123"]`
   - Gutenberg block: search for **"Recurring Donation"** in the inserter
3. **Test the donor flow** in the live admin preview pane below the meta box. Toggle viewport / engine / language / currency to verify how the form behaves under each.

For multi-campaign sites, define a **template** once at *Donations Companion → Templates*, then apply via the campaign meta box or via bulk actions on the campaign list screen.

Full walkthrough: [`docs/getting-started.md`](docs/getting-started.md).

---

## Documentation

### For nonprofit admins

- [`docs/getting-started.md`](docs/getting-started.md) — onboarding from fresh install through first campaign
- [`docs/advanced-intervals.md`](docs/advanced-intervals.md) — weekly / quarterly / semi-annually / custom cadences
- [`docs/multi-currency.md`](docs/multi-currency.md) — WCML / WCPay / Aelia integration
- [`docs/troubleshooting.md`](docs/troubleshooting.md) — diagnostics walkthrough; common issues
- [`docs/privacy.md`](docs/privacy.md) — what the plugin stores, what it doesn't, GDPR posture

### For developers

- [`docs/event-hooks.md`](docs/event-hooks.md) — six action hooks + GA4 / FluentCRM / webhook recipes
- [`docs/architecture/current-state.md`](docs/architecture/current-state.md) — module structure, save flow, render flow
- [`docs/architecture/parent-contract.md`](docs/architecture/parent-contract.md) — every line range we attach to in the parent plugin
- [`docs/architecture/compatibility-matrix.md`](docs/architecture/compatibility-matrix.md) — what's tested where
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — local setup, code style, PR flow
- [`docs/release-process.md`](docs/release-process.md) — how to cut a release

### For maintainers

- [`SECURITY.md`](SECURITY.md) — vulnerability reporting
- [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) — Contributor Covenant 2.1
- [`docs/release-readiness.md`](docs/release-readiness.md) — wp.org submission checklist

---

## Architecture

The plugin is small (~5,000 LOC PHP+JS+CSS, well under the wp.org soft limit) and intentionally low-magic:

- **Hand-rolled PSR-4-ish autoloader** — no Composer in the runtime. Composer is dev-only.
- **No custom database tables** — all per-campaign config in post meta on `wc-donation` posts; templates in a single `wp_options` row.
- **All persistence via `update_post_meta` / `WP_Query`** — zero direct SQL outside `$wpdb->prepare` (in `uninstall.php` for the transient sweep).
- **No remote runtime fetches** — no analytics, no telemetry, no external dependencies at runtime.
- **Auto-augmentation, not replacement** — the donor sees parent's full form (cause selector, gift aid, processing fee, tributes, donor wall) with our interval-first UI mounted into parent's amount-block position via CSS / JS.
- **Defense in depth on submit** — three layers (server-side guard, JS short-circuit, disabled HTML) prevent preview HTML from ever submitting a real donation.

Full architectural overview: [`docs/architecture/current-state.md`](docs/architecture/current-state.md).

---

## Testing

| Layer | Coverage |
|---|---|
| **PHPCS** (WPCS + PHPCompatibilityWP) | Clean |
| **PHPStan** (level 5) | No errors |
| **PHPUnit** | 201 tests / 518 assertions |
| **Parent contract watcher** | 16 file/line ranges hashed; CI fails if parent restructures |
| **Playwright E2E** | 10 specs across `none` / `wps` / `wcs` engine fixtures |
| **Plugin Check** | Gated on `DFWC_PARENT_ZIP_URL` secret (parent plugin is paid) |

Run all gates locally:

```bash
composer check            # PHPCS + PHPStan + PHPUnit
composer test:contract    # parent-contract watcher
npm run test:e2e          # Playwright (requires tests/donation-for-woocommerce.zip)
```

---

## Compatibility & status

This is a community-maintained companion. The parent plugin (Donation for WooCommerce by WPExperts) is **not affiliated** — the companion attaches via documented hooks rather than forking. A built-in self-check probe runs on every admin page load and surfaces an admin notice if any parent plugin update breaks a contract the companion depends on. A weekly CI watcher catches parent updates the moment they ship.

WPML compatibility certification is planned for post-WordPress.org acceptance — see [`plans/v2/AA-wpml-integration.md`](plans/v2/AA-wpml-integration.md) (gitignored locally; spec lives there).

---

## Contributing

Bug reports, feature requests, and pull requests welcome. See:

- [`CONTRIBUTING.md`](CONTRIBUTING.md) for setup + PR flow
- [`SECURITY.md`](SECURITY.md) for vulnerability reporting
- [`.github/ISSUE_TEMPLATE/`](.github/ISSUE_TEMPLATE/) for issue templates
- [Pinned roadmap issue](https://github.com/f8l124/donations-for-woocommerce-companion/issues/1) for scope discussion

---

## License

[GPL-2.0-or-later](LICENSE) — same as the parent plugin and WordPress core. Compatible with the WordPress.org plugin directory's licensing requirements.

Copyright (c) 2026 David Stells.
