# Simple Like-button System

Pollyanna is a lightweight, standalone Like-button system. This directory is the implementation workspace.

## Working principles

- Follow the current specifications in the parent `Pollyanna_Specification_v1.0` directory.
- Limit changes to the requested scope and respect the existing design.
- `legacy/like_button_beta_package/` is for historical reference. Do not deploy it as-is.
- If code, specifications, or older materials disagree, report the discrepancy instead of deciding on new behavior without direction.

## Previous beta

The previous PHP beta is preserved under `legacy/`. See [docs/LegacyMigration.en.md](docs/LegacyMigration.en.md) for how it maps to the current specification and for known limitations.

## Beta 0.0.2 (complete)

In addition to the 0.0.1 Like submission and database storage, 0.0.2 checks the server for a saved Like from the same browser and identifier, restoring the filled ♥ after a reload. No database schema change is required. The Like count was added in 0.0.3. Rate limiting will be addressed by 0.1.0.

## Beta 0.0.3 (complete)

0.0.3 adds an optional Like count. Until the admin screen is available, toggle it with `show_like_count` in `config/config.php`. It defaults to `false` (hidden). When enabled, the saved Like-event count for each identifier appears on page load and after a Like is submitted. The count uses a subtle gray by default; setting its color in the admin screen is planned for 0.1.0.

### Installation

1. Copy `config/config.example.php` to `config/config.php` and set the database DSN, username, and password.
2. Generate a random 32-byte secret and set its 64-character hexadecimal form as `app_secret`. With PHP CLI: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
3. Run `install/install.sql` against the target database.
4. Add the following to the HTML, changing `/Simple_Like_Button/` to the deployed path.

```html
<link rel="stylesheet" href="/Simple_Like_Button/assets/like.css">
<div data-pollyanna-like data-identifier="article-001"></div>
<script defer src="/Simple_Like_Button/assets/like.js"></script>
```

Set a unique value per page in `data-identifier`. The browser token is stored in LocalStorage; only its HMAC, generated with the installation secret, is stored in the database. Raw IP addresses are not stored.

## Important: Changing the Timezone

Change the timezone when the country or region of operation changes. The system finalizes accumulated Like totals, period aggregates, and time-of-day aggregates, including pending Likes, saves them as CSV files in a timestamped folder under `log/`, resets the active aggregate data, and starts collecting under the new timezone. Public Like counts also restart at zero.

Before applying a timezone change, the admin screen must show a confirmation dialog explaining that accumulated data will be reset. After the user selects Yes, show progress stages: “Aggregating current data” and “Saving aggregated data.” When saving is complete, identify `log/` as the archive location and gently advise that log files can be deleted if they put pressure on server storage. Archives are not automatically deleted, and the admin screen does not provide download or deletion controls. Administrators handle any optional backup and server-side deletion manually. Historical aggregates are not recalculated using the new timezone.
