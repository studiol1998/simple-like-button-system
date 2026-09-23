# Database Design Review

This note consolidates the current specification and design drafts in the parent project for initial implementation. It does not replace the v1.0 specification or decide unresolved behavior.

## Requirements already stated

- Store Like events and calculate aggregates from those events rather than storing aggregate values.
- Do not store page titles, URLs, referrers, raw IP addresses, raw browser identifiers, or User-Agent values.
- Treat `identifier` as an opaque string. Use UTF-8 (`utf8mb4`), with a maximum length of 50 characters. Trim leading and trailing whitespace before validation; reject empty values, newlines, and NUL characters.
- Store `created_at` for time-based aggregation.
- Store `client_hash` for rate limiting.
- Use HMAC-SHA-256 with an installation-specific secret to create `ip_hash` as a secondary measure against rate-limit bypass. Store the secret in the generated config file, not in the database.
- Store maintenance state and the configured rate-limit window and count in settings.

## Candidate fields from the current drafts

### `likes` table

| Field | Stated requirement | Schema decision still needed |
| --- | --- | --- |
| `id` | Event identifier | Integer width and generated-key syntax |
| `identifier` | UTF-8, maximum 50 characters, non-empty after trimming | Exact SQL type and collation |
| `created_at` | Submission time for time-based aggregates | Stored timezone and SQL type |
| `client_hash` | Rate-limit key derived from a client-side identifier | Exact length/type and identifier lifecycle |
| `ip_hash` | HMAC-SHA-256 secondary rate-limit key | Exact length/type and behavior when IP is unavailable |

### `settings` table

The drafts list maintenance state, limit time, and limit count. They do not specify singleton enforcement, allowed values, or whether the disabled mode from the beta is part of the current design.

## Questions to resolve before finalizing `install.sql`

1. Does the rate limit apply per client across the whole installation, per client and `identifier` pair, or both? The beta applied a global IP-based limit, but the current specification does not confirm that behavior.
2. How long should Like events be retained? Keeping all events or defining a retention period affects statistics and storage growth.
3. Which timezone defines daily and weekly statistics? The current drafts do not specify a timezone or the first day of the week.
4. Should the current system have only enabled and maintenance states, or also a disabled state? The v1.0 admin draft mentions maintenance only; the beta has three states.
5. How is the client identifier created, stored, and rotated? The draft mentions Cookie and LocalStorage but does not specify precedence or recovery behavior.

These points remain open. The legacy beta alone is not sufficient reason to decide them.
