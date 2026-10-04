# Bokun Bookings Management

Bokun Bookings Management is a WordPress plugin that lets tour and activity operators pull reservations from the [Bokun API](https://bokun.io/), save them as native custom posts, and work with the data directly inside WordPress. The plugin exposes booking dashboards, a booking-history data table with CSV export, public-facing shortcodes, and utility actions for managing product-tag media.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4+ with cURL enabled (required to call the Bokun API)
- A Bokun account with API access and at least one API key/secret pair

## Repository layout

```
├── bokun-bookings-management.php   # Main plugin bootstrap
├── includes/
│   ├── bokun-bookings-manager.php  # API client, import helpers, utilities
│   ├── bokun_settings.class.php    # Admin AJAX endpoints & settings handler
│   ├── bokun_shortcode.class.php   # Front-end shortcodes
│   ├── bokun_settings.view.php     # Settings screen markup
│   ├── bokun_booking_history.view.php # Booking history admin page
│   ├── bokun-analytics.php         # Analytics source table & sync layer
│   ├── bokun-partners-products.php # Partners products reference table
│   ├── data/partners-products.php  # Bundled product catalog seed data
│   ├── bokun_analytics_source.view.php # Analytics data admin page
│   └── class-bokun-github-sync.php # GitHub auto-sync (self-updater)
├── assets/
│   ├── css/                        # Admin/front styles
│   ├── js/                         # Admin/front scripts (import, DataTables helpers)
│   └── images/                     # UI assets (e.g., progress spinner)
```

## Features

- **Multiple Bokun API credentials** – Store, validate, and remove any number of API key/secret pairs from a repeatable UI component. The settings screen persists them in the `bokun_api_credentials` option and gracefully migrates legacy single-key installs. 【F:includes/bokun_settings.view.php†L1-L97】
- **Booking imports with progress tracking** – Secure admin/AJAX actions (`bokun_bookings_manager_page` and `bokun_get_import_progress`) call the Bokun Booking Search endpoint, paginate through results, and save bookings as the `bokun_booking` custom post type while reporting completion stats and errors back to the UI. 【F:includes/bokun_settings.class.php†L15-L166】
- **Dedicated post type & taxonomies** – Bookings are stored as first-class posts, enriched with Booking Status, Product Tags, and Team Member taxonomies so you can build filtered lists, Elementor widgets, or REST/GraphQL queries. 【F:bokun-bookings-management.php†L200-L279】
- **Dashboard & fetch shortcodes** – Drop-in shortcodes for the booking dashboard (`[bokun_booking_dashboard]`), booking history table (`[bokun_booking_history]`), and front-end import trigger (`[bokun_fetch_button]`) so non-admins can self-serve. 【F:includes/bokun_shortcode.class.php†L6-L76】
- **Rich booking history UI** – The admin page and shortcode render a responsive DataTable with filters, column searching, and CSV export via DataTables Buttons/JSZip. Permission checks prevent unauthorized viewing. 【F:includes/bokun_shortcode.class.php†L78-L171】
- **Product tag image importer** – Trigger a background job from the settings screen to pull gallery images for every Bokun product tag and attach them to the WordPress taxonomy terms. 【F:includes/bokun_settings.view.php†L200-L233】
- **Accessibility-aware progress feedback** – Both the admin fetch button and `[bokun_fetch_button]` shortcode share ARIA-enabled progress bars and live regions so users know the import status. 【F:includes/bokun_shortcode.class.php†L15-L52】【F:includes/bokun_settings.view.php†L110-L150】
- **GitHub auto-sync (self-updater)** – The plugin keeps itself in sync with its GitHub repository. It tracks the latest commit on a configured branch (default `main`) and, with auto-sync enabled by default, installs new commits automatically in the background using WordPress' native update pipeline. This replaces the separate "Github Plugin Installer and Updater" helper plugin, which is now merged into this one. 【F:includes/class-bokun-github-sync.php†L1-L120】

## GitHub auto-sync

The plugin ships with an integrated self-updater that mirrors the tracked GitHub branch onto the installed site. It uses WordPress' own plugin-update mechanism, so updates appear on the **Plugins** and **Dashboard → Updates** screens and can install automatically in the background.

- **Change detection is commit-based.** Rather than relying only on the plugin header version, it compares the latest commit SHA of the tracked branch against the installed commit. This means *any* change pushed to the branch — not just version bumps — is picked up.
- **Auto-sync is on by default.** On activation the plugin enables WordPress background auto-updates for itself and installs new commits without manual intervention.
- **Configuration** lives under **Bokun Bookings Management → Bokun GitHub Sync** (between Settings and Booking History), where you can set the repository URL, branch, and (for private repositories) a GitHub token, and toggle auto-sync on or off. A **Sync from GitHub now** button forces an immediate update.

> **First-time install (bootstrap).** The auto-sync module can only update a copy of the plugin that already contains it. The very first time, install the current `main` build once (download the repository ZIP and upload it via **Plugins → Add New → Upload Plugin → Replace current with uploaded**, then activate). After that, every change syncs on its own.

### How updates are triggered

Three mechanisms work together, from fastest to fallback:

1. **GitHub webhook (event-driven, recommended).** Installed the moment you push to the tracked branch. Under **Bokun Bookings Management → Bokun GitHub Sync**, copy the **Payload URL** and **Secret** and add them in GitHub (**Repository → Settings → Webhooks → Add webhook**; Content type `application/json`; "Just the push event"). The endpoint verifies the GitHub `X-Hub-Signature-256` HMAC before doing anything. The secret can also be set via a `BOKUN_GITHUB_WEBHOOK_SECRET` constant in `wp-config.php`.
2. **Admin-load check.** Whenever an administrator opens wp-admin, the plugin checks for a new commit (throttled to at most once every couple of minutes) and installs it if the branch has moved.
3. **Hourly WP-Cron check.** A background safety net. Note WP-Cron only fires on site traffic unless a real system cron calls `wp-cron.php`.

### GitHub token for private repositories

For public repositories no token is required. For private repositories, supply a GitHub personal access token with `repo` (or fine-grained *Contents: read*) scope in one of two ways:

1. **Recommended – wp-config.php constant** (kept out of the database and the repository):

   ```php
   define( 'BOKUN_GITHUB_TOKEN', 'ghp_your_token_here' );
   ```

2. **Settings field** – Enter the token under **Bokun Bookings Management → Bokun GitHub Sync**. It is stored in the WordPress options table. The constant, when defined, always takes precedence.

> **Never commit a token to the repository.** Anything pushed to GitHub is exposed publicly (for public repos) and GitHub automatically revokes leaked tokens.

## Installation

1. Clone or download this repository into your WordPress installation's `wp-content/plugins/` directory.
2. Ensure the folder is named `bokun-bookings-management` so WordPress can detect the plugin header in `bokun-bookings-management.php`.
3. Activate **Bokun Bookings Management** from **Plugins → Installed Plugins**. On first activation the plugin creates the `wp_bokun_booking_history` table and redirects you to the settings screen. 【F:bokun-bookings-management.php†L229-L313】

## Configuring API credentials & dashboard

1. Navigate to **Bokun Bookings Management → Settings**.
2. Enter one or more API keys/secrets. Use the “Add another API” button to add extra credential sets (handy for fetching from multiple Bokun accounts or environments). Save when finished. 【F:includes/bokun_settings.view.php†L39-L135】
3. (Optional) Select a page in the **Booking dashboard display** section to automatically append the `[bokun_booking_dashboard]` output whenever that page is viewed. Otherwise, insert the shortcode manually in Gutenberg or a template. 【F:includes/bokun_settings.view.php†L162-L217】
4. Use the **Fetch Booking** panel to test your credentials and start an import. Progress updates are shown inline without a full page refresh.
5. Use the **Import Product Tag Images** button if you need each Product Tag taxonomy term to carry over its Bokun gallery images.

### Import behavior

- Each credential set is normalized to an import “context” (API 1, API 2, etc.). Every run iterates through every configured context sequentially.
- Bookings are fetched via the `/booking.json/booking-search` endpoint with a default date window from yesterday through one month ahead. Adjust this by filtering `bokun_booking_items_per_page` or editing the request payload in `includes/bokun-bookings-manager.php`. 【F:includes/bokun-bookings-manager.php†L84-L174】
- Imported bookings are stored as the `bokun_booking` custom post type. Future-dated posts are forced to `publish` status so they appear immediately. 【F:includes/bokun-bookings-manager.php†L7-L23】
- Each create/update action is logged to `wp_bokun_booking_history` for auditing, including the actor (WP user, team member, guest) and whether the change has been “checked.”

## Shortcodes

| Shortcode | Purpose | Attributes |
|-----------|---------|------------|
| `[bokun_fetch_button]` | Renders a primary button that triggers the AJAX importer plus an optional progress bar. Use this on the front end when you want staff to pull Bokun reservations without visiting wp-admin. | None |
| `[bokun_booking_history]` | Outputs the booking history DataTable anywhere (front end or admin). | `limit` (default `100`), `capability` (default `manage_options`), `export` (slug used for the CSV filename). Users lacking the capability see a friendly notice. 【F:includes/bokun_shortcode.class.php†L95-L152】 |
| `[bokun_booking_dashboard]` | Displays the booking dashboard UI in two tabs — **Bookings** (cards, filters, etc.) and **Analytics** (KPIs, per-column filters, breakdown, CSV export). The plugin can append it automatically to a chosen page from the settings panel. | None |

## Admin booking history

The built-in **Booking History** submenu displays the latest entries from the `wp_bokun_booking_history` table, grouped by action, status, actor, and source. Users can filter via collapsible multi-select controls, search within the table, and download the visible dataset as CSV. The view gracefully handles missing tables (e.g., when the plugin has not been activated yet). 【F:includes/bokun_booking_history.view.php†L1-L118】

## Analytics data source

The **Analytics Data** submenu prepares the flat source dataset that powers the analytics dashboard. Rather than querying ~25 fields across post meta and taxonomies at render time, the plugin denormalizes each booking into a single row in a dedicated `wp_bokun_analytics_source` table, keeping only bookings created within a trailing window (three months by default). 【F:includes/bokun-analytics.php†L1-L120】

- **One row per booking.** Columns cover channel/seller/vendor identifiers, product title and option, travel and creation datetimes (stored in GMT), participant counts split into adult/child/infant, language, currency, price note plus a parsed numeric **amount**, product/booking status, confirmation codes, phone prefix, and the dashboard-managed **result** (full/partial/not-available) and **payment method** (Amex/PayPal/Other) read from the `booking_status` taxonomy.
- **Kept in sync automatically.** Every import upserts the booking's row and drops any that fall outside the window; dashboard status changes re-sync the affected row, and a global prune runs on the import path, so the table tracks the source bookings without a separate job. 【F:includes/bokun-bookings-manager.php†L1000-L1012】
- **Manual rebuild.** The admin screen shows the record count, window length, and last-rebuilt time, with a **Rebuild now** button (AJAX, `manage_options` + nonce protected) that truncates and repopulates the table for the current window. 【F:includes/bokun_analytics_source.view.php†L1-L140】
- **Schema upgrades.** The table is created on activation and re-checked on each admin request against `BOKUN_ANALYTICS_DB_VERSION`, so installs upgraded from an older build pick it up without re-activating. 【F:includes/bokun-analytics.php†L120-L150】
- **Partner page id.** Each row also carries `partner_page_id`, resolved from the product tag's `partnerpageid` term meta (looked up by `bokun_product_id`), so bookings can be filtered and grouped by partner page.

### Partners products reference table

A companion `wp_bokun_partners_products` table holds one row per Bokun product — `product_id` (key), `title`, `net_price`, `commission`, `departure_city`, and `partner_page_id` — as the per-product dimension that the analytics layer joins against for partner, net-price and commission reporting. 【F:includes/bokun-partners-products.php†L1-L60】

- **Catalog seed.** The product catalog ships in the repo (`includes/data/partners-products.php`) and is imported (upsert) on activation and via a **Rebuild partners products** button on the Analytics Data screen.
- **Partner page id.** During import, each product's `partner_page_id` is resolved from the matching `product_tags` term's `partnerpageid` term meta (memoized per request). 【F:includes/bokun-partners-products.php†L90-L140】

### Analytics dashboard tab

The `[bokun_booking_dashboard]` output is split into two tabs: **Bookings** (the existing dashboard) and **Analytics**. The analytics tab is a business-performance view that reads the `wp_bokun_analytics_source` rows, joins each booking to the partners-products catalog by `product_id`, and renders entirely client-side for instant interactivity:

- **Net revenue** — each booking line is joined to its catalog net price, and **net revenue = gross amount − (net price × participants)** is computed per line, shown in the detail table and aggregated in KPIs, the breakdown and the metric toggle.
- **Metric toggle** — switch the whole view between **Bookings**, **Participants**, **Revenue** and **Net revenue**; insights, trend and performance rankings all follow the selected metric. Revenue/net-revenue aggregate in a single currency (the most common in the filtered set) so mixed currencies are never summed.
- **Left filter sidebar** — important dimensions (product, channel, partner page, result, payment, currency, departure city) are clickable multi-select item lists; secondary dimensions stay under a collapsible **More filters**; plus search, date presets (7/30/90/All) and created/travel date ranges.
- **Auto insights** — top product (with share), top channel, net revenue (with margin %), average booking value, average lead time (created → travel), and full-result rate.
- **KPI tiles** — bookings, participants (adult/child/infant split), revenue per currency, net revenue, and average lead time.
- **Charts** — a weekly **trend** line/area chart (SVG, hover tooltip + crosshair), **product performance** and **channel performance** bar rankings, and **result** and **payment** mix. Colorblind-safe palette, light/dark aware.
- **Group-by breakdown** (count, share %, participants, gross, net revenue) and a **detail table + CSV export** (gross, net price, net revenue per line) of the filtered rows.

The **amount** is parsed best-effort from the free-text price note (`productBookings_0_notes_1_body`); the raw note is retained for reference. 【F:includes/bokun_shortcode.class.php†L2901-L3560】

## Hooks & filters

Use these extension points to customize behavior without editing core files:

- `bokun_booking_items_per_page` – Change the number of bookings pulled per API page (default 50).
- `bokun_booking_request_timeout` – Adjust the cURL timeout in seconds (default 300).
- `bokun_booking_history_page_limit` – Control how many booking history rows appear on the admin screen (default 100). 【F:includes/bokun_booking_history.view.php†L19-L45】
- `bokun_analytics_window_months` – Change the trailing window (in months, by booking creation date) retained in the analytics source table (default 3). 【F:includes/bokun-analytics.php†L52-L60】
- `bokun_txt_domain` action – Fired after the text domain loads so you can register additional strings. 【F:bokun-bookings-management.php†L248-L256】

## Development workflow

1. Install the plugin in a local WordPress environment.
2. Run `npm install && npm run dev` inside `assets/` if you extend the frontend build tooling (currently assets are plain CSS/JS and do not require compilation).
3. Use `wp i18n make-pot` to refresh translation files after editing user-facing strings.
4. Follow WordPress PHP coding standards (PSR-2-like formatting, escaping output, and so on).

## Troubleshooting tips

- **“Error: No API credentials available for this import.”** — Ensure at least one credential pair is saved; legacy single-key fields are deprecated and automatically migrated the next time you visit the settings screen. 【F:includes/bokun_settings.class.php†L117-L166】
- **Booking history table missing** — Reactivate the plugin to trigger `dbDelta` and recreate the `wp_bokun_booking_history` table. 【F:bokun-bookings-management.php†L229-L279】
- **Analytics source empty or table missing** — Open **Analytics Data** and click **Rebuild now** to create and repopulate `wp_bokun_analytics_source`. Only bookings whose creation date falls inside the window appear; adjust the window with the `bokun_analytics_window_months` filter.
- **Imports time out** — Lower the date range in `bokun_fetch_bookings()` or add filters to reduce the payload size. Also confirm your server allows outbound HTTPS requests to `api.bokun.io`.

## License

This plugin is provided as-is under the terms specified by the repository owner. If no explicit license is present, treat the code as "all rights reserved" and request permission before re-distributing.
