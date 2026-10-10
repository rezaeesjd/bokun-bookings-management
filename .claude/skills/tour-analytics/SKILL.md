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
   screen. The Bokun fetch (`bokun_fetch_bookings`) searches a `startDateRange`
   that now looks **back** as well as forward (default 60 days, filter
   `bokun_booking_start_date_lookback_days`), because the search is keyed on the
   tour's start date: a forward-only window never re-fetches a booking whose
   tour has passed, so a cancellation made after the tour date would otherwise
   never reach the plugin and the booking would linger as "confirmed". A booking
   cancelled in Bokun comes back with a "cancel" `booking_status` term, which is
   what `bokun_analytics_is_cancelled()` keys on.
2. **Partners catalog** — `bokun-partners-products.php` builds
   `wp_bokun_partners_products`, one row per product (`product_id`, `title`,
   `net_price`, `commission`, `departure_city`, `partner_page_id`). Seeded from
   `includes/data/partners-products.php`; `partner_page_id` is resolved from
   each product tag's `partnerpageid` term meta.
3. **Dashboard UI** — `BOKUN_Shortcode::render_analytics_panel()` in
   `includes/bokun_shortcode.class.php`. It is the **Analytics** tab;
   `wrap_dashboard_tabs()` puts the existing bookings dashboard in the first
   tab and this panel in the second. The **Business Results** view
   (`render_business_panel()`) is **embedded inside** the Analytics panel, not a
   separate tab: a `[data-biz-toggle]` button in the toolbar (next to Export
   CSV) swaps `[data-analytics-view]` for `[data-biz-view]` and adds `is-biz` to
   the root (which hides the booking sidebar + metric buttons). The panel ships
   the source rows + the partners map as inline JSON and does all filtering,
   joining, aggregation and charting **client-side**.
4. **Business overhead** — `bokun-business.php` loads the operator's expenses
   from the config file `includes/data/business-expenses.php` (no table, no
   admin CRUD — hand-edited, overridable via the `bokun_business_expenses`
   filter) and the **Business Results** tab combines them with the booking
   margin to show operating profit. See "Business Results tab" below.

`references/data-model.md` has the full column list and the booking-meta
mappings. Read it before adding or renaming a source field.

## Business Results view (overhead + operating profit)

A view inside the Analytics tab (toolbar toggle, not a separate tab) answers
"am I actually making money?" It reuses the **exact** net-revenue (margin)
computation the Analytics tab does — the same rows + partners map, the same
per-row enrichment block copied verbatim into `render_business_panel()`'s JS —
then subtracts the operator's overhead so the margin shown here always matches
the Analytics tab.

- **Expenses are config, not data.** `includes/data/business-expenses.php`
  returns `{ currency, items[] }`. Each item is `name`, `category`, `amount`
  (per period), `quantity`, `frequency` (`monthly`|`yearly`|`one_time`),
  optional `start`/`end` (`YYYY-MM-DD`) and `notes`. `bokun_business.php`
  normalizes them (adds `total = amount × quantity`, a `monthly` and `annual`
  equivalent — both 0 for `one_time`, which lands in its own month instead) and
  exposes `bokun_business_get_expenses()`, `bokun_business_reporting_currency()`
  (default EUR) and `bokun_business_monthly_overhead()`. There is no database
  and no rebuild — editing the file is live on next page load.
- **Single currency, by design.** Everything is the reporting currency (EUR).
  Revenue is scoped to bookings in that currency (`rev = rows where
  currency === CUR`); other-currency bookings are excluded from the P&L (never
  converted, never mixed), consistent with the net-revenue currency rule.
- **The P&L chain is built on catalog-matched bookings** so it is
  arithmetically exact: `G_matched − partnerCost = netMargin` holds because
  `netMargin = Σ _net_revenue` over matched rows and `partnerCost` is defined
  as `G_matched − netMargin`. Unmatched bookings (gross but no catalog net
  price) have `_net_revenue === null`, so they contribute gross but **no**
  margin; they are surfaced separately as "catalog coverage %" and "€X gross
  not in catalog" rather than being silently costed at zero. Operating profit =
  `netMargin − overhead`.
- **Run-rate vs period vs annual.** KPI tiles are the monthly run-rate (fixed
  monthly overhead, avg monthly margin, monthly operating profit, profit per
  booking, break-even bookings/month vs the actual average, catalog coverage,
  annual overhead). The P&L waterfall + statement have a `[Per month | This
  period | Annualized]` toggle (`scaled()`); all three modes preserve the
  gross→partner→margin→overhead→profit identity, and a caption under the toggle
  (`renderPeriodCaption()`) names the actual month range each mode covers (via
  `fmtMonth()`), not just the abstract label. One-off (`one_time`) costs are
  excluded from the run-rate and prorated to their own month in the monthly
  trend only.
- **Charts** follow the same conventions as Analytics: inline SVG + CSS bars,
  no library, palette from the shared `--an-*` CSS vars (the business root
  carries `class="bokun-andash bokun-bizdash"` so it inherits the Analytics
  card/KPI/barlist styling; `.bokun-bizdash` adds only the P&L/waterfall/table
  styles). The waterfall is a floating-bar SVG; the monthly trend is grouped
  margin-vs-overhead bars with an operating-profit line.
- **Lazy init:** `init` is attached as `root.bokunBusinessInit` on the business
  root; the Analytics toolbar toggle calls it the first time the view is shown
  (the panel auto-inits only if already visible, which it is not while embedded
  hidden).

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
- **Cancelled bookings earn no gross** (`is_cancelled`). The formula above is
  for live bookings only. A client-cancelled booking earned nothing, so net
  revenue treats its gross as 0: if it was *made* (Full/Partial, so we reserved
  and paid the partner) and not refunded, the whole partner cost is a loss and
  `net_revenue = −(net_price × participants)` — a negative value in the totals;
  if the partner refunded it, it's neutral (see below); if it was never made,
  net revenue is null. `_grossEarned` (0 for cancelled/refunded rows,
  `price_amount` otherwise) is the net-revenue margin denominator so these
  losses and neutral refunds don't distort the margin.
- **Partner-refunded cancellations are neutral.** On bookings tagged both
  "Booking made" and "Cancelled", the dashboard card shows two separate
  checkboxes: "Refund requested" (the legacy `refund-requested-from-partner`
  term — tracking only, no net-revenue effect) and "Cancelled and refunded by
  Partner" (the distinct `refunded-by-partner` term — the *completed* refund).
  Only the completed-refund term is snapshotted to `partner_refunded`. When it
  is set, the partner cost is recovered, so the line's net cost is forced to 0
  and its net revenue to null — it drops out of the net-revenue total instead of
  showing the loss it would be while the partner payment was unrecovered. Keep
  the two terms distinct: a pending or denied *request* must never be read as a
  completed refund, so never point `partner_refunded` at the requested term.
- The catalog join is on the **partner page id only**. The partners catalog is
  keyed by the Bokun partner page id (the "ID" column of the partners
  spreadsheet), and a booking's `partner_page_id` comes from its product tag's
  `partnerpageid` term meta — the two share that numbering, so
  `PARTNERS[String(r.partner_page_id)]` is the join. Do **not** fall back to
  `product_id`, `product_external_id`, or title: channel (e.g. Viator)
  bookings carry different ids and titles than the catalog, so those keys
  produce wrong or missing matches. The Analytics Data admin screen shows a
  catalog-match % (by partner page id) and a sample of unmatched booking
  products so a bad/partial catalog — or a product tag missing its
  `partnerpageid` meta — is visible.

Revenue and net-revenue are money, so they are **aggregated in a single
currency** — the most common one in the filtered set (`revCur`). Rows in other
currencies contribute 0 to those metric sums, and the chart titles show the
active currency and flag mixed sets. Never sum `price_amount`/net revenue
across currencies as if interchangeable.

The gross **Revenue** metric (the metric toggle, the Revenue KPI tile, and
avg booking value) is gross *earned*: it sums `_grossEarned`, which is 0 for
cancelled and partner-refunded bookings, so those stay out of revenue just as
they stay out of net revenue. The raw **Gross** column and totals in the
records table still show each booking's face `price_amount` (a ledger view),
so a cancelled booking shows its original amount there while contributing 0 to
the headline Revenue.

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
and the group-by option are generated from it. Status (`pb_status`) lives in
`$facets` because it is used constantly.

To offer an **empty-value** option (e.g. the Result facet's "No result" for
bookings with no result chosen yet), append a synthetic item with
`v: ''` and a display `label` to that facet's value list. No `matches()` change
is needed — it already compares against `''` for an empty cell, so selecting the
empty value filters to exactly those rows. The **quick filter** button
(`applyQuick`) is just a programmatic facet selection: it clears `facetSel`,
sets values discovered from the data by pattern (so "CONFIRMED"/"Viator.com"
casing isn't hardcoded), then calls `syncFacetItemClasses()` + `recompute()`.

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
- **Status comes from the whole payload, not just the product booking.** A
  Viator/OTA refund or cancellation is often recorded at the booking or payment
  level (e.g. a `paymentStatus` of REFUNDED) while `productBookings[0].status`
  stays CONFIRMED — so reading only the product status leaves a refunded
  booking showing as confirmed and still counted in revenue. The import scans
  every `*status*` key in the payload (`bokun_scan_booking_terminal_status()`)
  for a terminal channel state — cancelled / refunded / aborted / rejected /
  declined / expired / voided / chargeback — and stores the result as
  `_booking_effective_status`, which `pb_status` and
  `bokun_analytics_is_cancelled()` then prefer. That detection keys on the
  **channel** status only, never on a "refund" taxonomy term, so the operator's
  own `refunded-by-partner` tag (a partner-side cost recovery) is never misread
  as a customer refund. `_booking_effective_status` is populated on the next
  fetch, so a re-fetch is what makes an already-refunded booking drop out.
- **Channel refunds Bokun never reports → a manual "Refunded" marker.** Some
  refunds live only in the sales channel (e.g. Viator) and never reach Bokun —
  Bokun keeps returning CONFIRMED with no refund field anywhere, so there is
  nothing to auto-detect. For these the operator applies a **"Refunded"**
  `booking_status` term — via the "Mark a booking refunded (channel)" box on the
  Analytics Data screen (by confirmation code), the dashboard, or the WP taxonomy
  editor. A `set_object_terms` hook (`bokun_analytics_sync_refunded_marker`)
  bridges adding/removing that term to the dedicated **`_user_channel_refunded`**
  post-meta flag, which is the authoritative signal everything keys on. The flag
  (not the bare term at read time) is used because a term alone is ambiguous: if Bokun itself
  ever reported REFUNDED the normal import would create the same term, and keying
  on it would pin the booking as refunded forever even after Bokun reverted. When
  the flag is set, the import (`bokun_save_specific_fields`) forces
  `_booking_effective_status` to REFUNDED, does NOT re-apply the Bokun status,
  and drops a stale CONFIRMED/CANCELLED term so the booking reads only "Refunded";
  `bokun_analytics_is_cancelled()` returns true and `pb_status` shows REFUNDED, so
  it is excluded from net and gross revenue like a cancellation. Un-marking clears
  the flag and reverts the effective status to what Bokun last reported. The
  marker survives every future fetch. The term hook ignores import-driven term
  writes (guarded by `$GLOBALS['bokun_import_in_progress']`), and the import drops
  a stale auto-created "Refunded" term when Bokun reports a non-terminal status,
  so a Bokun-generated REFUNDED is never promoted to a manual override. (A Bokun-reported terminal status is still handled separately via
  `_booking_effective_status`; the read-only **Inspect a booking** box dumps a
  booking's stored + live-Bokun status fields to trace where a status lives.)
- **Import existing-post lookup must span all statuses.** The import
  (`bokun_save_bookings_as_posts`) matches an incoming booking to its existing
  post by `_confirmation_code`. That lookup uses `post_status => 'any'`: a
  publish-only lookup misses a post an earlier run demoted to draft, so the
  start-date look-back would re-insert a second, published copy — a duplicate
  post (and a duplicate analytics row, since rows are keyed by `post_id`).
  `bokun_dedupe_booking_posts()` collapses any duplicates sharing a confirmation
  code to one primary (the post with the most `booking_status` terms, so the
  dashboard-assigned result/payment survive; oldest ID wins ties) and trashes
  the rest. Trashing/deleting a `bokun_booking` removes its analytics row via
  the `trashed_post` / `before_delete_post` hook, so no orphan row is left.

## After deploying changes
A schema-version bump now **auto-schedules a one-time rebuild** on the next
admin request (via wp-cron: `bokun_analytics_ensure_schema()` flags it,
`bokun_analytics_maybe_schedule_rebuild()` queues
`bokun_analytics_run_pending_rebuild`), so existing rows pick up new/changed
columns without a manual step — the **Rebuild now** button is the fallback when
wp-cron is disabled. Still tell the user to run **Rebuild partners products**
(catalog + partner ids) once, since net revenue needs both populated.

The **Analytics Data** screen also has a one-time **"Mark cancelled 'booking
made' as refunded by partner"** backfill
(`bokun_analytics_mark_cancelled_made_refunded()`), for operators who requested
partner refunds for the whole cancelled backlog before the checkbox existed. It
assigns the `refunded-by-partner` term to every cancelled Full/Partial booking,
records history, and re-syncs each analytics row so net revenue flips from the
negative cancellation loss to neutral. It is idempotent. A refund applied via
the taxonomy term editor (not the dashboard checkbox or this backfill) will
**not** update the analytics row until a re-sync/rebuild.
