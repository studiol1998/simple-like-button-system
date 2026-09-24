# Database Design Decision Log

This document records decisions and open questions as each design topic is discussed.

## 1. Rate limits and hash retention

### Agreed

- Apply rate limits installation-wide, summing Likes across all identifiers for each browser's `client_hash`.
- Store a random browser token in LocalStorage and store only its HMAC on the server, not the token itself.
- Do not use `ip_hash` to block Likes; use it to investigate abuse.
- Delete IP-hash investigation records after at most 60 days. Never store raw IP addresses.
- Delete `client_hash` records used only for rate-limit checks after the configured limit window expires.

### Design implications

`client_hash` and investigation `ip_hash` have different purposes and retention periods. Manage short-lived rate-limit records separately from the 60-day investigation log. Since IP hashes can affect people sharing a network, do not use them as a Like rejection threshold.

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

Use each individual record's timestamp to place it in the appropriate reporting period. The implementation must define how many records to process per run, how to resume after failure, and how to avoid counting the same record twice. Do not synchronously process a large backlog during one page view.

Long-term statistics use these two forms:

- **Lifetime total:** total Likes per identifier during the current timezone period. On a timezone change, archive it and reset the active total.
- **Period aggregates:** Keep daily data until it reaches either five years of age or 50,000 rows, then roll older data into monthly aggregates. Keep monthly aggregates for five years, then roll them into yearly aggregates. Retain yearly aggregates. Make the 50,000-row cap adjustable through program configuration.
- **Time-of-day aggregates:** Implement collection and admin display in the beta, then decide whether to include the feature in the public release after testing it on Miyabi's site. Aggregate installation-wide Likes by two-hour slot in the active timezone, grouped by month, and retain the latest 24 months. Process events in the existing aggregation batches, not on every Like; store only nonzero slots and delete data outside the rolling 24-month window.

This avoids keeping individual timestamps and hashes for a long time. However, individual events cannot be replayed later to rebuild or correct aggregates. The specification is to roll older daily data into monthly aggregates when it reaches five years of age or 50,000 rows, whichever comes first. The five-year daily window covers roughly three years of detail likely to be useful, with a buffer against timing differences; if the 50,000-row cap is reached first, rollup happens sooner. Keep monthly aggregates for five years, then roll them into yearly aggregates and retain the yearly data. The 50,000-row cap can be changed through program configuration after launch.

Treat a timezone change as a change in the country or region of operation. Do not regroup historical aggregates using the new timezone. Finalize the lifetime, period, and time-of-day aggregates up to the change point, including any pending Likes, and save them in `log/`. Then reset active aggregate data and start fresh aggregation using the new timezone. Public counts therefore start from zero again.

Before applying a timezone change, show a confirmation dialog explaining that accumulated data will be reset. After the user selects Yes, show progress with the stages “Aggregating current data” and “Saving aggregated data.” When saving is complete, identify `log/` as the storage location and gently advise that log files can be deleted if they put pressure on server storage. Administrators handle any local backup and server-side deletion manually. Do not automatically delete archives after a fixed period.

Create a timestamped folder in `log/` for each timezone change. Record metadata such as the change time and old/new timezones in `metadata.json`. Store aggregates in two files: `lifetime_totals.csv` for totals per identifier, and `period_aggregates.csv` for daily, monthly, and yearly values with columns for period type, period start, identifier, and Like count. Do not automatically delete archives or provide download/deletion controls in the admin screen; administrators handle optional local backups and server-side deletion manually. The completion dialog advises deleting logs if they put pressure on server storage. Define CSV output handling so commas and line breaks in identifiers are represented correctly and spreadsheet software does not mistakenly evaluate identifiers as formulas.

### Decisions to make next

1. What batch size, failure-resumption strategy, and duplicate-count prevention should aggregation use?
2. Based on beta testing, should time-of-day aggregates be included in the public release?
3. How should identifiers be emitted in archive CSVs to prevent spreadsheet software from mistakenly evaluating them as formulas?
