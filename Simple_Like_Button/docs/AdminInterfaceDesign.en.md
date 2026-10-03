# Admin interface design proposal

Created: 2026-10-03. The three tabs and separate settings save groups are agreed requirements. Layout, chart details, and interactions below are review proposals.

[Static preview](../tests/admin-design-preview.html): open directly in a browser. It uses sample data, has no database or configuration connection, and simulates saving only.

## Shared layout

Show “Pollyanna — An Easy-to-Install Like Button System,” service status, and Dashboard / Details / Settings tabs at the top. Center the full product name and “Developed by Nikukiu-SYSTEM” on two lines in the footer. Mark the selected tab with text and an underline, support keyboard navigation, stack cards on narrow screens, and allow table scrolling. Preserve edits between tabs and warn before leaving with unsaved changes. Admin and public languages remain independent; the implementation will use the existing locale JSON files.

Style the feature tabs as bordered index labels attached to the page edge. Give the selected tab a white background, slightly greater height, and an accent along its top edge. Allow horizontal scrolling on narrow screens.

Use a 16px base font size for the admin interface and generally keep supporting text and labels at 14px or larger.

## Dashboard

Place system information to the right of the Dashboard heading, aligning its left edge with the text inside the Aggregated summary card below. Set the Dashboard heading to 34px, with 16px system heading/values, 14px field labels, and separators between fields. Put the admin Language selector here; apply and save it immediately, without duplicating it in Settings. Stack below the heading on narrow screens, with vertical and horizontal separators in the two-column field grid. The chart uses the full width below the summary cards.

Show total Likes (aggregated plus pending), the aggregated/pending breakdown, distinct Like IDs across both sources, a seven-day daily chart, and system information: actual application version, state, timezone, and retrieval time. Do not infer titles or URLs from Like IDs. The preview labels the version as a development preview.

Updated 2026-10-03: Show three charts side by side: seven daily bars, twelve monthly bars including the current month, and 24 hourly bars for the current calendar month. Split each chart subtitle into two fixed 15px lines: coverage period and data description. Stack the charts into one column on narrow screens. Use a 3px gap between bars. Show actual coverage and label the current month as incomplete. Hourly data includes aggregates only, with counts and percentages in a companion table. A rolling 30-day hourly view cannot be reconstructed from the existing monthly hourly aggregates; settle that period definition/storage before live implementation. Colors will be adjusted later.

Use the existing aggregation batch on admin entry (up to 500 records), then refresh the summary. Combine daily aggregates and pending events in the configured timezone using a consistent snapshot to prevent double counting. A failed request must not be represented as zero.

## Details

Place the hourly trend chart above search and list controls so the overall trend is visible first. Keep the month selector only as wide as its value and place the label, selector, and period total on one line.

Search by Like ID and select dates and daily/monthly/yearly granularity. Show the ID lifetime total, period total, chart, and table. Paginate the complete ID list instead of fixing it to the latest 100.

Keep the ID, period, and granularity controls only as wide as their contents and align them from the left. Add installation URLs as the rightmost list column. Associate each ID with canonical URLs explicitly supplied through `data-url`; never collect the browsing URL automatically, and support multiple locations for a shared ID. `pollyanna_identifier_urls` and the admin display were implemented on 2026-10-03.

Hourly statistics are site-wide, shown separately from ID details: the current storage cannot provide hourly statistics for each ID. Select a month or year and show 24 hourly bars, counts, and percentages. The denominator is the selected period's total hourly count. Label these as aggregated data and show the aggregation time. Empty datasets display an empty state without percentage calculations.

Hourly data is retained for 24 months. Label partially retained years as covering only available data; missing dates are not zero. Rolled-up daily data cannot be reconstructed at daily granularity. Explain available granularity and retention. Provide tables alongside charts.

## Settings

Place the aggregation timezone selector and change action to the right of the Settings heading, including the archive/reset warning in the same area. Stack it below the heading on narrow screens.

| Save group | Fields |
| --- | --- |
| Save admin and operation settings | Service state, aggregation threshold |
| Save public display settings | Public language, success message, per-ID/per-browser rolling 24-hour limit, count visibility, refresh interval, count color |

Provide one save button per group. Validate the full group before saving, preserve inputs on failure, and show saved/unsaved status. Saving one group must not commit edits in the other. The preview does not persist changes.

Provide nine preset admin color themes: System, Light, Dark, Green, Blue, Orange, Red, Purple, and Monochrome. Color themes use a white base with the selected color as an accent on tabs, buttons, and charts. System follows the device light/dark preference. Apply and save the selection immediately from Settings. Keep this independent from the public Like count color.

Disable refresh/color controls while counts are hidden, retaining their values. Refresh defaults to five seconds; zero disables polling. Offer Like limits 1–5 in ascending order, default 3. Generate success-message options from the selected public locale JSON.

Apply and save the admin language immediately when it is selected on the Dashboard. Grouped saves must prevent partial application across the existing configuration files; do not simply call each individual JSON writer in sequence.

Timezone changes are a separate operation inside Settings, with an explicit confirmation of aggregate/public-count reset, archive progress, completion location, and interrupted-operation recovery. Connect to the existing service without triggering it from normal save buttons. Any manual aggregation control belongs with aggregation status and must be distinct from refreshing charts.

## Implementation status and next steps

The production admin interface was connected on 2026-10-03. The static preview remains only as a design reference. The production screen now uses live database data, persistent settings, and authenticated sessions.

Live Dashboard, Details, Settings, grouped saves, timezone changes, authentication, and explicit `data-url` registration are complete. The next stage is the web installer, live-server end-to-end verification, and final color adjustments. Decide the next commit and push after the installer work.
