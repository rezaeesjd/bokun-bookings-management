=== Bokun Bookings Management ===
Contributors: hwtech, openai
Tags: bookings, reservations, bokun, tourism, scheduling
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Bokun-focused operations assistant for WordPress that synchronizes bookings, tracks history, and surfaces actionable tools for tour operators.

== Description ==
Bokun Bookings Management connects your WordPress site to Bokun so you can review and synchronize reservations without leaving the dashboard. The plugin provides:

* Guided onboarding for saving and validating Bokun API credentials.
* Manual and scheduled background synchronization of bookings, including detailed progress reporting.
* A searchable booking history table with sortable columns, CSV export, and granular filters (status, actor, source, and date range).
* Admin notices, inline tips, and localized UI copy to keep the workflow clear for every team member.

Behind the scenes the plugin stores a full activity log, allowing you to audit staff changes, monitor import jobs, and confirm customer communication. The included service container and modern coding standards make it easy to extend the plugin or integrate it with bespoke workflows.

== Installation ==
1. Upload the extracted `bokun-bookings-management` folder to the `/wp-content/plugins/` directory, or install via the Plugins screen by uploading the packaged ZIP file.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Visit **Bookings → Bokun Settings** to enter your primary and upgrade API credentials. Use the on-page validation buttons to verify access before syncing.
4. Trigger a manual sync or enable the background scheduler to import bookings. Progress indicators and completion summaries confirm the status of each run.
5. Review bookings under **Bookings → Booking History**, applying the filters or exporting data as needed.

== Frequently Asked Questions ==

= Do I need two sets of API credentials? =
The plugin supports both a primary and an optional upgrade Bokun API key pair. If your account only uses a single key, leave the upgrade fields empty.

= How often does the background sync run? =
The plugin schedules an hourly event by default. You can trigger an immediate run from the settings page at any time, and the status panel will reflect the most recent start, finish, and next scheduled run.

= Can I export bookings to share with my team? =
Yes. The booking history page includes an **Export CSV** button that respects any filters applied to the table so you can deliver focused datasets.

== Screenshots ==
1. Settings dashboard with onboarding checklist, credential validation, and sync controls.
2. Booking history table with filters, search, and CSV export options.

== Changelog ==
= 1.0.0 =
* Initial public release with credential onboarding, booking synchronization, background jobs, and an exportable activity log.
