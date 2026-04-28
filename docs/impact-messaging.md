# Donor Impact Messaging

> Available from **v0.9.0**. Turn abstract dollar amounts into concrete outcomes — "$25 = school supplies for one student" — so donors see the impact of their gift before they give. Four display modes, featured-preset badges, per-interval subtitles, live annual-equivalency text.

The companion ships with messaging primitives admins fill in to differentiate "another donation form" from "a campaign that converts." None of these are mandatory; the v0.6.x form behavior is preserved verbatim until admins fill in any of the new fields.

---

## What's available

| Field | Where | Translatable? |
|---|---|---|
| Per-preset `impact_label` | Each preset row | ✅ via WPML |
| Per-preset `is_featured` | Single boolean per interval; the "Most popular" preset | server-side enforced single-featured |
| Per-interval `subtitle` | Above the preset grid | ✅ via WPML |
| Per-interval `annual_equivalency` | Below the form, with token substitution | ✅ via WPML |
| Per-interval `custom_amount_impact_label` | Alongside the donor's custom-amount input | ✅ via WPML |
| `impact_display_mode` | Per-interval radio: how the impact_label renders | not applicable |

---

## Per-preset impact labels

Free-form text shown alongside each preset amount. Common patterns:

- `$25 → "Provides school supplies for one student"`
- `$100 → "Sponsors a teacher for a month"`
- `$500 → "Builds a classroom with desks and books"`

Configure on the campaign edit screen → **Interval-First Donation Form** meta box → for each preset row, fill the **"Impact label"** column. Or set at the template level via *Donations Companion → Templates*.

### Four display modes

The `impact_display_mode` per-interval setting controls how each preset's impact label renders to the donor:

| Mode | Visual | Best for |
|---|---|---|
| **`below_button`** *(default)* | Full-width row beneath each preset button. | Easiest to read; doesn't crowd the button. |
| **`inline`** | Subtext within the preset button. | Compact for tight layouts (sidebar widgets, narrow columns). |
| **`tooltip`** | CSS tooltip on hover/focus. Includes `aria-describedby` for screen readers. | Interfaces where impact text is supplemental, not primary. |
| **`card`** | Each preset becomes a self-contained card with image / amount / impact label. | High-engagement landing pages where the amount picker is the page's focal point. |

Pick the mode at the per-interval level (Monthly tab might be `card`, One-time might be `inline`). The donor-side overlay JS reads the mode from the `data-config` attribute and switches DOM structure accordingly.

```
┌─────────────────────────────────────┐ ┌─────────────────────────────────────┐
│  ○ $25                              │ │  ○ $25                              │
│ ─────────────────────────────────── │ │     Provides school supplies        │
│  ○ $50                              │ │ ─────────────────────────────────── │
│ ─────────────────────────────────── │ │  ○ $50                              │
│  ○ $100                             │ │     Sponsors a teacher              │
│                                     │ │ ─────────────────────────────────── │
│ (default — no impact text)          │ │ (mode = below_button)               │
└─────────────────────────────────────┘ └─────────────────────────────────────┘

┌─────────────────────────────────────┐ ┌─────────────────────────────────────┐
│  ○ $25  School supplies             │ │  ┌──────────┐  ┌──────────┐         │
│  ○ $50  Sponsors a teacher          │ │  │   $25    │  │   $50    │         │
│  ○ $100 Builds a classroom          │ │  │  School  │  │ Sponsors │         │
│                                     │ │  │ supplies │  │  teacher │         │
│ (mode = inline)                     │ │  └──────────┘  └──────────┘         │
│                                     │ │ (mode = card)                       │
└─────────────────────────────────────┘ └─────────────────────────────────────┘
```

---

## Featured presets

Mark **one preset per interval** as featured to draw donor attention. The featured preset gets:

- A **"Most popular"** badge (translatable via the standard `dfwc-companion` text domain)
- A subtle border accent (CSS-styled, customizable)
- Slight shadow / elevation in `card` mode

Configure on the campaign or template edit form: tick the **"Featured"** checkbox in the preset row.

**Single-featured-per-interval is enforced server-side.** If an admin accidentally ticks multiple featured boxes (or pastes a config with multiple featured presets), the sanitizer keeps the first checked one and unsets the rest. Last-write-wins on duplicates within the array.

The badge is text-substituted via `__()` so it translates with WPML's String Translation under the `dfwc-companion` text domain.

---

## Per-interval subtitle

A free-form line of copy above the preset grid:

```
[ Become a monthly sponsor ]
─────────────────────────
○ $25  ○ $50  ○ $100
```

Configure: campaign / template edit screen → for the relevant interval tab → **"Subtitle"** field.

Translatable via WPML String Translation. The `Donations Companion` domain registers the subtitle under key `<namespace>.<interval>.subtitle` where `<namespace>` is the template ID (or `_campaign:<id>` for per-campaign overrides, `_global` for global defaults).

Common patterns:

- Monthly tab: "Become a monthly sponsor"
- Annual tab: "Make a year-end commitment"
- Custom interval (e.g., quarterly): "Quarterly support keeps the lights on"

---

## Annual equivalency

A free-form line below the form with token substitution:

```
$25/month equals $300/year
```

The `{amount}` and `{annual_amount}` tokens get substituted live as the donor changes amounts (overlay JS does the substitution client-side). For non-monthly intervals, the multiplier adjusts:

| Interval | Multiplier |
|---|---|
| Monthly | 12 |
| Weekly | 52 |
| Quarterly | 4 |
| Semi-annually | 2 |
| Annual | 1 |

Configure: campaign / template edit screen → for the relevant interval tab → **"Annual equivalency"** field. Most useful on the Monthly tab; the multipliers also work for Phase 7's weekly/quarterly/semi-annual cadences.

Common patterns:

- Monthly: `{amount}/month equals {annual_amount}/year`
- Weekly: `{amount}/week is {annual_amount} a year — 52 weeks of impact`
- Quarterly: `{amount} every 3 months adds up to {annual_amount} annually`

---

## Custom-amount impact label

When the donor types a custom amount (instead of picking a preset), per-preset impact labels don't apply — the donor's amount doesn't match any preset's impact tier. Use the **"Custom-amount impact label"** field for messaging that works at any donation amount:

```
┌─────────────────────────────────┐
│  Custom amount: $ [_______]      │
│   Every gift makes a difference  │
└─────────────────────────────────┘
```

Common patterns:

- "Every gift makes a difference"
- "Each $1 provides one meal"
- "Whatever you can give helps"

Configure: campaign / template edit screen → for the relevant interval tab → **"Custom-amount impact label (optional)"** field. Translatable via WPML.

---

## Storage shape

Each interval block in `_dfwc_companion_overrides` (or template config or campaign config) carries:

```php
'monthly' => array(
    'enabled' => true,
    'presets' => array(
        array(
            'amount'       => 25.0,
            'label'        => '',                          // optional preset label ("Supporter")
            'impact_label' => 'Provides school supplies',  // Phase 5
            'is_featured'  => true,                        // Phase 5
            'sort_order'   => 10,
        ),
        // ... more presets
    ),
    'min' => 5.0,
    'max' => 1000.0,
    'default_index' => 0,
    'cta_template' => 'Donate {amount}/month',

    // Phase 5 fields
    'subtitle'                   => 'Become a monthly sponsor',
    'annual_equivalency'         => '{amount}/month equals {annual_amount}/year',
    'impact_display_mode'        => 'below_button',
    'custom_amount_impact_label' => 'Every gift makes a difference',

    // ... other fields
),
```

All fields default to safe values (empty string / `'below_button'` / `false`) so v0.6.x campaigns continue to render unchanged until admins opt in.

---

## WPML translation paths

When the admin saves a campaign or template, the following strings register with WPML String Translation under the `Donations Companion` domain:

| String | Key |
|---|---|
| `cta_template`, `subtitle`, `annual_equivalency`, `custom_amount_impact_label` | `<namespace>.<interval>.<field>` |
| Per-preset `label`, `impact_label` | `<namespace>.<interval>.presets.<idx>.<field>` |

`<namespace>` is:

- The template ID for template-defined strings (e.g., `school_sponsorship.monthly.subtitle`)
- `_campaign:<id>` for per-campaign override strings
- `_global` for global-defaults strings

Translators visit *WPML → String Translation → Domain: Donations Companion* and translate each string. The resolver translates at render time via `WPML_Strings::translate()`.

---

## A/B testing recipes

Phase 10 (A/B testing) was deferred from the v2 plan, but Phase 5's primitives compose well for ad-hoc experiments:

### Recipe: test impact_label vs. no-label

Define two templates:

- `template-a-with-impact` — impact_labels filled in
- `template-b-control` — same presets, no impact_labels

Assign half your campaigns to each via the bulk apply action. After two weeks, compare the donor-flow event counts (`dfwc_companion_donation_submitted`) between campaigns by template. Whichever template's campaigns convert more, that's your winner.

### Recipe: test card mode vs. below_button mode

Same approach — two templates differing only on `impact_display_mode`. Apply via bulk action. Compare conversion via the analytics tool you wired up in [`event-hooks.md`](event-hooks.md).

For programmatic A/B variant assignment without two-template duplication, the `dfwc_companion_resolved_config` filter lets you mutate the resolved config based on a cookie / session flag:

```php
add_filter( 'dfwc_companion_resolved_config', function ( $config, $campaign_id ) {
    if ( isset( $_COOKIE['ab_variant'] ) && 'b' === $_COOKIE['ab_variant'] ) {
        foreach ( array( 'one_time', 'monthly', 'annual' ) as $key ) {
            $config[ $key ]['impact_display_mode'] = 'card';
        }
    }
    return $config;
}, 10, 2 );
```

Set the cookie via your A/B framework (a simple snippet that flips it 50/50 on first page-load works fine). Track conversions via the event hooks. This avoids storing variants in the DB.

---

## Best practices for impact copy

Based on what works for nonprofits using the companion:

1. **Be specific.** "Helps a child" is weaker than "Provides school supplies for one student for a year." Specificity is what makes the impact concrete.
2. **Match the amount to a real outcome.** $25 = school supplies. $100 = teacher's salary for a week. $500 = building a classroom. Tie each preset to something the donor can visualize.
3. **Use present tense.** "Provides school supplies" beats "will provide school supplies." Present tense feels immediate.
4. **One short sentence per preset.** Long impact labels crowd the form. If you can't say it in 8 words, the preset amount is wrong (split into two presets).
5. **Featured = the suggested gift.** The featured preset should match the average donor's "I want to do something meaningful but not break the bank" amount — typically the middle preset.
6. **Don't moralize.** "Every dollar matters" tends to underperform "Provides X." Donors want concrete outcomes, not platitudes.
7. **Annual-equivalency text is psychological pricing.** "$25/month is $300/year" makes the donor see the full impact; some convert higher because of it. Some convert lower because the bigger number feels intimidating. Test on your audience.

---

## Performance

Impact-label rendering is part of the same DOM tree as the rest of the donor form. Adding 2–3 sentences of impact text per preset doesn't measurably affect page load. The donor-side `data-config` JSON does grow slightly (a few KB per campaign with full impact data) — well within typical HTML page sizes.

The `tooltip` mode adds a small CSS-only tooltip; no extra JS, no impact on Core Web Vitals.

The `card` mode adds image-loading time IF you add per-preset images (currently not supported in Phase 5 — preset cards reuse the campaign's featured image). Future v1.x might add per-preset images; until then, all four display modes have equivalent network footprint.

---

## What this doesn't include

- **Impact images per preset** — not in Phase 5. Future work; scope is significant (image upload UX + responsive rendering).
- **Conditional impact labels by donor segment** — Phase 5's labels are global per-preset. For donor-segmented copy (e.g., showing "Sponsors a child for a year" to Africa donors and "Provides healthcare access" to South Asia donors), use the `dfwc_companion_resolved_config` filter to mutate the resolved config based on geo / cookie / referrer.
- **Goal-progress impact** — "We've raised $X of $Y goal" is parent-plugin territory, not companion. Use parent's progress-bar shortcode alongside the donor form.
- **Donor wall / leaderboard recognition** — also parent-plugin territory.

The companion's role is the **moment of decision** — donors picking an amount and clicking Donate. Everything before (campaign discovery, mission storytelling) and after (thank-you, recognition, recurring renewal) lives in the parent plugin or your CMS / CRM.
