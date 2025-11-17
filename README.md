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
│   └── bokun_booking_history.view.php # Booking history admin page
├── assets/
│   ├── css/                        # Admin/front styles
│   ├── js/                         # Admin/front scripts (import, DataTables helpers)
│   └── images/                     # UI assets (e.g., progress spinner)
└── addons/                         # Extension hooks (currently empty placeholder)
```

## Features

- **Multiple Bokun API credentials** – Store, validate, and remove any number of API key/secret pairs from a repeatable UI component. The settings screen persists them in the `bokun_api_credentials` option and gracefully migrates legacy single-key installs. 【F:includes/bokun_settings.view.php†L1-L97】
- **Booking imports with progress tracking** – Secure admin/AJAX actions (`bokun_bookings_manager_page` and `bokun_get_import_progress`) call the Bokun Booking Search endpoint, paginate through results, and save bookings as the `bokun_booking` custom post type while reporting completion stats and errors back to the UI. 【F:includes/bokun_settings.class.php†L15-L166】
- **Dedicated post type & taxonomies** – Bookings are stored as first-class posts, enriched with Booking Status, Product Tags, and Team Member taxonomies so you can build filtered lists, Elementor widgets, or REST/GraphQL queries. 【F:bokun-bookings-management.php†L200-L279】
- **Dashboard & fetch shortcodes** – Drop-in shortcodes for the booking dashboard (`[bokun_booking_dashboard]`), booking history table (`[bokun_booking_history]`), and front-end import trigger (`[bokun_fetch_button]`) so non-admins can self-serve. 【F:includes/bokun_shortcode.class.php†L6-L76】
- **Rich booking history UI** – The admin page and shortcode render a responsive DataTable with filters, column searching, and CSV export via DataTables Buttons/JSZip. Permission checks prevent unauthorized viewing. 【F:includes/bokun_shortcode.class.php†L78-L171】
- **Product tag image importer** – Trigger a background job from the settings screen to pull gallery images for every Bokun product tag and attach them to the WordPress taxonomy terms. 【F:includes/bokun_settings.view.php†L200-L233】
- **Accessibility-aware progress feedback** – Both the admin fetch button and `[bokun_fetch_button]` shortcode share ARIA-enabled progress bars and live regions so users know the import status. 【F:includes/bokun_shortcode.class.php†L15-L52】【F:includes/bokun_settings.view.php†L110-L150】

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
| `[bokun_booking_dashboard]` | Displays the booking dashboard UI (cards, filters, etc.). The plugin can append it automatically to a chosen page from the settings panel. | None |

## Admin booking history

The built-in **Booking History** submenu displays the latest entries from the `wp_bokun_booking_history` table, grouped by action, status, actor, and source. Users can filter via collapsible multi-select controls, search within the table, and download the visible dataset as CSV. The view gracefully handles missing tables (e.g., when the plugin has not been activated yet). 【F:includes/bokun_booking_history.view.php†L1-L118】

## Hooks & filters

Use these extension points to customize behavior without editing core files:

- `bokun_booking_items_per_page` – Change the number of bookings pulled per API page (default 50).
- `bokun_booking_request_timeout` – Adjust the cURL timeout in seconds (default 300).
- `bokun_booking_history_page_limit` – Control how many booking history rows appear on the admin screen (default 100). 【F:includes/bokun_booking_history.view.php†L19-L45】
- `bokun_txt_domain` action – Fired after the text domain loads so you can register additional strings. 【F:bokun-bookings-management.php†L248-L256】

## Development workflow

1. Install the plugin in a local WordPress environment.
2. Run `npm install && npm run dev` inside `assets/` if you extend the frontend build tooling (currently assets are plain CSS/JS and do not require compilation).
3. Use `wp i18n make-pot` to refresh translation files after editing user-facing strings.
4. Follow WordPress PHP coding standards (PSR-2-like formatting, escaping output, and so on).

## Troubleshooting tips

- **“Error: No API credentials available for this import.”** — Ensure at least one credential pair is saved; legacy single-key fields are deprecated and automatically migrated the next time you visit the settings screen. 【F:includes/bokun_settings.class.php†L117-L166】
- **Booking history table missing** — Reactivate the plugin to trigger `dbDelta` and recreate the `wp_bokun_booking_history` table. 【F:bokun-bookings-management.php†L229-L279】
- **Imports time out** — Lower the date range in `bokun_fetch_bookings()` or add filters to reduce the payload size. Also confirm your server allows outbound HTTPS requests to `api.bokun.io`.

## License

This plugin is provided as-is under the terms specified by the repository owner. If no explicit license is present, treat the code as "all rights reserved" and request permission before re-distributing.
