# Database Design Decision Log

This document records decisions and open questions as each design topic is discussed.

## 1. Rate limits and hash retention

### Agreed

- Apply rate limits per Like ID (work) and anonymous browser. Check Likes in the rolling last 24 hours; the admin selects 1–5, with a default of 3.
- Store a random browser token in LocalStorage and store only its HMAC on the server, not the token itself.
- Do not use `ip_hash` to block Likes; use it to investigate abuse.
- Delete IP-hash investigation records after at most 60 days. Never store raw IP addresses.
- Keep rate-limit events separately from aggregation events, only as long as needed for the rolling 24-hour check. Expired events are removed the next time that browser Likes the same Like ID.

### Design implications

`client_hash` and investigation `ip_hash` have different purposes and retention periods. Manage short-lived rate-limit records separately from the 60-day investigation log. Since IP hashes can affect people sharing a network, do not use them as a Like rejection threshold. Because the public widget has no login, the limit is per anonymous browser, not per person.

## 2. Long-term records and aggregation

### Miyabi's preferences

- Keep daily aggregates for up to five years, then roll older daily data into monthly aggregates.
- Cap daily aggregates at 50,000 rows. Roll older daily data into monthly aggregates when it reaches five years of age or the row cap, whichever comes first.
- Treat 50,000 as the initial specification value and make it adjustable through program configuration after launch.
- Keep daily data for five years to cover roughly three years of detail likely to be useful, with a buffer against timing differences that could roll up useful detail too soon.
- Keep hashes linked to individual Likes only for as long as rate limiting or investigation needs them.
- Individual submission timestamps may no longer be needed after aggregation.
- Avoid aggregating many years of data whenever the admin screen opens; that could be slow and create heavy server load.
- Let administrators choose the automatic aggregation threshold from a dropdown with these values: 1,000 (default), 2,000, 4,000, 6,000, 8,000, and 10,000 pending Likes. This start threshold is separate from the maximum size of one processing batch.
- Automatically start aggregation when the selected threshold is reached. Do not automatically aggregate only because time has passed; aggregate below-threshold data when the admin screen is accessed. Do not add a scheduled task.
- Hide the public Like count by default, with a global on/off setting in the admin screen. Admin statistics are separate.

### Agreed aggregation model

Store new Likes as individual records first. Roll older records into aggregates, then delete the individual records. Keep aggregated values and unaggregated individual records separate; combine them to get the latest count when needed.

Let administrators choose the automatic aggregation start threshold from 1,000 (default), 2,000, 4,000, 6,000, 8,000, or 10,000 pending events. Start aggregation automatically when the selected threshold is reached. Do not trigger automatic aggregation based only on elapsed time; when the pending count is below threshold, start aggregation on admin-screen access. Do not add a scheduled task. The selected threshold is not the maximum size of one processing batch or a strict storage cap. When public count display is enabled, add pending individual records to past aggregates. When disabled, the public API must not return the count.

Keep the aggregation batch maximum at 500 events for now, without an admin control. This limit keeps each run lightweight and is separate from the automatic aggregation start threshold. Below-threshold events are also picked up when the admin screen is accessed. Reconsider how to change the limit if operational experience shows a need.

Use short database transactions for aggregation. In each batch, select individual events, update aggregate values, and delete the processed events within the same transaction. If any step fails, roll back the whole batch and retry from the same individual events. Use a lock to prevent aggregation runs from executing concurrently.

Use each event timestamp to select its reporting period. A normal batch processes at most 500 events. Select events, update aggregate values, and delete processed events in one database transaction; roll back on failure. A database-scoped advisory lock prevents concurrent aggregation. Since events are deleted only when their aggregate updates commit in the same transaction, retrying a failed batch does not double count. The verification page is capped at 30 records per request.

Long-term statistics use these two forms:

- **Lifetime total:** total Likes per identifier during the current timezone period. On a timezone change, archive it and reset the active total.
- **Period aggregates:** Keep daily data until it reaches either five years of age or 50,000 rows, then roll older data into monthly aggregates. Keep monthly aggregates for five years, then roll them into yearly aggregates. Retain yearly aggregates. Make the 50,000-row cap adjustable through program configuration.
- **Time-of-day aggregates:** Implement collection and admin display in the beta, then decide whether to include the feature in the public release after testing it on Miyabi's site. Aggregate installation-wide Likes by hour in the active timezone, grouped by month, and retain the latest 24 months. Process events in the existing aggregation batches, not on every Like; store only nonzero slots and delete data outside the rolling 24-month window.

This avoids keeping individual timestamps and hashes for a long time. However, individual events cannot be replayed later to rebuild or correct aggregates. The specification is to roll older daily data into monthly aggregates when it reaches five years of age or 50,000 rows, whichever comes first. The five-year daily window covers roughly three years of detail likely to be useful, with a buffer against timing differences; if the 50,000-row cap is reached first, rollup happens sooner. Keep monthly aggregates for five years, then roll them into yearly aggregates and retain the yearly data. The 50,000-row cap can be changed through program configuration after launch.

Treat a timezone change as a change in the country or region of operation. Do not regroup historical aggregates using the new timezone. Finalize the lifetime, period, and time-of-day aggregates up to the change point, including any pending Likes, and save them in `log/`. Then reset active aggregate data and start fresh aggregation using the new timezone. Public counts therefore start from zero again.

Before applying a timezone change, show a confirmation dialog explaining that accumulated data will be reset. After the user selects Yes, show progress with the stages “Aggregating current data” and “Saving aggregated data.” When saving is complete, identify `log/` as the storage location and gently advise that log files can be deleted if they put pressure on server storage. Administrators handle any local backup and server-side deletion manually. Do not automatically delete archives after a fixed period.

Create a timestamped folder in `log/` for each timezone change. Record metadata such as the change time and old/new timezones in `metadata.json`. Store aggregates in `lifetime_totals.csv`, `period_aggregates.csv`, and `hourly_aggregates.csv`. Write CSV with `fputcsv`; prefix identifiers that could be interpreted as formulas with an apostrophe. Do not automatically delete archives or provide download/deletion controls in the admin screen; administrators handle optional local backups and server-side deletion manually.

Put the public APIs into maintenance during the change and aggregate pending events in batches of at most 500. Only delete active aggregate tables and save the new timezone after all archive files are complete. If archiving or the database operation fails, roll back the database transaction and keep the old timezone. Persist enough state to resume an interrupted operation from the local admin page. On Apache, deny direct access to `log/` with `log/.htaccess`.

### Post-implementation verification (2026-09-29)

Created and removed the isolated `pollyanna_integration_tmp` database on XAMPP MariaDB 10.4.32. An intentional SQL error during hourly aggregation left both source events intact and all lifetime, period, and hourly aggregate tables unchanged. After removing the fault, retry processed the two events once; a subsequent run processed zero, confirming no double count. Two concurrent PHP workers were also started: one processed two events and the other processed zero. The final total was two with no pending events.

Using another isolated database and filesystem, verified old daily/monthly rollups, the 50,000-row daily cap, and interrupted timezone-change recovery. An archive-protection failure preserved the old timezone, aggregate data, and maintenance state; after fixing the rule, resuming completed the archive, reset active aggregates, and restored the previous state. Recovery from an interruption after commit was also verified.

Generated archive CSVs from an isolated database with synthetic identifiers such as `=1+1`, `+SUM(1,1)`, `-1+1`, and `@SUM(1,1)`, then opened them in Microsoft Excel. Identifiers in column A of `lifetime_totals.csv` and column C of `period_aggregates.csv` appeared as apostrophe-prefixed text and were not evaluated as formulas. The sample archive is marked test-only in XAMPP `log/csv-safety-test-20260929/`.

### Decisions to make next

1. Based on beta testing, should time-of-day aggregates be included in the public release?

## 3. Liked state after aggregation

### Agreed approach

- Store liked identifiers in browser LocalStorage so the same browser and identifier can restore the ♥ state after individual events are deleted by aggregation.
- Use existing unaggregated events for server-side state checks; when a saved Like is found, also save the identifier in browser storage.
- Treat cleared LocalStorage as a new visitor. Do not retain browser-specific history on the server long-term solely to preserve the liked state.

## 4. Time-of-day aggregates on the dashboard (defer details)

One dashboard idea is to show time-of-day aggregates by month or year. Use bars for each hour's share, show the actual count alongside each bar, and display the total for the selected period so the percentages are not read in isolation. Decide the denominator, presentation for small totals, and available periods during dashboard design.

The current retention proposal keeps time-of-day data for the latest 24 months. A yearly view can sum the stored monthly values, but dashboard design should decide whether to retain separate yearly hourly aggregates if users need to view years older than that window.

## 5. Installation URLs and admin authentication (2026-10-03)

- Do not collect the browsing URL automatically. Store only a canonical HTTP/HTTPS URL explicitly supplied by the installer in widget `data-url`.
- Store URLs in `pollyanna_identifier_urls`, keyed by Like ID and the URL SHA-256. Allow multiple URLs for one ID.
- Reject URLs containing credentials or fragments. Register or update last-seen time only when `data-url` is present during state restoration or Like submission.

## 6. v1.0 Phase 1 update (2026-10-07)

- `identifier` identifies an aggregation target rather than an individual button, and multiple widgets may share it.
- Expand the maximum to 100 Unicode code points and provide a migration for the five existing tables.
- Generate an identifier from the current path and DOM position only when `data-identifier` is absent; an empty attribute is an error.
- Select installation URLs from `data-url`, canonical, `og:url`, and the current URL; remove query and fragment and register only after a successful Like. This section supersedes the older registration conditions above.
- Require an admin password of at least 12 characters and store only the `password_hash()` result in `config/admin_credentials.json`.
- Restrict initial password setup to localhost. Regenerate the session ID after login and use CSRF validation, a five-attempt-per-minute limit, and logout.
