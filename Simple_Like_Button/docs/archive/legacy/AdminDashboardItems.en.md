# Admin Dashboard Items

This document inventories the information, settings, and actions needed in the admin interface before its final layout and charts are designed. It does not change any agreed product decision.

2026-10-03: The [interface proposal](AdminInterfaceDesign.en.md) uses Dashboard / Details / Settings tabs and separate admin/operation and public-display save groups. `admin/index.php` now provides the shared layout, Japanese/English switching, saved language preference, and a live-DB Dashboard summary with three charts. Access remains restricted to localhost until authentication is implemented. The inventory below describes the existing implementation.

## 1. Aggregation status

| Item | Content | Status |
| --- | --- | --- |
| Aggregated Likes | Sum of lifetime totals across all Like IDs | Implemented on the local verification page |
| Pending Like records | Number of individual records remaining in `pollyanna_likes` | Implemented on the local verification page |
| Latest batch result | Records processed and pending counts before and after the batch | Implemented on the local verification page |
| Daily aggregate rows | Current daily row count and the 50,000-row cap | Implemented on the local verification page |

## 2. Aggregation by Like ID

| Item | Content | Status |
| --- | --- | --- |
| Lifetime total by Like ID | Total Like count for each Like ID | Implemented; the local page shows the latest 100 entries |
| Period aggregates | Start date and count by Like ID for day, month, and year | Implemented; the local page shows the latest 100 rows |
| Retention policy | Daily data up to five years or 50,000 rows; monthly data for five years after monthly roll-up; yearly data retained | Roll-up code implemented; not validated with genuinely aged data |

The final admin interface should consider filtering by period and Like ID instead of limiting the view to the latest 100 rows.

## 3. Site-wide hourly aggregates

| Item | Content | Status |
| --- | --- | --- |
| Hourly counts | Counts grouped by hour in the configured timezone | Implemented; shown as a monthly table for the latest 24 months on the local page |
| Month/year selection | Select a month or year to inspect | Final selection method undecided |
| Percentage and count | Proposed bar chart with percentages, actual counts, and period total | To be decided during dashboard design |

## 4. Operational settings

| Item | Decision | Status |
| --- | --- | --- |
| Admin language | Select Japanese or English. Use “いいね ID” in Japanese and “Like ID” in English | Implemented in the production Dashboard's System information. The selection applies immediately and is saved as the default. Add `lang/admin.<locale>.json` to add another language |
| Public language and click-success message | Select the site's display language and the message shown after a Like | Japanese/English and the success message are selectable on the local verification page. Text comes from `lang/widget.<locale>.json`; option keys use numbered names such as `like01` and `like02`, independently from the admin interface language |
| System state | ON or maintenance | Toggle on the localhost-only verification page. Saved in `config/system_state.json`; state and Like APIs return 503 during maintenance |
| Like limit | Choose 1–5 Likes per Like ID (work) and anonymous browser in a rolling 24-hour window; default is 3 | Selectable and saved on the local verification page; enforced by the API |
| Public count display | Toggle on/off; default off | Toggle and save on the local verification page; immediately used by the public API |
| Public count auto-refresh | 5 seconds (default), 10, 30, 60, or off (0 seconds) | Poll only while public counts are enabled; pause while the browser tab is hidden |
| Public count color | Set from the admin interface | Selectable and saved on the local verification page; applied to the public widget |
| Aggregation start threshold | Choose 1,000 (default), 2,000, 4,000, 6,000, 8,000, or 10,000 | Selectable and saved on the local page in `config/aggregation_threshold_settings.json`; used by the Like API's automatic aggregation check |
| Batch size | 500, separate from the start threshold | Fixed at 500 for now with no admin control. Shown on the local page; the verification page is capped at 30 |
| Timezone | Timezone used for aggregation | Changeable on the local page. Pending events are aggregated and archived as CSV before aggregate tables are reset; public APIs remain in maintenance during the change |

### Editing public messages

- Edit the `success_messages` section in `lang/widget.ja.json` and `lang/widget.en.json`.
- To change existing wording, keep keys such as `like01` and edit only the string value.
- To add an option, use the next numbered key, such as `like07`. Add the same key to both locale files with translated text if it should be available in Japanese and English.
- Removing or renaming the currently selected key can invalidate the saved setting; select another message in the admin page afterward.

## 5. Timezone changes and archives

- Before changing the timezone, confirm that lifetime, period, and hourly data and the public count will be reset.
- After confirmation, show progress for aggregating pending records and saving data to `log/`. On failure, preserve data and save enough state to resume.
- On success, show the `log/` location and advise manual backup/deletion if server storage is a concern.
- Record the change time and old/new timezones, and save lifetime, period, and hourly aggregates as CSV files. Do not clear aggregate tables until the archive is complete.
- Implemented on the local verification page. CSV formula safety was verified in Microsoft Excel using synthetic formula-like identifiers. Non-Apache servers need their own access-denial rule.

## 6. Privacy and abuse investigation

- Do not store raw IP addresses. Delete investigation `ip_hash` records within 60 days.
- Do not expose the `ip_hash` value itself as a normal admin display item.
- Email alerts for sudden Like spikes were discussed, but the alert threshold, configuration, and dashboard fields are undecided and are not treated as finalized requirements.

## 7. Implementation and verification boundaries

- The current local verification page has no login and is restricted to localhost. It is for testing and must not be published as the production admin interface.
- Production admin authentication and authorization are not implemented. The state control is available only on the localhost-only verification page.
- The latest-100-row table is only for verification. Final listings, charts, and filters remain to be designed.
