# Campaign Directory

> Available from **v0.8.0**. A donor-facing browseable grid of campaigns with search, filters, sort, and pagination — built on top of six campaign taxonomies.

If your nonprofit runs many campaigns simultaneously (a campaign-per-program model is common in education, missions, or relief organizations), the parent plugin gives donors a single-campaign permalink but no way to **discover** other campaigns. The directory grid solves that.

```
┌──────────────────────────────────────────────────────────────┐
│  Search: [______________]    Filter: Cause ▾  Region ▾  ...  │
│ ─────────────────────────────────────────────────────────    │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐                    │
│  │ [image]  │  │ [image]  │  │ [image]  │                    │
│  │ School   │  │ Medical  │  │ Construct│                    │
│  │ in Kenya │  │ in Haiti │  │ Project  │                    │
│  │ ★Featured│  │          │  │          │                    │
│  └──────────┘  └──────────┘  └──────────┘                    │
│                                                              │
│  [< Prev]    Page 1 of 4    [Next >]                         │
└──────────────────────────────────────────────────────────────┘
```

Each card links to that campaign's permalink, where the standard donor-form-overlay flow takes over.

---

## Three integration points

Same configuration surface in all three:

### Shortcode

```
[dfwc_campaign_grid]
```

Optional attributes:

```
[dfwc_campaign_grid
    cause="education,medical"
    country="kenya"
    featured="1"
    layout="grid"
    per_page="12"
    orderby="featured"
    show_filters="1"
    show_search="1"]
```

### Gutenberg block

Search for **"Donation Campaign Grid"** in the block inserter. The block exposes the same attributes via the InspectorControls sidebar.

### Elementor widget

If Elementor is installed, search for **"Donation Campaign Grid"** in the widget panel.

All three call the same `Frontend\Campaign_Directory_Renderer::render( $args )` so the donor-side output is identical.

---

## Six taxonomies

Registered against the `wc-donation` post type:

| Taxonomy | Slug | Purpose |
|---|---|---|
| Cause Category | `dfwc_cause` | Education, Medical, Food, Construction, Missions, Leadership Training, Discipleship |
| Region | `dfwc_region` | Continent / world region (Africa, Asia, Latin America, etc.) |
| Country | `dfwc_country` | ISO country names |
| Program | `dfwc_program` | Internal program / project name (e.g., "Bible School Network") |
| Sponsorship Type | `dfwc_sponsorship_type` | School / Classroom / Student / Pastor / Teacher / Church / Missionary |
| Urgency | `dfwc_urgency` | Normal / Priority / Urgent |

### Default starter terms

On first activation, the companion seeds default terms for the three "fixed" taxonomies (Cause, Sponsorship Type, Urgency). The other three (Region, Country, Program) start empty — admins fill in what's relevant for their organization.

The seeding flag is stored in the `dfwc_companion_terms_seeded` option. Once seeded, never re-runs (so your custom edits to the starter terms are safe).

To reset and re-seed, delete the `dfwc_companion_terms_seeded` option:

```bash
wp option delete dfwc_companion_terms_seeded
# Then re-activate or re-trigger seeding via:
wp eval "( new \DFWC\Companion\Taxonomy\Campaign_Taxonomies() )->seed_terms_if_needed();"
```

### Editing terms

Standard WordPress UI — under the campaign list (`Donations`) you'll find six taxonomy edit screens. Add, edit, delete, and assign-to-campaigns work identically to categories and tags.

---

## Per-campaign classification

Edit a campaign → side meta boxes for each taxonomy. Tick the relevant terms. The taxonomies appear in any directory grid that filters or sorts on them.

Plus the **"Featured campaign"** side meta box (Phase 4) — a checkbox that drives the "Featured first" sort option in the grid.

---

## Filter UI

When `show_filters="1"` (default in shortcode/block/widget), the grid renders a filter bar above the cards:

- **Search box** (text) — full-text against campaign title + content
- **Per-taxonomy dropdowns** — one for each taxonomy with `show_filter="1"` in the shortcode args
- **Featured-only toggle** — checkbox
- **Sort dropdown** — Date (default) / Featured first / Title A→Z / Random

The filter bar is **progressive enhancement**: with JavaScript enabled, changing any filter triggers a debounced REST call (`/wp-json/dfwc-companion/v1/grid`) that returns just the rendered grid HTML, swapped in place via DOM mutation. Browser URL updates via `history.replaceState` so deep links continue to work. Without JavaScript, the filter form submits normally — full page reload.

---

## REST live search

`GET /wp-json/dfwc-companion/v1/grid?cause=education&country=kenya&page=2`

Public read endpoint, rate-limited to 60 requests per IP per minute (hashed-IP transients). Returns:

```json
{
    "html": "<div class='dfwc-directory'>...</div>",
    "rendered_at": 1714350000
}
```

The `html` field is the full grid + pagination markup, ready to swap into the page. The companion's `dfwc-directory.js` handles the swap, debounce, and URL update.

Defenses:

- Permission callback: `__return_true` (public — donor browsing is public-by-design)
- Rate limit: 60 req/min/IP (hashed via `wp_hash`)
- Schema-validated args (the WP REST API does this automatically when args are declared)
- Output is HTML escaped at construction time — no untrusted strings reach the donor browser

---

## Layouts

Two layouts:

### `grid` (default)

CSS Grid with responsive breakpoints. Cards arranged 3-up on desktop, 2-up on tablet, 1-up on mobile. Featured campaigns get a subtle border accent.

### `list`

Stacked rows with image-left + content-right. Better for sites with long campaign descriptions where the full description belongs in the listing.

```
[dfwc_campaign_grid layout="list"]
```

Both layouts share the same card content. If you need to customize the card HTML, override the `templates/directory-card.php` template via your theme's `dfwc-companion/` subdirectory (standard WP template-overrides pattern).

---

## Card content

By default, each card shows:

- **Featured image** (if set on the campaign)
- **Campaign title** (linked to permalink)
- **Excerpt** or first 25 words of content
- **Featured badge** (if `is_featured` meta is set)
- **Goal progress** (if the parent plugin's goal is configured)

Plus optional taxonomic chips (cause, country, etc.) you can enable via the `card_taxonomies` shortcode attr:

```
[dfwc_campaign_grid card_taxonomies="cause,country,urgency"]
```

---

## Sort options

| Value | Behavior |
|---|---|
| `date` (default) | Latest first by `post_date` |
| `featured` | Featured campaigns first; tie-break on `menu_order`, then `post_date` |
| `title` | Alphabetical by title |
| `rand` | Random order (uses MySQL `RAND()`; expensive on large catalogs — cache via Object Cache if you have it) |
| `menu_order` | Manual ordering via the campaign edit screen's `menu_order` field |

Featured-first sort puts cards with `_dfwc_companion_featured = '1'` post meta at the top. Set via the **"Featured campaign"** side meta box on the campaign edit screen.

---

## WPML support

All six taxonomies are declared `translate="1"` in `wpml-config.xml`. WPML's Translation Management handles per-term translation natively:

1. WPML → Translation Management → Translatable Content → Categories & Custom Taxonomies
2. Tick the dfwc_* taxonomies you want translated
3. Each taxonomy term becomes translatable through WPML's standard term-translation UI

Donor-side, when WCML is active and the donor is browsing in French, the directory grid filters by the French-language version of each campaign. Cross-language directories (showing campaigns from all languages) are possible via:

```
[dfwc_campaign_grid lang="all"]
```

The `lang="all"` shortcode attribute overrides WPML's default per-language filtering.

---

## Multilingual / multi-currency

When WCML is active:

- Filter values translate per donor's active language
- Card thumbnails / titles / excerpts use the donor's-language version
- "Featured" badge translates via the standard text-domain flow

When the donor switches currency mid-browse, the grid does NOT re-render (currency context only matters at donate time, not at browse time). The donor-form overlay JS picks up the active currency when the donor lands on a single campaign permalink.

---

## Programmatic filtering

The companion's `Taxonomy\Campaign_Query_Builder` translates filter arrays into `WP_Query` args. For sites that need custom filter UI (e.g., a faceted-search plugin):

```php
use DFWC\Companion\Taxonomy\Campaign_Query_Builder;

$args = ( new Campaign_Query_Builder() )->build( array(
    'cause'    => 'education',
    'country'  => 'kenya',
    'featured' => true,
    's'        => 'school',
    'orderby'  => 'featured',
    'page'     => 1,
    'per_page' => 12,
) );

$query = new WP_Query( $args );
```

The builder handles tax_query construction (with `IN` operator for array term values), search passthrough, orderby allow-listing, and per-page clamping. Filter hooks let you customize:

```php
// Add an additional meta_query to every grid query.
add_filter( 'dfwc_companion_grid_query_args', function ( $args, $filters ) {
    $args['meta_query'][] = array(
        'key'     => 'campaign_active',
        'value'   => 'yes',
    );
    return $args;
}, 10, 2 );
```

---

## Caching

The grid HTML is **not** cached server-side. Every request hits the REST endpoint and re-runs `WP_Query`. Reasons:

- Donor expectations: filter changes should reflect immediately
- WPML: per-language caches multiply complexity
- Featured campaigns: admins want to see featured updates without cache busts

If you have heavy traffic, add object caching at the WP level (Memcached / Redis via `wp_cache_*`). The directory's `WP_Query` results are eligible for the standard query cache. Don't add a fragment cache on the rendered HTML unless you have very specific requirements.

---

## Customization

### Override the card template

Copy `templates/directory-card.php` into your theme at `wp-content/themes/your-theme/dfwc-companion/directory-card.php`. WordPress's standard template-locator finds it.

### Filter the card data

```php
add_filter( 'dfwc_companion_grid_card_data', function ( $data, $campaign_id ) {
    $data['custom_field'] = get_post_meta( $campaign_id, 'my_custom_field', true );
    return $data;
}, 10, 2 );
```

The data array is what `templates/directory-card.php` receives. Add your custom fields here.

### Style the grid

All grid CSS is scoped under `.dfwc-directory`. Override via your theme's CSS without specificity wars:

```css
.dfwc-directory__card { border-radius: 16px; }
.dfwc-directory__featured-badge { background: #ff6b00; }
```

The base `assets/css/dfwc-directory.css` is intentionally minimal — admins are expected to skin it.

---

## Common pitfalls

### "I don't see the directory grid block in the inserter"

The block registers at `init` priority via `register_block_type`. If your block-editor JS bundle is heavily cached, force-reload the editor (`Ctrl+Shift+R`).

### "Filter values aren't translating in WPML"

WPML's term-translation runs separately from string-translation. Visit *WPML → Translation Management → Categories & Custom Taxonomies*; tick the dfwc_* taxonomies; then translate each term via the standard term-translation UI.

### "Live search hits 429 rate-limit errors"

Default rate limit is 60 req/min/IP. If a single user is hitting that limit through legitimate use (very fast typing, no debouncing), the JS isn't debouncing properly. Verify `assets/js/dfwc-directory.js` is loading the latest version (cache-bust via `?ver=` param on the script tag, which ships from the plugin version constant).

### "Featured-first sort isn't putting featured campaigns first"

Two causes:

1. The `_dfwc_companion_featured` meta isn't set. Edit the campaign and tick the "Featured campaign" side meta box.
2. Your theme's `pre_get_posts` filter is overriding our `orderby` arg. Check theme functions.php for `pre_get_posts` listeners that match `wc-donation` post type.

---

## Storage

| Key | Where |
|---|---|
| `_dfwc_companion_featured` | Post meta on `wc-donation` posts; `'1'` for featured, absent otherwise |
| `dfwc_companion_terms_seeded` | Single option (autoload off); `'1'` once initial term seeding has run |
| Six taxonomies | `wp_terms` + `wp_term_taxonomy` + `wp_term_relationships` (standard WP taxonomy tables) |

---

## Privacy

The directory grid serves only **public** campaign data — same data anyone can see by browsing the wc-donation single-campaign permalinks one by one. No donor info, no order data, no aggregate counts. The REST endpoint's `__return_true` permission is appropriate.
