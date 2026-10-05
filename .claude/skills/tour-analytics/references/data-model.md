# Analytics data model reference

## `wp_bokun_analytics_source` (one row per booking)

Built by `bokun_analytics_build_row()` in `includes/bokun-analytics.php`.
`bokun_analytics_meta($post_id, [keys])` returns the first non-empty candidate
meta ("N/A" and "" treated as null).

| Column | Source (booking post meta / taxonomy) |
|---|---|
| `post_id` | booking post ID (primary key) |
| `confirmation_code` | `confirmationCode` / `_confirmation_code` |
| `channel_title` | `channel_title` |
| `channel_id` | `channel_id` |
| `channel_channel_type` | `channel_channelType` |
| `seller_title` | `seller_title` |
| `seller_id` | `seller_id` |
| `vendor_title` | `productBookings_0_vendor_title` |
| `vendor_id` | `productBookings_0_vendor_id` |
| `pb_seller_id` | `productBookings_0_seller_id` |
| `pb_seller_title` | `productBookings_0_seller_title` |
| `product_external_id` | `productBookings_0_productExternalId` |
| `product_id` | `productBookings_0_product_id` / `_product_id` |
| `partner_page_id` | product tag `partnerpageid` term meta (via `bokun_partners_products_resolve_partner_page_id()`) |
| `result` | `booking_status` taxonomy: `full` / `partial` / `not-available` |
| `partner_refunded` | 1 when the `refunded-by-partner` `booking_status` term is set ("Cancelled and refunded by Partner"), else 0 — neutralizes net revenue for a client-cancelled booking the partner refunded. Note this is the *completed-refund* term, distinct from `refund-requested-from-partner` ("Refund requested"), which only records that a refund was asked for and does not affect net revenue |
| `payment_method` | `booking_status` taxonomy: Amex / PayPal / Other (`amex`,`paypal`,`other-payment`) |
| `product_title` | `_product_title` / `productBookings_0_product_title` |
| `product_option` | `productBookings_0_fields_rateTitle` / `productBookings_0_rateTitle` |
| `travel_datetime` | raw `_original_start_datetime` → GMT (fallback `post_date_gmt`) |
| `created_datetime` | raw `_original_creation_date` / `creationDate` → GMT (fallback `bookingcreationdate`) |
| `adult_participants` / `child_participants` / `infant_participants` | parsed from `pricecategory1..5` meta by title keyword (adult/senior, child/youth/kid, infant/baby) |
| `language` | `language` |
| `pb_status` | `productBookings_0_status` / `_booking_status_origin` |
| `price_note` | `productBookings_0_notes_1_body` (free text) |
| `price_amount` | numeric amount parsed from `price_note` (`bokun_analytics_parse_amount()`) |
| `product_confirmation_code` | `productBookings_0_productConfirmationCode` |
| `pb_channel_id` | `productBookings_0_channelId` |
| `currency` | `currency` |
| `phone_prefix` | `_phone_prefix` |
| `synced_at` | GMT timestamp of last sync |

Window: rows are kept only while `created_datetime` is within
`bokun_analytics_get_window_months()` (default 3, filter
`bokun_analytics_window_months`). `bokun_analytics_is_within_window()` is the
authoritative GMT check; `bokun_analytics_prune_window()` drops aged-out rows
on the import path.

## `wp_bokun_partners_products` (one row per product)

Built by `bokun_partners_products_seed()` from
`includes/data/partners-products.php`.

| Column | Source |
|---|---|
| `product_id` | catalog ID (primary key) = the Bokun **partner page id**; joins to booking `partner_page_id` |
| `title` | catalog title |
| `net_price` | catalog net price (per person, booking currency assumed) |
| `commission` | catalog commission ratio (e.g. 0.25) |
| `departure_city` | catalog departure city |
| `partner_page_id` | product tag `partnerpageid` term meta |
| `synced_at` | GMT timestamp of last import |

`bokun_partners_products_get_map()` returns `product_id => {title, net_price,
commission, departure_city, partner_page_id}` for the dashboard to join
client-side.

## Client-side derived fields (in `render_analytics_panel()` JS)

Computed per row after joining the partners map by `partner_page_id`
(`PARTNERS[String(r.partner_page_id)]`); not stored:

- `net_price`, `commission`, `departure_city` — from the partners map.
- `_net_cost` = `net_price × participants`.
- `_net_revenue` = `price_amount − net_price × participants` (null unless both
  present). Currency-scoped to `revCur` in all aggregates.
- **Partner-refunded cancellations.** When `partner_refunded` is set, the
  client cancelled a reserved booking but the partner refunded the cost, so the
  line has no profit or loss: `_net_cost` is forced to `0`, `_net_revenue` to
  `null` (neutral — it contributes nothing to the net-revenue total instead of
  the negative it would otherwise be while the partner payment was unrecovered),
  and `_refunded` is set true. Refunded rows are excluded from **both** sides of
  the net-revenue margin in `renderInsights()` — `sumNetRev()` drops them via the
  null `_net_revenue`, and the `grossPrim` denominator skips `_refunded` rows —
  so a neutral refund never understates the margin.

## Taxonomies

- `product_tags` — one term per product; term meta `bokun_product_id`
  (reverse-lookup key) and `partnerpageid`.
- `booking_status` — carries result (`full`/`partial`/`not-available`) and
  payment (`amex`/`paypal`/`other-payment`) selections made in the dashboard;
  changes re-sync the analytics row via `update_booking_status()`. It also
  carries two refund states for client-cancelled bookings:
  `refund-requested-from-partner` ("Refund requested" — tracking only, no
  net-revenue effect) and the distinct `refunded-by-partner` ("Cancelled and
  refunded by Partner" — the completed refund, snapshotted to `partner_refunded`
  and the only one that neutralizes net revenue). They are kept separate so a
  pending/denied request is never mistaken for a completed refund.
