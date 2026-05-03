# Cause-aware giving (per-cause goals + closure UX)

> **Shipped in v2.4.0.** Extends v1.2.0 goal-aware giving (which operated at the campaign level) down to the cause level. Admins set per-cause goals; the companion tracks per-cause raised totals and closes causes when their goal is reached. Three closure modes give admins control over what donors see when a cause is fully funded.

## Table of contents

1. [What ships](#what-ships)
2. [Prerequisites](#prerequisites)
3. [Setup walkthrough](#setup-walkthrough)
4. [Closure modes explained](#closure-modes-explained)
5. [How raised totals are computed](#how-raised-totals-are-computed)
6. [The dfwc_companion_cause_closed action](#the-dfwc_companion_cause_closed-action)
7. [Diagnostics](#diagnostics)
8. [Limitations + documented constraints](#limitations--documented-constraints)
9. [Troubleshooting](#troubleshooting)
10. [Architecture notes](#architecture-notes)

---

## What ships

- **Per-cause goals** stored under `_dfwc_companion_overrides.cause_goals`, keyed by companion-minted stable cause IDs (renames in parent's UI don't break aggregation).
- **Three closure modes:** Hide, Redirect to another cause (default), Redirect off campaign.
- **Server-side enforcement** via Submit_Guard — closed causes reject AJAX submits with mode-aware messaging. Trust boundary even when client UI is bypassed.
- **Client-side UX** via dfwc-overlay.js — modifies parent's cause picker per the configured mode. Hide drops the `<li>` from the picker; Redirect-to-cause renders an inline "goal met! pick another" prompt; Redirect-off-campaign sends the donor to the general fund.
- **`dfwc_companion_cause_closed` action** fires once when a cause's raised total crosses the goal threshold — extension hook for cancelling subscriptions, posting to Slack, etc.
- **One new diagnostic check** flags orphaned cause goals (configured but parent's cause picker is disabled).
- **Recurring renewals are exempt.** Closure blocks NEW enrollments only — existing sustainers continue to renew normally. (See [Limitations](#limitations--documented-constraints).)

## Prerequisites

- WordPress 6.2+ + WooCommerce 5.0+ + Donation for WooCommerce 3.9.8+ (companion's standard stack).
- Each campaign that should accept cause-aware giving needs:
  - Causes configured via parent's "Donation Cause" admin section.
  - Parent's `wc-donation-cause-display-option` set to "Show" (the picker has to be visible to donors for the closure UX to apply).

## Setup walkthrough

### 1. Add causes via parent's UI

In the campaign edit screen, scroll to parent's "Donation Cause" section. Set the display option to **Show**, then add cause names + descriptions for the buckets you want donors to choose between. Companion's stable IDs mint automatically the first time the campaign loads after this — you don't manage them directly.

### 2. Enable cause goals globally

Navigate to *WooCommerce → Donations Companion → Settings → General*. Find the "Goal-aware giving" section:

- **Enable per-cause goals** (checkbox): turn on.
- **Default cause closure mode** (dropdown): pick the behavior for closed causes whose per-cause mode is set to "Inherit." Default is "Redirect to another cause" — matches v1.2.0 goal-aware-giving's "surface, don't hard-block" UX precedent.

Click Save Changes.

### 3. Configure per-cause goals

Re-open any campaign edit screen. A new "Per-cause goals" meta box appears (visible only when global cause-goals is on). One row per cause configured in step 1:

| Column | Purpose |
|---|---|
| Cause | The cause name from parent's UI (read-only). |
| Track | Checkbox — opt this specific cause into goal tracking. |
| Goal amount | USD-equivalent target. 0 = disabled. |
| Closure mode | What happens when the cause hits its goal. "Inherit" defers to the global default. |

Save the campaign. Goals take effect immediately; the donor view updates on next page load.

### 4. (Optional) Configure the general fund

If you'll use **Redirect off campaign** mode for any cause, configure the general fund campaign on the same Settings page (under "General fund campaign"). Donors clicking a closed cause are sent to that campaign's permalink. If no general fund is configured AND a donor bypasses the UI to attempt a closed-cause donation, the rejection message falls back to a generic "please choose another cause."

## Closure modes explained

Three modes ship at v2.4.0. Each can be set per-cause OR inherited from the global default.

### Hide

The closed cause is silently dropped from the donor's picker. Donors who land on the campaign see the remaining open causes only — no "fully funded!" callout, no fanfare.

**Best for:** Campaigns with many causes where you want to gracefully retire fully-funded buckets without drawing attention to the closure. Quietest UX.

### Redirect to another cause (default)

The closed cause shows in the picker with reduced opacity (visual cue: "this isn't selectable"). Clicking it doesn't select; it triggers an inline yellow "goal met!" card above the picker:

> **Education reached its goal!**
>
> Please consider supporting one of these still-open causes:
>
> [Healthcare] [Emergency Relief]

Each open-cause button auto-selects the matching cause and dismisses the prompt.

**Best for:** Most campaigns. Celebrates the milestone ("we hit it!") while immediately giving the donor a path forward. Matches Phase 13 goal-aware-giving's "surface, don't hard-block" UX.

### Redirect off campaign

The closed cause shows reduced-opacity. Clicking it redirects the donor's browser to the configured general fund campaign permalink. No inline card; full page navigation.

**Best for:** Campaigns where every cause hitting its goal should send donors to the org's general fund. Useful for time-bound emergency campaigns ("Hurricane Response") that conclude when funded — donors are explicitly routed to ongoing giving rather than asked to pick another cause within an over-and-done effort.

### Server-side enforcement

All three modes reject server-side via Submit_Guard if a donor bypasses the client UI (DevTools, curl) and POSTs a closed-cause donation. The rejection message is mode-aware: Redirect-off-campaign mode includes the general-fund URL, the other modes return a generic "please choose another cause" message.

## How raised totals are computed

Per-cause raised totals are queried from existing WC order data — companion adds no new write paths, just a read-side aggregation:

1. **Linked product:** companion looks up the campaign's parent-stored `wc_donation_product` meta.
2. **Single SQL aggregate:** sums `_line_subtotal` across paid order line items where the line item carries either `_dfwc_companion_cause_id` matching the cause OR (legacy fallback) parent's `Cause` meta matching the cause name.
3. **Order status filter:** `wc_get_is_paid_statuses()` — by default `processing` + `completed`. Matches parent's own raised-tally semantics.
4. **HPOS-aware:** the SQL branches on `OrderUtil::custom_orders_table_usage_is_enabled` — joins against `wp_wc_orders` (HPOS) or `wp_posts` (legacy). Line-item meta storage is unchanged across the migration.

### Caching

- **Per-request memo:** the closure renderer hits the same cause many times per render (one for each picker `<li>`). The memo collapses repeats.
- **5-minute transient:** cross-request cache. Keyed `dfwc_cause_raised_<campaign_id>_<md5(cause_id)>`.
- **Invalidation:** hooks fire on `woocommerce_order_status_completed`, `woocommerce_order_status_processing`, and `woocommerce_order_status_refunded`. The aggregator walks the order's line items, finds any carrying our cause meta (or legacy `Cause` meta with a resolvable name), and invalidates those caches. The 5-minute TTL is the long-stop for invalidation gaps (manual order edits, status transitions outside the listened set).

### Stable cause IDs

Companion mints a UUID per cause (lazily, on first read of the campaign's causes). The UUID lives in `_dfwc_companion_cause_ids` post meta on the campaign, position-indexed against parent's `donation-cause-names`. Renames in parent's UI preserve the ID at that position — historical orders carrying that cause keep counting toward the renamed cause.

The UUID is threaded into the cart → order line item via two hooks:
- `woocommerce_add_cart_item_data` (priority 20): when a cart item is added with `cause_name`, look up the cause-id by name and attach it to cart-item data.
- `woocommerce_checkout_create_order_line_item` (priority 20, after parent's add at priority 10): copy the cause-id from cart-item data onto the order line item meta.

Forward queries use the cause-id; legacy orders (placed pre-v2.4.0) fall back to name-match.

## The dfwc_companion_cause_closed action

Fires once when a cause's raised total crosses its goal threshold. Listeners can act:

```php
add_action(
    'dfwc_companion_cause_closed',
    function ( $campaign_id, $cause_id, $goal_amount, $raised_amount ) {
        // Cancel matching recurring subscriptions, post to Slack, tag
        // donors in FluentCRM, etc. The recurring-renewal exemption
        // (closure blocks new only) is enforced by Submit_Guard;
        // listeners that cancel subscriptions are making a deliberate
        // policy choice on top.
    },
    10,
    4
);
```

**When it fires:** during invalidation, when the prior cached raised was below the goal AND the fresh aggregate is at-or-above. Self-priming — the aggregator re-caches the fresh value so the next read doesn't re-aggregate.

**Idempotency:** crossing detection compares prior-cached vs fresh-aggregate. Repeated invalidations with the same prior+fresh pair don't re-fire.

## Diagnostics

One new check at `WooCommerce → Donations Companion → Diagnostics`:

- **Cause goals not orphaned** — info-level. Auto-passes silently when the master toggle is off. Otherwise scans for campaigns where per-cause goals are configured but parent's `wc-donation-cause-display-option` is `disabled`. The combination means the goals will never apply because parent isn't rendering the cause picker. Warns with the count + sample campaign IDs and the remediation: "enable parent's Donation Cause display setting OR clear the per-cause goals from the meta box."

## Limitations + documented constraints

1. **No backfill of legacy orders.** Orders placed pre-v2.4.0 don't carry our `_dfwc_companion_cause_id` line-item meta; raised totals fall back to name-matching for those. If you rename a cause AFTER v2.4.0 ships, the new name's name-match aggregation only sees orders placed before the rename — historical-cause-id-matching catches the rest. Documented degraded behavior; admins are NOT expected to edit historical orders.

2. **Recurring renewals are exempt from closure enforcement.** A donor who set up a monthly sustainer to cause "Education" before it closed continues to renew normally. Companion does NOT auto-cancel matching subscriptions when a cause closes. Admins who want this behavior can listen on the `dfwc_companion_cause_closed` action and cancel matching subscriptions explicitly.

3. **No per-cause progress bars on the donor form yet.** Visualization of how-much-raised-per-cause would help donors. v2.5+ candidate; needs UX design + an extra render-time aggregation pass we'd want to bake into the existing form-config payload.

4. **Cross-currency cause goals.** Goals are denominated in WC base currency. If the store's base is USD and a donor gives £700 GBP, the conversion-time WC behavior determines what counts toward the cause's USD-denominated goal. Documented limitation; per-currency goals are a v2.5+ enhancement.

5. **Refunds are NOT subtracted from raised totals.** Matches parent's own raised-tally semantics. A large refunded donation continues to count toward closure. Admins who refund a significant donation should manually adjust the goal target. Refund-aware aggregation is a v2.5+ refinement; needs a hook on `woocommerce_order_status_refunded` to invalidate then re-aggregate excluding refunded amounts.

6. **Cause names with HTML entities.** Parent's storage layer doesn't HTML-escape cause names; if your cause names contain `<` / `>` / `&`, the donor-side cause-picker selector matching may fail. Stick to plain text in cause names.

7. **Single SQL aggregate per (campaign, cause).** With many causes per campaign + many campaigns, the closure renderer may issue dozens of queries per donor render (mitigated by the 5-min transient + per-request memo). For sites with 50+ causes per campaign, profile carefully. Realistic nonprofit usage is 3-7 causes per campaign — well within performance budget.

## Troubleshooting

### Cause shows as open even though raised >= goal

- Open `WooCommerce → Donations Companion → Diagnostics` — does **Cause goals not orphaned** warn?
  - If yes: the campaign has goals configured but parent's cause-display-option is disabled. Either enable parent's display option (causes show) or clear the per-cause goals (no tracking).
- Verify the cause is "Track"-checked AND has `goal_amount > 0` in the per-cause meta box.
- Verify the campaign has a linked WC product (`wc_donation_product` post meta). Without one, raised aggregation returns 0.
- Check the transient cache: `dfwc_cause_raised_<campaign_id>_<md5(cause_id)>`. If stale, place a small test order and let the invalidation hooks fire (or manually run `wp transient delete-all` via WP-CLI).

### Cause shows as closed but raised < goal

- Did you change the goal amount recently? Check the meta box value.
- Goals are denominated in WC base currency. A multi-currency store may have donors in non-base currencies; their conversion-time amount is what counts. Verify the store base currency.

### Donor's "Donate" button click does nothing on closed cause (mode B)

- Browser console — does it show `[dfwc] failed to parse data-closed-causes JSON; bailing`? If so, the wrapper's `data-closed-causes` attribute is malformed. File a bug.
- Verify the cause name in parent's "Donation Cause" matches the `<li data-name="...">` in the rendered cause picker. Cause renames after v2.4.0 ships preserve the companion ID but the donor-side click handler matches by name — so a renamed-but-not-republished campaign can mismatch. Re-save the campaign to refresh the rendered HTML.

### Recurring renewal failed for a closed cause

- This is by design. Submit_Guard's closure check only runs in parent's `donation_to_order` AJAX action — recurring renewals route through WC's subscription pipeline, not through this AJAX. Renewals continue normally.
- If a renewal IS failing, the cause closure isn't the cause — check WC subscription log + Stripe gateway log.

### `dfwc_companion_cause_closed` action didn't fire when expected

- The action fires inside `Cause_Raised_Aggregator::invalidate` only when the PRIOR cached raised was below the goal AND the fresh aggregate is at-or-above. If the prior cached value was already at-or-above (e.g., goal was reduced after donations had been recorded), no crossing is detected and no action fires.
- The first invalidation after install has no prior baseline; a stale-prior crossing detection is skipped on that path.
- Verify your listener is hooked at the right priority (10 by default) + correct arg count (4).

## Architecture notes

For developers integrating against this surface, see the technical plan at [`plans/v2/cause-aware-giving.md`](../plans/v2/cause-aware-giving.md). Key modules:

- `Config\Cause_Identity` — companion-managed UUIDs threaded through cart → order
- `Config\Cause_Goals_Schema` — per-cause goal storage + closure-mode definitions
- `Config\Cause_Raised_Aggregator` — 3-layer cache (memo + transient + SQL aggregate, HPOS-aware)
- `Config\Cause_Closure_Service` — composite read surface for closure decisions
- `Frontend\Submit_Guard` (extended) — server-side trust boundary
- `assets/js/dfwc-overlay.js` (extended) — client-side mode-specific UX

The internal pattern (channel/feature owns its own renderer + REST/UX + Phase 9 fire) is consistent with v2.3.0 crypto and v1.3.0 stock. v3.0.0 will refactor these into the formal `Donor_Channel` interface (per [`plans/v2/v3-channel-api-and-extraction.md`](../plans/v2/v3-channel-api-and-extraction.md)).

---

*This document covers v2.4.0. Pair with [`docs/architecture/current-state.md`](architecture/current-state.md) for system-level context.*
