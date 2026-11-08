# Database Design Review

This note consolidates the current specification and design drafts in the parent project for initial implementation. It does not replace the v1.0 specification or decide unresolved behavior.

Decisions confirmed in discussion with Miyabi are recorded in [DatabaseDesignDecisionLog.en.md](DatabaseDesignDecisionLog.en.md). If they differ from the initial questions or recommendations at the end of this review, follow the decision log.

See [ImplementationPlan.en.md](ImplementationPlan.en.md) for implementation stages and remaining work.

## Core data policy

- Store Likes as individual events first. After aggregation, delete those event rows and retain lifetime totals and period aggregates.
- Do not collect or store page titles, referrers, raw IP addresses, raw browser identifiers, or User-Agent values. Select the installation URL from `data-url`, canonical, `og:url`, and the current URL; remove query and fragment and save it only after a successful Like.
- Treat `identifier` as the identity of a Like aggregation target. Use UTF-8 (`utf8mb4`) with a maximum of 100 Unicode code points. Accept Unicode letters, numbers, punctuation and symbols plus ASCII `-` and `_`; reject whitespace, control characters, and other ASCII punctuation or symbols.
- Keep UTC timestamps, `client_hash` for rate limiting, and `ip_hash` for abuse investigation on unaggregated Like events only for the periods required by each purpose.
- Create `ip_hash` with HMAC-SHA-256 and an installation-specific secret; delete it within 60 days. Store the secret in the config file, not in the database.
- Apply rate limits per Like ID and anonymous browser: 1–5 accepted Likes (default 3) in the rolling last 24 hours. Delete expired dedicated rate-limit records on the next Like.
- Trigger aggregation at the selected threshold (1,000 by default) or on admin access. Do not synchronously process a large backlog in one run.
- Keep daily aggregates for at most five years and 50,000 rows, then roll older daily data into monthly aggregates. Make the 50,000-row cap adjustable through program configuration.
- Implement time-of-day aggregation in the beta: group installation-wide Likes by month and hour in the active timezone, retaining the latest 24 months. Decide whether to include it in the public release after testing on Miyabi's site.
- On a timezone change, finalize and archive lifetime, period, and time-of-day aggregates, including pending Likes, in `log/`; reset active data and start aggregation in the new timezone. Show a confirmation dialog before the change. After Yes, show progress stages “Aggregating current data” and “Saving aggregated data”; on completion, confirm that data was aggregated and saved to `log/`. Public counts restart at zero.
- Use UTC for database timestamps. Public count display defaults to off and can be toggled in the admin screen.
- Provide enabled and maintenance states only.

## Candidate fields from the current drafts

### Individual events and aggregate data

| Data | Policy | Implementation detail |
| --- | --- | --- |
| Unaggregated Like events | Retain pending events and delete after successful aggregation | SQL types, indexes, transactional deletion |
| Lifetime totals | Keep per-identifier totals during the current timezone period; archive and reset them when the timezone changes | Rebuild and correction behavior |
| Period aggregates | Keep daily data up to five years/50,000 rows, keep monthly data for five years then roll it into retained yearly data; archive and reset on timezone change | Batches up to 500; aggregate updates and source-event deletion share one transaction |
| Installation URLs | Store identifier, normalized-URL SHA-256, URL, and first/last seen timestamps; allow multiple URLs per ID | Follow the URL candidate priority, remove query and fragment, and register only after a successful Like |
| Investigation `ip_hash` | Store only HMAC value and delete within 60 days | Behavior when IP is unavailable |

### `settings` table

Settings include maintenance state, rate-limit window and count, public count visibility, and the automatic aggregation start threshold. The dropdown values are 1,000 (default), 2,000, 4,000, 6,000, 8,000, and 10,000 pending events. The daily aggregate row cap is a separate setting, initially 50,000 and adjustable through program configuration.

## Remaining verification items

Rollback/retry after aggregation failure and prevention of concurrent runs were verified on 2026-09-29 using an isolated database on XAMPP MariaDB. See [DatabaseDesignDecisionLog.en.md](DatabaseDesignDecisionLog.en.md) for details.

CSV formula protection was verified in Microsoft Excel on 2026-09-29 by opening an archive containing synthetic formula-like identifiers; the identifiers remained text and were not evaluated.

1. Configure direct-access denial for `log/` on non-Apache web servers.

See [DatabaseDesignDecisionLog.en.md](DatabaseDesignDecisionLog.en.md) for confirmed decisions and their history. Do not treat legacy beta behavior as current specification.
