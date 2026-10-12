# Simple Like-button System

Pollyanna is a lightweight, standalone Like-button system. This directory is the implementation workspace.

## Working principles

- Follow the current specifications in the parent `Pollyanna_Specification_v1.0` directory.
- Limit changes to the requested scope and respect the existing design.
- `legacy/like_button_beta_package/` is for historical reference. Do not deploy it as-is.
- If code, specifications, or older materials disagree, report the discrepancy instead of deciding on new behavior without direction.

## Previous beta

The previous PHP beta is preserved under `legacy/`. See [docs/LegacyMigration.en.md](docs/LegacyMigration.en.md) for how it maps to the current specification and for known limitations.

## Current features

- Japanese and English widget text, with selectable Like success messages
- Restoring the same browser's Like state for each identifier
- A rolling limit of 1–5 Likes per identifier and anonymous browser in 24 hours (default: 3)
- Six text-symbol presets, unliked/liked symbol colors, and public Like count visibility, color, and refresh interval (5 seconds by default; 0 disables refresh)
- A configurable automatic aggregation threshold (default: 1,000 events)
- Daily, monthly, yearly, and time-of-day aggregates
- CSV archival and aggregate reset when changing the timezone
- Password-protected admin interface and browser-based web installer

## Requirements

- PHP 8.1 or later with PDO MySQL enabled.
- MySQL or MariaDB. Tables in `install/install.sql` use InnoDB.
- On Apache, `log/.htaccess` denies direct access to archives. On other web servers, add an equivalent access-denial rule in the server configuration.
- Keep the widget JavaScript, CSS, and API on the same site (same origin). Cross-origin embedding and CORS are not supported.

## Installation

1. In the hosting control panel, create one empty database dedicated to Pollyanna.
2. Create or select a database user and assign it to that database. Grant `CREATE`, `SELECT`, `INSERT`, `UPDATE`, and `DELETE`. If individual selection is unclear, grant all privileges only on the dedicated Pollyanna database.
3. Copy the database host, port, database name, database username, and database password exactly as displayed. Providers may automatically add characters before database and user names.
4. Upload the package and allow the PHP process to write to `config/`. On non-Apache servers, deny web access to `config/` and `log/`.
5. Open `/your-install-path/install/` in a browser and proceed through Welcome, Environment check, Database setup, Basic settings, and Confirmation.
6. Set the aggregation timezone and an admin password of at least 12 characters, then run the installation. Database and admin passwords are not redisplayed on the review screen.
7. When the completion screen appears, delete the entire `install/` directory from the server. Admin remains locked until it is deleted.
8. Open `/your-install-path/admin/` and sign in with the admin password you set.
9. Add this snippet to the page where the widget should appear. Replace `/Simple_Like_Button/` with the deployed path.

The installer creates tables, initial data, the secret, configuration, and admin credentials inside the prepared empty database. It does not create the database or database user.

For a manual installation, create `config/config.php` from `config/config.example.php`, set a unique 64-character hexadecimal `app_secret`, and execute `install/install.sql`. Manual setup must also provide admin credentials and completion state, so the web installer is recommended.

```html
<link rel="stylesheet" href="/Simple_Like_Button/assets/like.css?v=1">
<div data-pollyanna-like data-identifier="article-001" data-url="https://example.com/articles/001"></div>
<script defer src="/Simple_Like_Button/assets/like.js"></script>
```

`data-identifier` identifies the target being counted. Reusing the same value for the same target shares its count, liked state, and rate limit. Manual values are limited to 100 Unicode code points and cannot contain whitespace or ASCII symbols except `-` and `_`. Omitting the attribute generates an identifier from the current path and DOM position; an empty attribute is an error. When updating the CSS, change `v=1` to refresh browser caches.

The installation URL is selected from `data-url`, canonical, `og:url`, and the current page URL in that order. Its query and fragment are removed, and it is saved only after a Like succeeds. One identifier can have multiple URLs.

The browser identifier is stored in LocalStorage; the database stores only its HMAC, generated with the installation secret. Raw IP addresses are not stored. Clearing LocalStorage makes that browser appear as a new anonymous visitor.

## Editing language and messages

Edit widget text in `lang/widget.ja.json` and `lang/widget.en.json`. To change a success message, keep its key (such as `like01`) and edit the value. To add an option, add the same numbered key to both JSON files. JSON does not support comments.

## Access concentration detection (v1.0.0-beta.1)

The Access concentration detection tab shows monitoring, current blocks and the last 60 days of history above the settings. History offers 20 (default), 50, 80 or 100 entries per page, with the choice saved in the browser. New installations default to detection ON and automatic blocking ON. Existing saved settings are preserved; legacy installations without a settings file retain detection ON and automatic blocking OFF. Turning detection OFF also turns automatic blocking OFF and disables its control. Turning detection back ON enables the control but does not turn automatic blocking back ON. Detection-only operation is available. Legacy detection OFF / automatic blocking ON settings are normalized to both OFF when read and cannot be saved in that inconsistent state.

| Admin setting | API | Default monitoring window | Default request count |
| --- | --- | --- | --- |
| Retrieving Like information | `state.php` | 60 seconds | 2,400 |
| Sending a Like | `like.php` | 60 seconds | 240 |

Retrieving Like information checks Like counts and whether a Like has been sent; these requests normally run automatically. The default detects more than 2,400 requests in 60 seconds. A button updating every five seconds makes approximately 12 requests/minute in a simple model. Assuming 100 buttons update independently gives approximately 1,200 requests/minute; the default provides twice that allowance. This is an assumed model, not a measured traffic figure. Buttons sharing an identifier share their requests, and actual traffic depends on implementation, visible pages, update intervals and usage.

Sending a Like is a request made when a visitor presses the Like button. The default detects more than 240 requests in 60 seconds. This averages four requests/second, a provisional frequency intended to exceed ordinary manual use. It was not derived from measured traffic and does not guarantee that detected requests are attacks.

Detection occurs when requests from the same connection exceed the specified count within the configured time window. Counts are independent per connection and API, using one-second rolling buckets. Time window to monitor (seconds) accepts 10–3,600 seconds; Request count that triggers detection accepts 1–1,000,000 requests. Detection-only history is deduplicated while a previous detection remains in that connection/API window.

These are provisional defaults for general use, and appropriate values vary by site. Visitors sharing an IP may have their requests combined; detected traffic is not necessarily abusive. Review the history even when using automatic blocking with the initial settings, and tune the rules for your site's size and traffic.

Valid OPTIONS is excluded. Invalid input, rejected origins and invalid OPTIONS are counted. Rejected origins retain 403; valid OPTIONS retains 204 even during a block. Allowed origins receive CORS-enabled 429 with `Retry-After` when blocked, distinguished from the existing per-browser Like quota. Only the two public APIs are blocked; Admin and ordinary site pages remain available.

When automatic blocking is enabled, exceeding a detection rule blocks the connection from both public APIs for 48 hours. Blocks expire 48 hours after creation. Admin can confirm a release using a management ID, which resets both API counters. After expiry or manual release, exceeding a rule again creates a new history and a new 48-hour block, without escalation or permanent blocks. Detection OFF stops new detections and removes counters; automatic blocking OFF stops new automatic blocks. Active blocks remain in effect until expiry regardless of either setting, without extending their deadlines. Manual release remains available. After release, new detection and reblocking follow the current settings. Admin displays times in the aggregation timezone, including the abbreviation applicable to each timestamp and daylight saving time. Internal storage and expiry checks remain UTC. Changing the timezone does not change detection history, block deadlines or protection settings.

Only purpose-separated HMAC values derived from the existing `app_secret` and `REMOTE_ADDR` are stored in short-term counters and active block management. Forwarded headers are ignored. **Shared networks and reverse proxies can combine multiple visitors into one connection and cause false blocks.** History contains no IP addresses or IP hashes.

Expired counters, blocks and history are deleted on the next public API check or authenticated Admin access. Valid OPTIONS also runs cleanup without being counted. With no traffic, physical deletion is delayed, but expired blocks cannot extend beyond their deadline. History becomes eligible for deletion 60 days after detection. No cron is required.

For an existing installation, back up the database, `config/` and `log/`, apply `install/upgrade-access-protection.sql` to its database, then update application files while preserving settings, credentials and the secret. The repeatable SQL only adds three tables. Do not restore a deleted `install/` directory or rerun the installer; obtain and run the migration SQL separately. New installations use the updated `install/install.sql`.

Before migration, or if protection encounters a database/locking error, the public APIs continue without this protection and log the problem. Admin reports unavailable schema/read failures. Monitor logs and verify operation after deployment. A short database-scoped lock serializes protection transactions. This application feature cannot stop network-scale attacks before they reach PHP or the database.

## Local verification page

`tests/local-aggregation-admin.php` is a local-only test page with no login. Do not deploy or expose it in production. In XAMPP, open `http://localhost/Simple_Like_Button/tests/local-aggregation-admin.php`. Opening the page runs one aggregation batch of up to 30 pending records.

During a timezone change, the public APIs enter maintenance mode. Aggregates, including pending Likes, are saved to a timestamped folder under `log/`; active aggregate values reset only after the archive is complete. Archives are not automatically deleted. If a change is interrupted, reload the local page and use **Resume timezone change**.
