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
- Public Like count visibility, color, and refresh interval (5 seconds by default; 0 disables refresh)
- A configurable automatic aggregation threshold (default: 1,000 events)
- Daily, monthly, yearly, and time-of-day aggregates
- CSV archival and aggregate reset when changing the timezone

## Requirements

- PHP 8.0 or later with PDO MySQL enabled.
- MySQL or MariaDB. Tables in `install/install.sql` use InnoDB.
- On Apache, `log/.htaccess` denies direct access to archives. On other web servers, add an equivalent access-denial rule in the server configuration.
- Keep the widget JavaScript, CSS, and API on the same site (same origin). Cross-origin embedding and CORS are not supported.

## Installation

1. Copy `config/config.example.php` to `config/config.php` and set the database DSN, username, and password.
2. Generate a random 32-byte secret with PHP CLI using `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Put the resulting 64-character hexadecimal value in `app_secret`. Use a different secret for each installation.
3. Run `install/install.sql` against the target database.
4. Allow the PHP process to write settings JSON files in `config/` and create/write `log/`. Protect `config/config.php` from direct web access.
5. Add this snippet to the page where the widget should appear. Replace `/Simple_Like_Button/` with the deployed path.

```html
<link rel="stylesheet" href="/Simple_Like_Button/assets/like.css?v=1">
<div data-pollyanna-like data-identifier="article-001" data-url="https://example.com/articles/001"></div>
<script defer src="/Simple_Like_Button/assets/like.js"></script>
```

Use a stable, unique value per work in `data-identifier`. Values cannot be empty or contain line breaks, and are limited to 50 characters. Prefer an internal ID over a title or URL. Pages for the same work should use the same identifier to share its count and rate limit. When updating the CSS, change `v=1` to refresh browser caches.

Set `data-url` explicitly to the canonical URL of the page containing the work. It is stored as the installation URL shown in the admin interface. One identifier can have multiple URLs. The current browsing URL is never collected automatically.

The browser identifier is stored in LocalStorage; the database stores only its HMAC, generated with the installation secret. Raw IP addresses are not stored. Clearing LocalStorage makes that browser appear as a new anonymous visitor.

## Editing language and messages

Edit widget text in `lang/widget.ja.json` and `lang/widget.en.json`. To change a success message, keep its key (such as `like01`) and edit the value. To add an option, add the same numbered key to both JSON files. JSON does not support comments.

## Local verification page

`tests/local-aggregation-admin.php` is a local-only test page with no login. Do not deploy or expose it in production. In XAMPP, open `http://localhost/Simple_Like_Button/tests/local-aggregation-admin.php`. Opening the page runs one aggregation batch of up to 30 pending records.

During a timezone change, the public APIs enter maintenance mode. Aggregates, including pending Likes, are saved to a timestamped folder under `log/`; active aggregate values reset only after the archive is complete. Archives are not automatically deleted. If a change is interrupted, reload the local page and use **Resume timezone change**.
