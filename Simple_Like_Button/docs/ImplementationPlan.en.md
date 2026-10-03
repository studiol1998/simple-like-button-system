# Implementation Plan

This memo connects the existing Pollyanna v1.0 specification and decisions agreed with Miyabi to implementation work. It does not replace the authoritative specification. See [DatabaseDesignDecisionLog.en.md](DatabaseDesignDecisionLog.en.md) for agreed decisions and [DatabaseDesignReview.en.md](DatabaseDesignReview.en.md) for database design questions.

The old beta is reference material for behavior and migration only; its code will not be adopted as-is.

## Current progress (2026-09-29)

2026-10-03: Added `admin/index.php` as the production admin foundation. It provides shared Dashboard / Details / Settings tabs, Japanese/English switching from `admin.*.json`, and a saved default admin language. The Dashboard reads the live database for total, aggregated, pending, distinct Like IDs, seven-day, twelve-month, and current-month hourly summaries. Details, remaining settings, and authentication are subsequent work. Access is restricted to localhost until authentication is complete.

The Like API, state restoration, daily/monthly/yearly/hourly aggregation, rate limits, Japanese and English widget, local verification page, settings, and timezone-change archive are implemented. Public count auto-refresh defaults to 5 seconds and supports configurable intervals. When public counts are off, only the initial liked-state check runs; no count query runs. Aggregation rollback/retry and two-process concurrency were verified on 2026-09-29 using an isolated database on XAMPP MariaDB.

Old-data compaction and interrupted timezone-change recovery were verified on 2026-09-29 using an isolated database and filesystem on XAMPP MariaDB. The five-year daily-to-monthly and monthly-to-yearly rollups, additive updates to existing aggregates, and the 50,000-row daily cap (50,001 rows compacted by one row) preserved totals. The timezone test resumed after a 500-event batch, preserved the old timezone, 511 aggregate Likes, and maintenance state after an archive-protection failure, then completed archive/reset/state restoration after the failure was fixed. Recovery after the database commit was also verified. CSV formula protection was tested with synthetic identifiers in Microsoft Excel; the identifiers remained text and were not evaluated. Before adding the widget to production templates, the main remaining work is end-to-end verification under the actual site conditions and access-denial rules for non-Apache servers.

## Goal

- Deliver a self-hosted Like button that can be embedded easily in a website.
- Implement data minimization, per-Like-ID anonymous-browser rate limits, period aggregation, admin controls, and installation flow consistently with agreed decisions.
- Verify the completed system so Miyabi can add it to the site's HTML templates.

## Beta version plan

### 0.0.1

- When a visitor taps ♡, change it to a filled, colored ♥ after the server accepts the Like.
- Save the tap in the database.
- Include input validation. Do not apply rate limits in 0.0.1; revisit them by 0.1.0 when admin and operational settings are added.

### 0.0.2 (complete)

- Restore the liked state after reload for the same browser and identifier.
- Account for browser identification in 0.0.1 so this does not require a later database redesign.

### 0.0.3 (complete)

- Display the Like count.
- Until the admin screen is available, toggle display with `show_like_count` in the configuration file. Its default is `false`.
- Do not return the count from the public API while display is disabled.
- Before aggregation is introduced, 0.0.3 displays the number of saved Like events for each identifier.

### 0.0.4 (implemented)

- Implement daily, monthly, and yearly aggregation.
- Aggregate installation-wide Likes by hour and month in the active timezone, with a simple view of the latest 24 months.
- Also store liked identifiers in browser LocalStorage so the ♥ state survives deletion of aggregated individual events. Clearing LocalStorage makes the browser a new visitor, consistent with the existing browser-identity decision.

### 0.1.0 (local verification page implemented)

- Bundle the admin screen and the functions needed for installation and operation for testing on Miyabi's site.
- Allow settings such as rate limits, public count visibility, and count display color to be managed in the admin screen.
- Decide whether to include time-of-day aggregates in the public release after testing on the site.

For 0.0.1, prioritize making the Like action and database storage work; do not build all version features at once. Provide a minimal indication if sending or saving fails.

## Agreed requirements

- Record Likes as individual events first, then delete individual rows after aggregation.
- Let admins select an automatic aggregation start threshold of 1,000 (default), 2,000, 4,000, 6,000, 8,000, or 10,000 pending events. Start automatic aggregation when the selected threshold is reached. Do not auto-aggregate only because time has passed; aggregate below-threshold data when the admin screen is accessed. Do not add a scheduled task.
- Calculate the current count from stored aggregates plus pending events. Public count display defaults to off and can be toggled in the admin screen.
- When public counts are enabled, refresh them periodically (5 seconds by default; also 10, 30, 60, or disabled at 0). Do not poll while counts are hidden or while the browser tab is hidden.
- Keep daily data for up to five years and 50,000 rows; when either limit is reached, roll older data into monthly aggregates. The five-year window leaves a buffer around the roughly three years of detail likely to be useful. The 50,000-row value is adjustable through program configuration.
- Keep monthly aggregates for five years after they are created, then roll them into yearly aggregates. Retain yearly aggregates.
- Implement time-of-day aggregation and admin display in the beta, then decide whether to include it in the public release after testing on Miyabi's site. Group installation-wide Likes by month and hour in the active timezone; retain the latest 24 months. Process events in existing aggregation batches and store only nonzero slots.
- On a timezone change, aggregate current lifetime, period, and hourly data, including pending events, save it as CSV in a timestamped folder under `log/`, reset active aggregates, and begin aggregating under the new timezone. Public counts restart at zero.
- Before changing the timezone, show a confirmation dialog. After Yes, show aggregation and archive progress, then confirm that data was saved to `log/`. Keep public APIs in maintenance during the change and allow resuming after interruption.
- Do not automatically delete archives after a fixed period or provide download/deletion controls in the admin screen. The completion dialog gently advises that log files can be deleted if they put pressure on server storage; administrators handle any backup and deletion manually.
- For each timezone change, create a timestamped folder containing `metadata.json`, `lifetime_totals.csv`, `period_aggregates.csv`, and `hourly_aggregates.csv`. Write CSV with `fputcsv` and prefix formula-leading identifiers with an apostrophe.
- Store timestamps in UTC. Never store raw IP addresses. Delete investigation IP hashes within 60 days.
- Apply rate limits per Like ID and anonymous browser, with a configurable limit of 1–5 Likes (default 3) in the rolling last 24 hours. Do not use `ip_hash` to reject Likes.
- Provide enabled and maintenance states only.

## Expected day-to-day operation

1. When a visitor clicks the button, the API validates the identifier, browser token, and rate limit, then records an individual event with a UTC timestamp and the required hashes.
2. If public count display is enabled, the API returns the current total plus pending events. If disabled, the public API does not return a count.
3. Start aggregation when the configured automatic start threshold is reached or when the admin screen is accessed. Keep the maximum at 500 events per batch for now, without an admin control. This limit is separate from the automatic start threshold and keeps each batch lightweight. Select individual events, update aggregates, and delete processed events in one short database transaction; roll back and retry the full batch on failure. Prevent concurrent aggregation runs.
4. Assign events to reporting dates using the active timezone and update lifetime totals and daily aggregates. Roll older daily data into monthly aggregates when it reaches five years of age or the daily row cap, whichever comes first.
5. Roll monthly aggregates into yearly aggregates after five years as monthly data, and retain yearly aggregates.
6. Update time-of-day aggregates by month and hour within existing batches, retaining only the latest 24 months.
7. Keep rate-limit events separate from aggregation events and delete expired rows when the same browser next Likes the same Like ID. Delete investigation IP hashes within 60 days.

If the pending count remains below the configured threshold and the admin screen is not accessed, retain events as unaggregated records. Normal batches handle up to 500 events; event selection, aggregate updates, and deletion run in one transaction. Roll back on failure and use a database-scoped lock to prevent concurrent aggregation. Rollback/retry, no double counting, and two-process concurrency were verified in an isolated database on 2026-09-29. Public count polling pauses while the tab is hidden.

### Timezone change flow

1. The admin screen asks for confirmation that lifetime totals, period aggregates, and public counts will reset.
2. After Yes, finish aggregating current data, including pending events.
3. Save the finalized lifetime, period, and time-of-day aggregates in a timestamped folder. Use `lifetime_totals.csv` and `period_aggregates.csv` for the established formats; define the time-of-day archive format during implementation. Record metadata such as the change time and old/new timezones in `metadata.json`. Show progress stages “Aggregating current data” and “Saving aggregated data.”
4. Only after confirming that storage succeeded, reset active data, apply the new timezone, and begin new aggregation.
5. Show a completion dialog identifying `log/` as the archive location and advising that log files can be deleted if they put pressure on server storage. Do not automatically delete archives; administrators handle optional backup and deletion manually.

Define safe failure behavior, such as keeping the old timezone and active data if archival storage fails.

## Implementation stages

### 1. 0.0.1 minimum implementation

- Connect to the database and create the minimum table needed to save identifiers and Likes.
- Provide an endpoint that accepts a Like.
- Implement the ♡ display, tap action, and filled colored ♥ state after successful storage.
- Confirm the 0.0.1 acceptance criteria for successful and failed storage.

### 1.1 0.0.2 state restoration

- Add an API endpoint that checks saved Like events for the same browser token and identifier.
- Query the endpoint when the page loads and restore the filled ♥ when a saved event exists.
- Use the existing LocalStorage browser token and table; do not change the database schema.
- Confirm restoration after reload and separation across browsers and identifiers.

### 2. Design for later stages

- Define database tables, indexes, and storage formats.
- Protect `log/` with Apache `.htaccess` and do not automatically delete archives.
- Aggregation chunking, rollback, and duplicate-count prevention are implemented and were verified against an isolated MariaDB database on 2026-09-29.
- Timezone-change resume, archive-failure recovery, and post-commit recovery were verified in an isolated environment on 2026-09-29.

### 3. Data layer and core processing

- Prepare database setup based on the current specification.
- Implement Like submission, browser-based rate limiting, and public count retrieval.
- Implement period aggregation, rollup from daily to monthly data, and expiry of investigation hashes.
- Prevent double counting and lost records during concurrent access or failures.

### 4. API and embeddable widget

- Implement input validation, HTTP methods, response formats, and errors according to the API specification.
- Use an external JavaScript file to render the button and handle clicks, maintenance state, and network errors.
- Provide an embedding example for future HTML templates.

### 5. Admin screen

- Provide maintenance, rate limit, public count, and statistics controls.
- The local verification page implements the timezone warning and confirmation, aggregation and `log/` storage progress, and completion notice.
- Do not erase active data before archival storage completes.

### 6. Installer and operations

- Prepare installation steps for database configuration, table creation, secret generation, and security checks.
- Protect write-required locations such as `log/` from direct web access.
- Prepare Japanese and English installation and configuration documentation.

### 7. Integration and template migration readiness

- Verify the API, widget, admin screen, aggregation, and timezone change as an end-to-end flow under the actual deployment conditions.
- Verified the automatic aggregation threshold in an isolated MariaDB: 999 pending events were not aggregated; an HTTP Like request for the 1,000th event aggregated 500. Opening the admin page in Edge ran its automatic 30-event batch; both the page and database showed 470 pending, 530 aggregated, and a total of 1,000. Keep the default threshold at 1,000.
- Aggregation rollback/retry, concurrent execution, old-data compaction, and timezone-change recovery are verified in an isolated environment.
- Finalize the embedding instructions for Miyabi's approximately 20 HTML templates.
- Once verified, add the widget to the templates so content migration can follow.

## Decisions for later features

These are not prerequisites for 0.0.1. Decide them before starting the relevant later feature.

### Product decisions

1. CSV formula protection is implemented and was verified in Microsoft Excel on 2026-09-29; formula-like identifiers remained literal text.
2. After beta testing, whether to include time-of-day aggregates in the public release.
3. For a dashboard time-of-day chart, consider bars showing each hour's share of the selected month or year, with the actual hourly count and period total alongside. Decide the denominator, presentation for small totals, and available periods during dashboard design. Since time-of-day data is currently planned for a rolling 24-month window, also decide how to support views of older years if needed.

### Implementation design

1. The 500-event aggregation batch limit is decided; rollback/retry and concurrency protection are implemented and verified in isolation.
2. Timezone-change recovery is verified in isolation; review the operational procedure on the actual host before production deployment.
3. A low-load method for detecting that the selected count has been reached.
4. Storage location and direct-web-access protection for `log/`. The parent specification lists `logs/`, while the current instruction says `log/`; reconcile the directory name during implementation.

## Commit checkpoint

Before production template integration or release preparation, review the implementation and verification results, then discuss the commit scope and any release tag.
