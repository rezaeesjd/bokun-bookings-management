---
name: tour-analytics
description: >-
  Design, extend, and debug the tour-booking analytics dashboard in this
  WordPress plugin (Bokun Bookings Management) — the Analytics tab of the
  [bokun_booking_dashboard] shortcode, its source tables, and the net-revenue
  join. Use this skill whenever the work touches analyzing tours/bookings:
  adding or changing a metric, KPI, chart, filter, or column on the analytics
  dashboard; adding a field to the analytics source; anything about net
  revenue, net price, commission, partner page, product/channel performance,
  or "which tour performs best"; or changing how bookings are aggregated for
  reporting. Trigger it even when the user says "the analytics tab", "the
  dashboard", "tour performance", or "the report" without naming files — this
  dashboard is what they mean. Load it before editing render_analytics_panel()
  or the bokun-analytics / bokun-partners-products data layers so the
  established data model, net-revenue formula, palette, and review gotchas are
  honored instead of reinvented.
---

# Tour analytics dashboard

This plugin turns Bokun bookings into a business-performance dashboard for a
tour operator. This skill is the map: where the pieces live, the data model,
the conventions that keep the UI consistent, and the review findings already
paid for once — don't rediscover them.

## Architecture at a glance

Three layers, each in its own file under `includes/`:

1. **Source table** — `bokun-analytics.php` builds `wp_bokun_analytics_source`,
   one flat row per `bokun_booking` post (last 3 months by creation date). It
   denormalizes the flattened post meta + `booking_status` taxonomy into
   query-ready columns. Kept current on import, on dashboard status edits, and
   pruned as rows age out; rebuilt in full from the **Analytics Data** admin
   screen.
2. **Partners catalog** — `bokun-partners-products.php` builds
   `wp_bokun_partners_products`, one row per product (`product_id`, `title`,
   `net_price`, `commission`, `departure_city`, `partner_page_id`). Seeded from
   `includes/data/partners-products.php`; `partner_page_id` is resolved from
   each product tag's `partnerpageid` term meta.
3. **Dashboard UI** — `BOKUN_Shortcode::render_analytics_panel()` in
   `includes/bokun_shortcode.class.php`. It is the **Analytics** tab;
   `wrap_dashboard_tabs()` puts the existing bookings dashboard in the first
   tab and this panel in the second. The panel ships the source rows + the
   partners map as inline JSON and does all filtering, joining, aggregation and
   charting **client-side**.

`references/data-model.md` has the full column list and the booking-meta
mappings. Read it before adding or renaming a source field.

## The core business metric: net revenue

Net revenue is the operator's margin and the reason the partners catalog
exists. Per line:

```
net_revenue = gross_amount − (net_price × participants)
```

- `gross_amount` is `price_amount`, parsed best-effort from the free-text price
  note (`productBookings_0_notes_1_body`). The raw note is kept alongside.
- `net_price` is per person; `participants` = adult + child + infant.
- It is only defined when the line has a gross amount **and** its product is in
  the partners catalog; otherwise leave it null (don't coerce to 0 per row).
- The catalog join is best-effort: match the booking's `product_id`, then its
  `product_external_id`, then an exact normalized title — because channel
  (e.g. Viator) bookings may carry a different id or title casing than the
  catalog. The Analytics Data admin screen shows a catalog-match % and a
  sample of unmatched booking products so a bad/partial catalog is visible.

Revenue and net-revenue are money, so they are **aggregated in a single
currency** — the most common one in the filtered set (`revCur`). Rows in other
currencies contribute 0 to those metric sums, and the chart titles show the
active currency and flag mixed sets. Never sum `price_amount`/net revenue
across currencies as if interchangeable.

## Conventions that keep the dashboard coherent

- **Everything client-side.** PHP emits the rows, the partners map, dimension
  lists and i18n strings as `<script type="application/json">`; the inline JS
  filters/aggregates/draws. No AJAX round-trips, no external chart libraries.
  The analytics tab renders lazily on first open (see `init()` /
  `bokunAnalyticsInit`), so hidden tabs cost nothing.
- **Charts are inline SVG + CSS bars.** No CDN dependency (the plugin runs on
  arbitrary sites). Resolve CSS custom properties to concrete hex in JS before
  putting them in SVG attributes — `fill="var(--x)"` does **not** work in SVG
  presentation attributes (see `trendColors()`).
- **Palette** follows the data-viz guidance: a colorblind-safe categorical set
  and status colors (full = good/green, partial = warning/amber,
  not-available = critical/red), defined as CSS vars on `.bokun-andash` with a
  dark-mode block. Reuse the vars; don't hardcode new hues.
- **Escape for HTML built via innerHTML.** `escHtml()` escapes `& < > " '` so a
  product/channel label can't break out of a `title="…"` attribute. Use it for
  every interpolated value, including attribute values.
- **i18n**: user-facing strings go through `__()` in PHP (text domain
  `BOKUN_txt_domain`) and are passed to the JS in the `L` object — keep JS free
  of hardcoded English.
- **Validate before every push** (there is no CI): `php -l` each changed PHP
  file, and syntax-check the inline panel JS with `node --check` by extracting
  the last `<script>` block and stubbing the one `<?php echo esc_js($uid) ?>`.
  The scratch harnesses in prior work show the pattern.

## How to extend it

### Add a source field (column on `wp_bokun_analytics_source`)
1. Add the column to the `CREATE TABLE` in `bokun_analytics_install_table()`
   and **bump `BOKUN_ANALYTICS_DB_VERSION`** (admin_init re-runs dbDelta).
2. Populate it in `bokun_analytics_build_row()` from the booking meta
   (`bokun_analytics_meta()` resolves the first non-empty candidate key; see
   `references/data-model.md` for key names). Add a test in the analytics
   harness.
3. If it should be filterable/groupable, add it to `$facets` or `$more` (for
   the sidebar) and it flows through automatically.

### Add a metric to the toggle
Extend `metricVal()` (and `metricLabel()`/`isMoney()`), add a `[data-metric]`
button. If it's money, scope it to `revCur` like revenue/net-revenue.

### Add a filter
Important dimensions are **clickable multi-select item lists** in the left
sidebar (`$facets`); secondary ones are dropdowns under "More filters"
(`$more`). Both feed one `f.dims` map of arrays that `matches()` checks by
membership. Add the key + label to the right array — the item list / dropdown
and the group-by option are generated from it.

### Add a chart
Follow `renderTrend` (SVG line/area with hover crosshair) or `barList`
(horizontal CSS bars) rather than introducing a chart library. Render into a
`[data-…]` container inside `recompute()`.

## Review gotchas already found and fixed — don't regress them

These came out of code review on earlier PRs. Keep them true:

- **Activation vs `init`.** `product_tags` registers on `init`, which does
  **not** run during the plugin activation hook. So the partners catalog seed
  (which resolves `partnerpageid`) runs on the first `admin_init`, not in the
  activation callback. Any term-meta lookup during activation returns
  `invalid_taxonomy`.
- **Currency.** Never aggregate money across currencies (see net-revenue
  section). KPI tiles that show per-currency totals are the exception and group
  explicitly.
- **Bar widths.** `barList()` sizes bars by `g.metric / max`; when a chart
  displays counts, set `g.metric = g.count` so width matches the shown value
  (otherwise a count-labelled bar sized by a revenue metric overflows).
- **Single bucket.** The trend renders a single weekly point when only one
  bucket exists; reserve the "No data" message for truly empty sets.
- **Honest labels.** The full/partial/not-available split is the *result*
  field, not payment — label it accordingly.
- **Travel GMT.** Derive travel datetime from the raw `_original_start_datetime`
  meta via `bokun_analytics_raw_to_gmt()`, not `post_date_gmt`, which is
  double-shifted on non-UTC sites.

## After deploying changes
Tell the user to run **Analytics Data → Rebuild now** (bookings source) and
**Rebuild partners products** (catalog + partner ids) once, since net revenue
needs both populated.
