# Simple Like-button System

Pollyanna is a lightweight, standalone Like-button system. This directory is the implementation workspace.

## Working principles

- Follow the current specifications in the parent `Pollyanna_Specification_v1.0` directory.
- Limit changes to the requested scope and respect the existing design.
- `legacy/like_button_beta_package/` is for historical reference. Do not deploy it as-is.
- If code, specifications, or older materials disagree, report the discrepancy instead of deciding on new behavior without direction.

## Previous beta

The previous PHP beta is preserved under `legacy/`. See [docs/LegacyMigration.en.md](docs/LegacyMigration.en.md) for how it maps to the current specification and for known limitations.

## Important: Changing the Timezone

Change the timezone when the country or region of operation changes. The system finalizes accumulated Like totals, period aggregates, and time-of-day aggregates, including pending Likes, saves them as CSV files in a timestamped folder under `log/`, resets the active aggregate data, and starts collecting under the new timezone. Public Like counts also restart at zero.

Before applying a timezone change, the admin screen must show a confirmation dialog explaining that accumulated data will be reset. After the user selects Yes, show progress stages: “Aggregating current data” and “Saving aggregated data.” When saving is complete, identify `log/` as the archive location and gently advise that log files can be deleted if they put pressure on server storage. Archives are not automatically deleted, and the admin screen does not provide download or deletion controls. Administrators handle any optional backup and server-side deletion manually. Historical aggregates are not recalculated using the new timezone.
