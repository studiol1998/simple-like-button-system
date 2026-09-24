# Implementation Plan

This memo connects the existing Pollyanna v1.0 specification and decisions agreed with Miyabi to implementation work. It does not replace the authoritative specification. See [DatabaseDesignDecisionLog.en.md](DatabaseDesignDecisionLog.en.md) for agreed decisions and [DatabaseDesignReview.en.md](DatabaseDesignReview.en.md) for database design questions.

The old beta is reference material for behavior and migration only; its code will not be adopted as-is.

## Goal

- Deliver a self-hosted Like button that can be embedded easily in a website.
- Implement data minimization, browser-based installation-wide rate limits, period aggregation, admin controls, and installation flow consistently with agreed decisions.
- Verify the completed system so Miyabi can add it to the site's HTML templates.

## Beta version plan

### 0.0.1

- When a visitor taps ♡, change it to a filled, colored ♥ after the server accepts the Like.
- Save the tap in the database.
- Include input validation. Do not apply rate limits in 0.0.1; revisit them by 0.1.0 when admin and operational settings are added.

### 0.0.2 (complete)

- Restore the liked state after reload for the same browser and identifier.
- Account for browser identification in 0.0.1 so this does not require a later database redesign.

### 0.0.3

- Display the Like count.
- Until the admin screen is available, use a configuration-file setting to turn count display on or off.

### 0.0.4

- Implement daily, monthly, and yearly aggregation.
- Aggregate installation-wide Likes by two-hour slot and month in the active timezone, with a simple view of the latest 24 months.

### 0.1.0

- Bundle the admin screen and the functions needed for installation and operation for testing on Miyabi's site.
- Allow settings such as rate limits and public count visibility to be managed in the admin screen.
- Decide whether to include time-of-day aggregates in the public release after testing on the site.

For 0.0.1, prioritize making the Like action and database storage work; do not build all version features at once. Provide a minimal indication if sending or saving fails.

## Agreed requirements

- Record Likes as individual events first, then delete individual rows after aggregation.
- Let admins select an automatic aggregation start threshold of 1,000 (default), 2,000, 4,000, 6,000, 8,000, or 10,000 pending events. Start automatic aggregation when the selected threshold is reached. Do not auto-aggregate only because time has passed; aggregate below-threshold data when the admin screen is accessed. Do not add a scheduled task.
- Calculate the current count from stored aggregates plus pending events. Public count display defaults to off and can be toggled in the admin screen.
- Keep daily data for up to five years and 50,000 rows; when either limit is reached, roll older data into monthly aggregates. The five-year window leaves a buffer around the roughly three years of detail likely to be useful. The 50,000-row value is adjustable through program configuration.
- Keep monthly aggregates for five years after they are created, then roll them into yearly aggregates. Retain yearly aggregates.
- Implement time-of-day aggregation and admin display in the beta, then decide whether to include it in the public release after testing on Miyabi's site. Group installation-wide Likes by month and two-hour slot in the active timezone; retain the latest 24 months. Process events in existing aggregation batches and store only nonzero slots.
- On a timezone change, aggregate current lifetime and period data, including pending events, save it as CSV in a timestamped folder under `log/`, reset active data, and begin aggregating under the new timezone. Public counts restart at zero.
- Before changing the timezone, show a confirmation dialog. After Yes, show aggregation and storage progress; when complete, confirm that the data was saved to `log/`.
- Do not automatically delete archives after a fixed period or provide download/deletion controls in the admin screen. The completion dialog gently advises that log files can be deleted if they put pressure on server storage; administrators handle any backup and deletion manually.
- For each timezone change, create a timestamped folder containing `metadata.json`, `lifetime_totals.csv`, and `period_aggregates.csv`. The totals CSV columns are `identifier,total_likes`; the period CSV columns are `period_type,period_start,identifier,like_count`.
- Store timestamps in UTC. Never store raw IP addresses. Delete investigation IP hashes within 60 days.
- Apply rate limits per browser installation-wide. Do not use `ip_hash` to reject Likes.
- Provide enabled and maintenance states only.

## Expected day-to-day operation

1. When a visitor clicks the button, the API validates the identifier, browser token, and rate limit, then records an individual event with a UTC timestamp and the required hashes.
2. If public count display is enabled, the API returns the current total plus pending events. If disabled, the public API does not return a count.
3. Start aggregation when the configured automatic start threshold is reached or when the admin screen is accessed. Process bounded batches and delete individual events only after their aggregate updates are saved successfully.
4. Assign events to reporting dates using the active timezone and update lifetime totals and daily aggregates. Roll older daily data into monthly aggregates when it reaches five years of age or the daily row cap, whichever comes first.
5. Roll monthly aggregates into yearly aggregates after five years as monthly data, and retain yearly aggregates.
6. Update time-of-day aggregates by month and two-hour slot within existing batches, retaining only the latest 24 months.
7. Delete rate-limit records after their configured window and investigation IP hashes within 60 days. The design must allow an expired IP hash to be cleared even if its individual Like event remains pending.

If the pending count remains below the configured threshold and the admin screen is not accessed, retain events as unaggregated records. The batch size and recovery after failure are implementation decisions.

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
- Decide `log/` archive policy.
- Design chunking, failure recovery, and duplicate-count prevention for aggregation.
- Define safe recovery behavior if a timezone change fails partway through.

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
- Implement the timezone warning and confirmation, aggregation and `log/` storage progress, and completion notice.
- Do not erase active data before archival storage completes.

### 6. Installer and operations

- Prepare installation steps for database configuration, table creation, secret generation, and security checks.
- Protect write-required locations such as `log/` from direct web access.
- Prepare Japanese and English installation and configuration documentation.

### 7. Integration and template migration readiness

- Verify the API, widget, admin screen, aggregation, and timezone change as an end-to-end flow under the actual deployment conditions.
- For aggregation verification, lower the production initial threshold of 1,000 to 20 in test configuration. This test setting does not change the production default.
- Confirm automatic aggregation at 20 pending events, no automatic aggregation below 20 even after one hour, aggregation of below-threshold data when the admin screen is accessed, and no double counting when a batch is retried.
- Finalize the embedding instructions for Miyabi's approximately 20 HTML templates.
- Once verified, add the widget to the templates so content migration can follow.

## Decisions for later features

These are not prerequisites for 0.0.1. Decide them before starting the relevant later feature.

### Product decisions

1. Define how identifiers are emitted in archive CSVs so spreadsheet software does not mistakenly evaluate them as formulas.
2. After beta testing, whether to include time-of-day aggregates in the public release.

### Implementation design

1. Aggregation batch limit, resume position, and protection against counting an event twice.
2. Safe sequencing of aggregation, archival, and reset during a timezone change.
3. A low-load method for detecting that the selected count has been reached.
4. Storage location and direct-web-access protection for `log/`. The parent specification lists `logs/`, while the current instruction says `log/`; reconcile the directory name during implementation.

## Commit checkpoint

Before 0.0.1 implementation begins, commit and push the design memo and included changes and create a tag, as Miyabi requested. Confirm the tag name and included scope against the working tree at that checkpoint.
