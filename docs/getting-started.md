# Getting Started

> First-time admin onboarding guide for the **Donations for WooCommerce Companion** plugin. Walks you from a fresh install through your first donor-ready campaign in ~15 minutes.

---

## Prerequisites

You need three plugins active **before** the companion will work:

1. **WooCommerce** (free, [wp.org](https://wordpress.org/plugins/woocommerce/))
2. **Donation for WooCommerce** (paid, sold via [woocommerce.com](https://woocommerce.com/products/donation-for-woocommerce/) — this is the *parent* plugin the companion sits on top of)
3. **A subscription engine** — one of:
   - **Subscriptions for WooCommerce** by WPS (free, [wp.org](https://wordpress.org/plugins/subscriptions-for-woocommerce/))
   - **WooCommerce Subscriptions** (paid, [woocommerce.com](https://woocommerce.com/products/woocommerce-subscriptions/))

Either subscription engine is fine — the companion auto-detects which is active and feeds the right AJAX parameters at submit time.

If you skip step 3, the companion still works for **one-time** donations. Recurring tabs are visibly disabled with a clear admin notice explaining what's missing.

---

## Install + activate

1. Download the latest companion zip from the [Releases page](https://github.com/f8l124/donations-for-woocommerce-companion/releases) (or install from wp.org once we're listed there).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → activate.
3. The plugin lands at **WooCommerce → Donations Companion** in your admin menu.

A green admin notice confirms successful activation. A yellow notice surfaces if any prerequisite is missing — follow the link to install whatever's needed.

---

## Run the diagnostic

**WooCommerce → Donations Companion → Diagnostics** runs ~13 health checks against your install. The first time you visit this page, it should be all green ✅:

- WooCommerce active
- Parent plugin active + version compatible
- `wc-donation` post type registered
- Subscription engine detected
- Parent AJAX handler bound
- WPML / WCML detection (informational)
- Advanced intervals engine support (informational unless you've enabled the toggle)

If anything's yellow ⚠️ or red ❌, the page tells you exactly what's wrong and what to do. The "Re-check" button at the top forces a fresh probe (the report caches for 12 hours otherwise).

---

## Your first campaign

The parent plugin owns the campaign post type. Create one as you normally would: **Donations → Add New** in your admin sidebar.

Once the campaign exists and is published, the companion adds two meta boxes to the campaign edit screen:

- **Interval-First Donation Form** (main column, top) — the per-campaign config. This is where you'll spend most of your time.
- **Featured campaign** (sidebar) — toggle this campaign as featured in the directory grid.

### Configure the intervals

The main meta box has three tabs by default: **One-time**, **Monthly**, **Annually**. (Four extra tabs — Weekly, Quarterly, Semi-annually, Custom — appear if you've enabled the global advanced-intervals toggle in Settings.)

For each tab you want to offer:

1. Check the **"Offer X donations on this campaign"** checkbox.
2. Set 2–4 preset amounts. Add a **label** (e.g. *Supporter*) and an **impact label** (e.g. *Provides school supplies for one student*) per preset for messaging that converts.
3. Pick one preset as **featured** if you want a "Most popular" badge on it.
4. Set min and max for the **custom amount** (donors can type their own value within this range).
5. Optionally write a **subtitle** above the preset grid ("Become a monthly sponsor") and an **annual equivalency** below it ("$25/month equals $300/year").
6. Pick the **impact display mode** — inline / below preset / tooltip / card.

Save the campaign. The companion automatically:

- Forces the parent plugin's "Recurring Donations" setting to "User chooses" (required for recurring submits to actually process — without this the parent silently downgrades recurring to one-time).
- Configures the linked WooCommerce product as a subscription product (writes the right meta keys for whichever engine is active).
- Surfaces a yellow warning if any of the above doesn't take — usually means the campaign isn't yet linked to a product.

### Preview as you go

Below the meta box you'll see a **live preview pane**. Debounced 350ms updates render the donor-facing form into an iframe — pixel-faithful to what donors will see. Use the toolbar to:

- Switch viewport (Desktop / Tablet / Mobile)
- Simulate a different engine (Auto / WC Subscriptions / Subscriptions for WooCommerce / No engine) — verify how the form degrades when an engine isn't present
- Switch language (only when WPML is active)
- Switch currency (only when WCML is active)

Three independent layers prevent preview HTML from ever submitting a real donation: a server-side guard, the overlay JS's `data-preview` short-circuit, and the iframe's mock submit button shipping with the `disabled` attribute already set. You can click the preview's submit button safely.

---

## Place the form on a page

Three ways:

### Option 1 — Auto-augmentation (default for v0.5.0+)

Wherever the parent plugin already renders its donation form (the campaign permalink page, the parent's `[wc_woo_donation]` shortcode, the donation widget, the cart-block context), the companion **automatically wraps it** with the interval-first overlay. No further action needed for most sites.

### Option 2 — Shortcode

```
[dfwc_recurring_donation campaign_id="123"]
```

Place this in any page or post. Replace `123` with your campaign post ID (visible in the URL when editing the campaign).

### Option 3 — Gutenberg block

In the block editor, search for **"Recurring Donation"** in the inserter. The block has a campaign-picker control in the sidebar.

### Option 4 — Elementor widget

If you use Elementor, search for **"Recurring Donation"** in the widget panel. Configuration mirrors the shortcode.

---

## Optional: templates

If you have multiple campaigns that share the same preset structure, define a **template** once and apply it to all of them.

**WooCommerce → Donations Companion → Templates → Add New** lets you:

- Define presets / min-max / CTA / impact messaging for all 7 intervals
- Save with a name ("School Sponsorship", "Emergency Relief", "General Fund")
- Apply to one or more campaigns via the campaign edit screen's template dropdown OR via the wc-donation list-screen bulk actions

Each campaign that has a template assigned **inherits** all template fields. Per-campaign edits create per-campaign **overrides** that float on top of the template — so future template edits propagate to unchanged fields and only the field you actually edited stays campaign-specific. This is documented in detail in [`templates.md`](templates.md).

---

## Optional: directory grid

If you want donors to browse all your campaigns from one page:

```
[dfwc_campaign_grid]
```

Plus the `dfwc-companion/campaign-grid` Gutenberg block and the Elementor "Donation Campaign Grid" widget. The grid:

- Renders cards for every published `wc-donation` campaign
- Filters by the six built-in taxonomies (Cause, Region, Country, Program, Sponsorship Type, Urgency) — each campaign you classify shows up in those filters
- Has a search-as-you-type box (debounced live REST refresh; falls back to full submit if JS fails)
- Pagination, sort options including "Featured first"

Classify your campaigns by clicking the taxonomy meta boxes on the campaign edit screen — same UI WordPress core uses for categories and tags.

---

## Optional: advanced intervals

If your nonprofit's giving model includes weekly tithing, quarterly mission support, semi-annual memberships, or "every 6 weeks" sponsorship cycles:

1. **WooCommerce → Donations Companion → Settings**, check **"Enable advanced giving intervals"**.
2. Edit a campaign — four extra interval tabs (Weekly / Quarterly / Semi-annually / Custom) appear at the end of the meta box.
3. Configure as you would the standard three.
4. The custom interval gets a "Custom cadence" sub-section: pick **every N day/week/month/year** and provide a translatable donor-facing label like *every 6 weeks*.

Full details in [`advanced-intervals.md`](advanced-intervals.md).

---

## Optional: per-currency presets

If you run a multilingual / multi-currency site (with WCML or another supported switcher):

1. Set up your additional currencies in WCML's currency-switcher config first.
2. Edit a campaign — each interval gets a "Per-currency preset amounts" section with one collapsible row per non-base currency.
3. Define the amounts donors in that currency should see (e.g., £20 / £40 / £80 instead of $25 / $50 / $100). Empty rows fall back to base.

Full details in [`multi-currency.md`](multi-currency.md), including how to wire WCPay Multi-Currency or Aelia Currency Switcher via the `dfwc_companion_active_currency` filter (5-line snippet).

---

## Where to go next

- [`templates.md`](templates.md) — multi-campaign workflows
- [`campaign-directory.md`](campaign-directory.md) — building the donor-facing grid
- [`impact-messaging.md`](impact-messaging.md) — converting donors via per-preset impact labels
- [`multi-currency.md`](multi-currency.md) — WCML / WCPay / Aelia
- [`advanced-intervals.md`](advanced-intervals.md) — weekly / quarterly / semiannual / custom cadences
- [`troubleshooting.md`](troubleshooting.md) — diagnostics walkthrough; common issues
- [`privacy.md`](privacy.md) — what the plugin stores / doesn't
- [`developer-hooks.md`](developer-hooks.md) — filters + actions reference

For ops:

- `wp dfwc-companion health` — run the diagnostic from the command line, useful for monitoring agents
- [`release-process.md`](release-process.md) — how to ship a new release if you're maintaining a fork
