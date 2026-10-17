# Pollyanna — Simple Like-button System

Version: `v1.0.0-beta1`

Pollyanna is a lightweight, standalone Like-button system. The Japanese creator name "にくきぅSYSTEM" and the English name "Nikukiu SYSTEM" refer to the same creator.

Copyright (C) 2026 Nikukiu SYSTEM

Licensed under **GPL-3.0-only** (GNU General Public License version 3 only). See the unmodified full license in `LICENSE`. No additional proprietary conditions restrict the use, modification or redistribution permitted by the GPL.

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
- Preserve the relative layout of widget JavaScript and the API. For embedding from another origin, configure the primary origin, base domain and additional allowed origins in Admin and operation settings. Origins consist of scheme, host and port, without a path.

## Installation

1. In the hosting control panel, create one empty database dedicated to Pollyanna.
2. Create or select a database user and assign it to that database. Grant `CREATE`, `SELECT`, `INSERT`, `UPDATE`, and `DELETE`. If individual selection is unclear, grant all privileges only on the dedicated Pollyanna database.
3. Copy the database host, port, database name, database username, and database password exactly as displayed. Providers may automatically add characters before database and user names.
4. Extract `Pollyanna-v1.0.0-beta1.zip` and upload its standard root `pollyanna/`. The installation name and location may be changed. Allow the PHP process to write to both `config/` and `log/`. On non-Apache servers, deny web access to `config/` and `log/`.
5. Open `/your-install-path/install/` in a browser and proceed through Welcome, Environment check, Database setup, Basic settings, and Confirmation.
6. Set the aggregation timezone and an admin password of at least 12 Unicode code points and at most 72 UTF-8 bytes, then run the installation. Database and admin passwords are not redisplayed on the review screen.
7. When the completion screen appears, delete the entire `install/` directory from the server. Admin remains locked until it is deleted.
8. Open `/your-install-path/admin/` and sign in with the admin password you set.
9. Add this snippet to the page where the widget should appear. Replace `/pollyanna/` with the deployed path.

The installer creates tables, initial data, the secret, configuration, and admin credentials inside the prepared empty database. It does not create the database or database user.

For a manual installation, create `config/config.php` from `config/config.example.php`, set a unique 64-character hexadecimal `app_secret`, and execute `install/install.sql`. Manual setup must also provide admin credentials and completion state, so the web installer is recommended.

```html
<link rel="stylesheet" href="/pollyanna/assets/like.css?v=1">
<div data-pollyanna-like data-identifier="article-001" data-url="https://example.com/articles/001"></div>
<script defer src="/pollyanna/assets/like.js"></script>
```

`data-identifier` identifies the target being counted. Reusing the same value for the same target shares its count, liked state, and rate limit. Manual values are limited to 100 Unicode code points and cannot contain whitespace or ASCII symbols except `-` and `_`. Omitting the attribute generates an identifier from the current path and DOM position; an empty attribute is an error. When updating the CSS, change `v=1` to refresh browser caches.

The installation URL is selected from `data-url`, canonical, `og:url`, and the current page URL in that order. Its query and fragment are removed, and it is saved only after a Like succeeds. One identifier can have multiple URLs.

The browser identifier is stored in LocalStorage; the database stores only its HMAC, generated with the installation secret. Raw IP addresses are not stored. Clearing LocalStorage makes that browser appear as a new anonymous visitor.

## Admin display and password protection

Before login, URL language/theme choices affect only that session's temporary display. They survive failed logins and reloads without saving shared preferences. Successful login clears them and restores saved settings. Authenticated language/theme changes are saved using CSRF-protected POST requests.

New or explicitly changed passwords require at least 12 Unicode code points, at most 72 UTF-8 bytes, valid UTF-8, and no NUL characters. Character and byte counts differ: 24 ordinary Japanese characters occupy 72 bytes. Leading/trailing spaces are preserved; there is no Unicode normalization or truncation. Hashing explicitly uses bcrypt with PHP's default cost. No additional mbstring extension is required.

Existing hashes are not automatically changed during upgrades or login. Legacy login retains its 1024-byte input limit. Old bcrypt passwords longer than 72 bytes cannot distinguish characters beyond the first 72 bytes. Their original length and tail cannot be recovered from the hash; explicitly change the password to adopt the new rules.

Use Change administrator password in Admin and operation settings. Enter the current password, new password, and confirmation. Authentication, CSRF, current-password verification, the new rules, and exact confirmation are required. Attempts are limited to five per minute per session. Failure preserves the old hash and never redisplays plaintext passwords. Success ends the current session; other signed-in sessions expire on their next admin request. Sign in again with the new password. Back navigation and POST replay cannot change it again.

Each authenticated request checks a credential fingerprint. Sessions created before this update lack it and require one new login. The credentials JSON format is unchanged; the application secret and database connection are preserved. There is no unauthenticated password reset. Keep web access to config denied.

## Concurrent installation and recovery

New installation and completion retries use a nonblocking exclusive lock on config/.installer.lock. State is rechecked after acquiring it. New installation exclusively creates config/.installer-in-progress. Failure to acquire the lock stops before application writes. Existing config, credentials, and secrets are not deleted or replaced, and database tables are not reset.

Only normal completion removes the progress marker. The lock file remains for reuse. Do not remove a progress marker merely because time has passed. A remaining progress marker means partial installation even if the completion marker exists.

After confirming that the earlier installer process stopped, Retry completion is available only when the saved config, credentials, and required database tables can be verified. Recovery only creates a missing completion marker and removes the progress marker; it never regenerates secrets or credentials. Missing config/credentials or an invalid existing completion marker prevents automatic recovery. Preserve artifacts and consult the backup and server administrator.

The filesystem must support file locking and exclusive file creation. Test concurrency on shared/network filesystems and different PHP execution modes. Do not bypass locking in an unverified environment. No cron or Redis is required.

## Editing language and messages

Edit widget text in `lang/widget.ja.json` and `lang/widget.en.json`. To change a success message, keep its key (such as `like01`) and edit the value. To add an option, add the same numbered key to both JSON files. JSON does not support comments.

## Access concentration detection (v1.0.0-beta1)

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

This ZIP is for new installations only. It contains `install/install.sql` and excludes migration SQL for existing installations. Do not use it to overwrite an existing installation or rerun its installer.

Before migration, or if protection encounters a database/locking error, the public APIs continue without this protection and log the problem. Admin reports unavailable schema/read failures. Monitor logs and verify operation after deployment. A short database-scoped lock serializes protection transactions. This application feature cannot stop network-scale attacks before they reach PHP or the database.

## Installation location and URLs

The default example is `/pollyanna/`. You may rename it, use nested subdirectories or place its contents directly in the document root. Preserve the relative layout of `admin/`, `api/`, `assets/`, `src/` and other directories, and avoid conflicts with existing files. For a document-root installation, change the embedding URLs to `/assets/like.css` and `/assets/like.js`. Relative URLs resolve against the embedding HTML page, so check each page. Loading assets from another origin requires complete HTTPS URLs and suitable origin permissions. CORS is not user authentication.

A `cgi-bin/` directory may treat all files as CGI programs and is not a recommended default. Use it only after verifying PHP execution and static CSS/JavaScript delivery. Review origin settings when changing host, scheme or port. Automatically generated identifiers depend on page path and DOM order; moving pages may change the counting target.

## Authenticated widget test

After deleting `install/`, sign in to Admin and open Widget test at `admin/?view=widget-test`. Two buttons allow checking shared-identifier synchronization, count updates and normal click limits. They use the real API and database. Test clicks are included in normal statistics and rankings, and access anomaly protection applies. Administrator authentication for the page does not authenticate users of the public API.

The fixed value is `Pollyanna-test_01（ポリアンナテスト_01）`. If Japanese is inconvenient, edit the single `$testIdentifier` definition in `admin/widget-test-view.php`. Changing it creates a different counting target and leaves previous data intact. The limit is 100 Unicode code points. Whitespace, control characters and ASCII symbols other than `-` and `_` are not allowed. No Unicode normalization is applied.

Examples include Chinese (`测试01`), Korean (`테스트01`), Arabic (`اختبار01`), Hebrew (`בדיקה01`), Russian (`Тест01`) and Greek (`Δοκιμή01`). Accepting these strings does not guarantee correct RTL presentation. Do not insert directional control characters into identifiers. Use `bdi` or `dir="auto"` for display isolation and verify mixed-script presentation and copying in a browser.

## Post-installation security and maintenance

Delete `install/` and verify that HTTP access to `config/` and `log/` is denied. Even on Apache, `.htaccess` must be enabled appropriately. On Linux, verify ownership, write permissions and SELinux; do not make the entire installation mode 777. Use HTTPS for Admin and securely back up the database, configuration, secret, credentials and archives. PHP also requires a working session storage location. No cron, Redis, external API or additional library is required. AlmaLinux 10.1 is a planned installation verification environment, not a verified platform merely because it is named here.

A timezone change saves aggregates, including pending Likes, as CSV under `log/` and resets active aggregates only after successful archival. Archives are not automatically deleted. Resume an interrupted change from Admin and operation settings. This reset does not change access anomaly history or block deadlines.
