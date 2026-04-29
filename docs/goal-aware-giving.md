# Goal-Aware Giving

> Available from **v1.2.0**. Two opt-in features that read the parent plugin's campaign goal and use it to shape donor behavior:
>
> 1. **Dynamic max** — clamp the donor's custom max amount to the campaign's remaining goal so a one-time donor can give up to fully funding the campaign but no more.
> 2. **Fully-funded redirect** — when a campaign meets its goal, surface a "Goal met! Support our general fund" card so donors can keep giving without overshooting the funded campaign.

Both features are off by default. Existing v1.1.x sites see no behavior change until an admin opts in via *WooCommerce → Donations Companion → Settings → Goal-aware giving*.

---

## Why these features exist

Two real nonprofit pain points motivate v1.2.0:

**Donor-side pain:** "I want to fully fund this campaign — how much is left?" Currently the donor reads the parent's progress bar and does mental math. With dynamic max, the donor sees the form's max amount auto-update to remaining-goal, and any custom amount above that is rejected.

**Admin-side pain:** "Our school-construction campaign hit its goal three weeks ago, but donors keep giving to it. Now we have to manually allocate the overflow." With the fully-funded redirect, the donor is offered the org's general fund as a natural alternative when a specific campaign is closed out.

The features compose — admins typically enable both together. Each can be enabled independently if the org's flow demands it.

---

## What the parent plugin provides

The companion **reads** parent meta — never writes. Parent plugin's goal-tracking surface:

| Meta key | Where | What |
|---|---|---|
| `wc-donation-goal-display-option` | campaign post | Goal feature on/off (any non-empty value = on) |
| `wc-donation-goal-display-type` | campaign post | `fixed_amount` | `percentage_amount` | `no_of_donation` | `no_of_days` | (empty) |
| `wc-donation-goal-fixed-amount-field` | campaign post | The dollar target |
| `wc-donation-goal-fixed-initial-amount-field` | campaign post | A "seed" value added to raised in the progress display |
| `wc_donation_product` | campaign post | Linked WooCommerce product ID |
| `total_donation_amount` | linked PRODUCT (not campaign) | Running tally of donations |

The companion's **`Config\Goal_State`** wraps these reads:

```php
use DFWC\Companion\Config\Goal_State;

$state = Goal_State::for_campaign( $campaign_id );

$state->goal_enabled();     // bool — has the parent's goal feature been turned on?
$state->goal_type();        // string — the parent's goal-type slug
$state->is_amount_goal();   // bool — true only for fixed_amount + percentage_amount with non-zero target
$state->goal_amount();      // float — the target (0 if not amount-goal)
$state->raised_amount();    // float — total_donation_amount + fixed_initial_amount
$state->remaining_amount(); // float — max(0, goal - raised)
$state->is_fully_funded();  // bool — raised >= goal AND amount-goal AND goal > 0
$state->clamp_max($max);    // float — utility for max-clamping in donor + server flow
```

Per-request cached via static map keyed by campaign_id; `Goal_State::reset_cache()` for tests.

---

## Setting up

### 1. Configure the parent plugin's goal first

The companion's features only fire when the parent plugin's goal is actually configured. Edit a `wc-donation` campaign:

- **Goal Display:** turn on
- **Goal Type:** select **Fixed Amount** (or **Percentage Amount** — both work; donation-count and time-bound goals don't have a "remaining dollars" answer)
- **Goal Amount:** enter the target dollar amount
- **Initial Goal Amount:** optional seed value (often used to display a kickoff amount from offline donations)

Save.

### 2. Enable the companion's goal-aware features

*WooCommerce → Donations Companion → Settings → Goal-aware giving:*

- **Cap the donor's max custom amount at the campaign's remaining goal** — turn this on to enable the dynamic max.
- **Reject donor submissions when the campaign is fully funded** — turn this on if you want fully-funded campaigns to refuse donations entirely (donor sees a friendly redirect message instead of being able to over-fund). Default off — the goal-met card surfaces but the form still accepts donations.
- **General fund campaign** — pick the campaign donors should be redirected to when their target is fully funded. Typically named "General Fund," "Where Most Needed," or similar.

### 3. Verify in the donor view

Visit the campaign as a donor (logged out). On the One-time tab, type a custom amount above the remaining goal — the form rejects with a clear message. Type an amount at-or-below remaining — the form accepts.

When a campaign reaches its goal, the donor sees a green card above the form: *"This campaign reached its goal! Want to keep supporting our work? Make a gift to our general fund instead."* The CTA button links to the configured general-fund campaign.

---

## How dynamic max works

```
Configured max (per Phase 6 currency / Phase 3 template):  $10,000
Goal:                                                       $50,000
Raised:                                                     $48,000
Remaining:                                                  $2,000

Effective max (donor sees + server enforces):               $2,000
```

The companion takes the **smaller** of the configured max and the remaining goal. Admins who set explicit max amounts that aren't tied to the goal still get those caps; the goal clamp only tightens further when the campaign is close to fully funded.

### Server-side + client-side enforcement

Both layers apply identical clamping:

- **Donor-side (`Frontend\Renderer::build_form_config`)** — the max value shipped to the overlay JS is already clamped. Donor's custom-amount input rejects values above the clamped max client-side.
- **Server-side (`Frontend\Submit_Guard::enforce`)** — re-validates against the same clamp on submit. A donor who bypasses the client-side check (DevTools, curl) gets HTTP 422.

The shared logic lives in `Goal_State::clamp_max( float $configured_max ): float`. Both call sites invoke this so they can't drift.

### What about recurring intervals?

**Recurring intervals are NOT clamped.** Reasoning: the donor's first charge fits within remaining-goal, but their renewals would exceed it within months. Silently capping renewals to a smaller-than-asked amount is worse than letting the goal be modestly exceeded. Recurring intervals continue to use their configured max from the campaign / template / global layers.

If the campaign is fully funded AND the admin enables the redirect toggle, recurring submits ARE rejected too — at that point the campaign is closed and we shouldn't accept renewing donations.

### What about per-currency presets (Phase 6)?

The goal is denominated in the parent plugin's base currency. The companion's clamp resolves in **base currency** for donors in any currency. A donor in GBP browsing a USD campaign with $2,000 remaining sees the form's max clamped to the GBP-equivalent of $2,000 (computed via WCML's exchange rate at render time, not by us).

Cross-currency edge case: if WCML's rate fluctuates between render and submit, the server-side clamp uses the rate at submit time. Tiny rejections at the threshold can happen; the donor's error message tells them the actual cap.

---

## How the fully-funded redirect works

When `Goal_State::is_fully_funded()` returns true AND the admin has configured a general-fund campaign, the renderer adds two attributes to the overlay wrapper:

```html
<div data-dfwc-overlay-target
     data-fully-funded="1"
     data-general-fund-url="https://example.org/donate/general-fund/"
     ...>
```

The overlay JS reads these and renders a card above the form:

```
┌────────────────────────────────────────────┐
│ This campaign reached its goal!            │
│ Want to keep supporting our work? Make a   │
│ gift to our general fund instead.          │
│                                            │
│ [ Give to the general fund ]               │
└────────────────────────────────────────────┘
[ … donation form below … ]
```

### Default mode: surface, don't block

By default the form **still accepts donations** to the funded campaign — the card is informational. Donors who want to overshoot can; donors who'd rather give to general can.

### Strict mode: block + redirect

Turn on **"Reject donor submissions when the campaign is fully funded"** to enforce strict mode. Submit_Guard rejects donations to the funded campaign with a message pointing to the general-fund permalink. The card still renders too — the donor sees the opt-out before they hit the wall.

Strict mode is the right choice for orgs that close campaigns the moment goals are met (capital campaigns, building projects, time-bound matches). Default mode is right for ongoing campaigns where overflow is welcome.

### What if no general-fund campaign is configured?

The card doesn't render. The donor sees the regular form and can donate to the funded campaign normally (or strict mode rejects with a less-friendly "campaign reached its goal" message). Configure a general fund to unlock the redirect UX.

### What about recurring renewals to a fully-funded campaign?

Existing subscriptions to a fully-funded campaign continue renewing — the companion doesn't touch parent's renewal flow. Strict mode only blocks **new** donations.

If you want to stop renewals when a campaign closes, that's parent / WC Subscriptions territory:

1. Edit the linked WC subscription product
2. Mark it inactive or delete it (renewals fail gracefully)

This is intentional — the companion doesn't reach into the engine's renewal scheduler. Renewal lifecycle is the engine's responsibility.

---

## Edge cases and behaviors

### Goal type is `no_of_donation` or `no_of_days`

The companion **does not clamp**. These goal types track different units (donation count, days remaining) — there's no "remaining dollars" answer. Donor experience is identical to v1.1.x.

### Goal target is 0 or empty

Treated as "no goal set." Companion does not clamp. Identical to having `enable_goal_based_max` off.

### Campaign with no linked product

A newly-published campaign without a WC product yet has no `total_donation_amount` to read. `Goal_State::raised_amount()` returns the initial seed only (or 0). `remaining_amount()` returns the full goal target. The clamp still works — it just clamps to the full target, which is essentially "the campaign is unfunded so the donor can give up to the full target."

### Donor reaches the goal mid-page-load

If a donation lands between the donor's page load and their submit, the donor's locally-clamped max may be stale. Server-side validation catches this: Submit_Guard re-reads `Goal_State` at submit time. The stale donor sees a 422 error with the actual current max. They reload the page, see the goal-met card, and have the option to donate to the general fund.

This is a low-frequency edge case for any single campaign but real on high-volume campaigns. The friendlier behavior would be to show "your gift would exceed the remaining $X — apply $X here and the rest to the general fund?" That's polish for a future v1.2.x release.

### Admin changes the configured max while a donor is on the page

Same story as above — server-side validation reflects the latest. Donor's stale form doesn't crash; just shows a clean error.

### General-fund campaign is itself the campaign being viewed

The renderer guards against this. If the configured general-fund campaign is the same as the one currently being rendered, the goal-met card doesn't appear (would be a confusing self-loop).

### Per-campaign override of these settings?

Currently global only. If you need per-campaign opt-out (e.g., "this specific campaign should accept overflow even though the global toggle says block"), use the `dfwc_companion_resolved_config` filter to mutate behavior per campaign — or file an issue if you have a real use case.

---

## Programmatic control

```php
// Force-enable the goal clamp regardless of admin toggle (e.g., per-domain
// in a multisite where some sites should always clamp).
add_filter( 'option_dfwc_companion_global_settings', function ( $settings ) {
    if ( is_array( $settings ) ) {
        $settings['enable_goal_based_max'] = true;
    }
    return $settings;
} );

// Hide the goal-met card on a specific campaign even when fully funded.
// (You'd implement by short-circuiting general_fund_url to empty.)
add_filter( 'dfwc_companion_resolved_config', function ( $config, $campaign_id ) {
    // ... your logic ...
    return $config;
}, 10, 2 );

// Read the goal state from a custom listener:
use DFWC\Companion\Config\Goal_State;

add_action( 'dfwc_companion_donation_submitted', function ( $campaign_id, $interval, $amount ) {
    $state = Goal_State::for_campaign( $campaign_id );
    if ( $state->is_fully_funded() ) {
        // Notify your CRM that this campaign just closed
        // (this exact donation pushed it over the line, or it was already closed
        // and the admin has strict mode off so this donation accepted anyway).
    }
}, 10, 3 );
```

---

## Storage shape

Goal-aware settings live in the existing `dfwc_companion_global_settings` option (added in v0.7.0):

```php
[
    'version'                      => 1,
    // ... other v0.7+ settings ...
    'enable_goal_based_max'        => false,  // Phase 13
    'enable_fully_funded_redirect' => false,  // Phase 13
    'general_fund_campaign_id'     => 0,      // Phase 13; 0 = none
]
```

`uninstall.php` (when `preserve_data_on_uninstall` is off) wipes the whole option as part of its existing companion-options sweep. No new tables, no new meta keys.

---

## Privacy

Goal-aware features add no new data collection. The goal target is parent's data (admin-supplied). The raised total is parent's running aggregate (orders' line totals, not donor-specific). The general-fund campaign URL is a public WP permalink. No PII flows through these features.

---

## Diagnostics

`WooCommerce → Donations Companion → Diagnostics` doesn't currently surface a goal-aware-specific check. The features depend on:

- Parent plugin active (the `parent_active` check)
- Goal feature configured per-campaign (admin-side concern; not auto-detectable)

If you suspect the clamp isn't firing:

1. Verify *Settings → Goal-aware giving → Cap the donor's max…* is checked.
2. Verify the campaign's parent goal has type **Fixed Amount** (not Number of Donations / Days).
3. Verify the campaign has a non-zero goal target.
4. Inspect the donor form's source — look for `data-fully-funded="1"` or check the `data-config` JSON's `"max"` field for the one-time interval.

Or just call `Goal_State::for_campaign( $id )` from a quick `wp eval`:

```bash
wp eval "var_dump( ( DFWC\Companion\Config\Goal_State::for_campaign( 123 ) )->remaining_amount() );"
```

---

## What this doesn't do

- **Doesn't track donor pledges.** "I'll fund the rest" promises live elsewhere — out of scope for v1.2.0.
- **Doesn't auto-disable the campaign on goal-met.** Parent plugin's campaign post stays published; donor flow continues unless strict mode is on. Admins who want auto-archive should hook into `dfwc_companion_donation_submitted` to set the post status when `Goal_State::is_fully_funded()` flips to true.
- **Doesn't allocate overflow to the general fund automatically.** The donor's overflow decision is voluntary — they click the CTA. Auto-split donations (e.g., "give $100 with $50 to this campaign and $50 to general") is significant scope and not on the roadmap.
- **Doesn't do percentage_amount fancy math.** `percentage_amount` is treated as `fixed_amount` for clamping purposes (the underlying target dollar value drives both). The progress display difference is parent's concern.
