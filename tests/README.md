# Booking dashboard regression checks

These tests run against the checked-out plugin source. They use synthetic data,
WordPress test doubles, an in-memory advisory-lock model, and a mocked browser
transport. They never connect a mailbox, call an AI or Bókun API, or send mail.

## Run locally

Requirements: PHP 8.2 or newer, Node.js 20 or newer, and npm.

```sh
php tests/dashboard-access.php
php tests/booking-webhook.php
php tests/github-sync-security.php
npm ci --prefix tests --ignore-scripts --no-audit --no-fund
npm --prefix tests test
python tests/wordpress/run.py
```

Only dependency installation needs network access. The test executions are
offline. Keep `--ignore-scripts`; no dependency lifecycle scripts are required.

## Coverage

- `dashboard-access.php`: 44 checks for the public Bookings view hiding every
  staff-only surface (import controls/status, Analytics and Messages tabs),
  staff-only direct analytics/business/history access, independent Messages
  visibility, empty booking inventories, noindex, and cache protection.
- `booking-webhook.php`: 69 checks for explicit activation, secret authorization,
  request shape, durable pending markers, database lock ownership, scheduling,
  retries, trailing work, safe deactivation, bounded booking identifiers, distinct
  queued bookings, interrupted-marker recovery and updates arriving during work.
- `messages-ui.cjs`: 31 mocked-DOM checks for safe rendering, operator notes,
  unsaved edits, draft history, foreground locking, approval invalidation,
  analysis-only recommendations, and cross-business navigation. It asserts that
  no send request occurs.
- `github-sync-security.php`: isolated GitHub updater secret-handling and
  authenticated download checks.
- `analytics-ui.cjs`: renders the real PHP Analytics panel with synthetic rows
  and checks linked counts, OR/AND selections, search, dates, dropdowns,
  zero-result recovery, quick filters, chart drilldowns, metric changes, reset,
  and filtered CSV export. Counts use all other dimensions' active filters and
  remain booking counts regardless of the selected chart metric.

GitHub Actions runs the PHP suites on PHP 8.2 and 8.4, syntax-checks the plugin,
and runs the JavaScript suite on Node.js 22. The npm dependency tree is locked.
The Analytics JavaScript suite also requires PHP to render the panel;
`BOKUN_TEST_PHP` and `BOKUN_TEST_PHP_ARGS` can select a local PHP runtime.
The WordPress runner requires Python 3.9+, PHP PDO SQLite, mbstring, OpenSSL
and curl. It downloads checksum-pinned official WordPress 7.1.3 and SQLite
Database Integration 3.1.0 into a disposable operating-system temporary directory,
initializes a fresh synthetic installation, and executes 133 messaging checks,
76 import checks and two asset-version checks, including targeted API lookups,
updates, cancellations and preservation of unrelated past bookings. It never
uses an existing database.
All external HTTP and actual mail are blocked; API transports are mocked.

`BOKUN_TEST_PHP` selects the PHP executable; `BOKUN_TEST_PHP_ARGS` is a JSON
argument array for extension overrides. `BOKUN_TEST_ARCHIVE_CACHE` can point to
cached `wordpress-test.zip` and `sqlite-test.zip`; checksums are still required.
`BOKUN_TEST_KEEP_RUNTIME=1` preserves only the generated synthetic runtime for
debugging. PHP entrypoints require CLI and an explicit disposable-runtime marker.

## Limits

The SQLite integration suites do not prove a real MySQL server's advisory-lock
behavior, OAuth refresh, actual mailbox backfill, live Bókun data, AI answer
quality or deployment. Live synthetic-data AI evaluations remain explicit local
operator actions, not CI jobs with production keys. No WordPress database,
credential, real customer fixture or downloaded runtime belongs in this directory.
Development directories are excluded from install archives with export-ignore.
