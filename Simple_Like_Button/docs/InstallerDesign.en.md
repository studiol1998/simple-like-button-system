# Web Installer Design

Created 2026-10-03. Last updated 2026-10-05.

## Goal

After uploading the files, an administrator should be able to start Pollyanna by following a browser-based setup flow without manually editing database configuration or secrets.

## Completion criteria

1. Japanese and English are selectable.
2. Check the PHP version, PDO MySQL, JSON, sessions, and required writable directories.
3. Accept and verify database host, port, database name, username, and password.
4. Safely create every table represented by `install/install.sql`.
5. Generate a cryptographically random 64-digit hexadecimal application secret.
6. Select the aggregation timezone.
7. Set an admin password of at least 12 characters and store only its hash.
8. Atomically save `config/config.php` and `config/admin_credentials.json`.
9. Show links to the admin interface and widget example after completion.
10. Detect completed installations and prevent the installer from running again.
11. After a successful installation, save a completion marker and deny admin access while the `install/` directory remains. Treat this as the highest-priority completion requirement.

## Screen flow

The installer doubles as an installation guide. Its first screen explains database terminology and where to perform each task in a hosting control panel.

1. **Welcome**: explain the database as the container for stored data and describe preparation under a hosting panel's “MySQL,” “Database,” or similarly named menu.
2. **Environment check**: list requirements and actionable failures.
3. **Database setup**: explain each connection field, verify the connection only, and never redisplay the password.
4. **Basic settings**: choose timezone and enter/confirm the admin password, with a visibility toggle.
5. **Confirmation**: summarize operations without displaying database passwords or secrets.
6. **Installation**: create tables, then save configuration and authentication data.
7. **Completion**: require deletion of the `install/` directory. Keep Admin unavailable until deletion, explain that deleting the directory unlocks it, and link to the README and embedding example.

## Preparation performed by the user

1. Create one empty database dedicated to Pollyanna.
2. Create a database user or select an existing one.
3. Assign that user to the dedicated database and grant table creation, read, insert, update, and delete permissions. If individual selection is unclear, granting all privileges on the dedicated Pollyanna database is acceptable.
4. Copy the database host, port, database name, database username, and database password from the hosting control panel.

The installer does not create the database or its user. Some hosts automatically prefix database and user names, so values must be copied exactly as displayed. The installer creates tables and initial data, generates the secret, and writes configuration files in the empty database.

## Safety design

- Use a session and CSRF token throughout setup.
- Never concatenate submitted connection values into SQL. Execute only bundled fixed schema SQL.
- Never expose database passwords, application secrets, or admin passwords in errors or logs.
- Write configuration through temporary files and atomic replacement.
- Treat a valid `config/config.php` plus admin credentials as an installed system.
- Repair and overwrite flows are outside the first installer release.
- Atomically save `config/install_complete.json` after successful installation. While both the completion marker and `install/` directory exist, stop the admin interface before login processing and show only deletion instructions.
- Unlock admin access only after the entire `install/` directory has been removed from the server. Removing only `index.php` or using a UI action does not unlock it.
- Check web access protection for `log/` and `config/`; explain non-Apache requirements on completion.
- If file saving fails after table creation, keep the tables and allow a safe retry using `CREATE TABLE IF NOT EXISTS`.

## Defaults

| Setting | Default |
| --- | --- |
| Aggregation timezone | `Asia/Tokyo` |
| Service state | On |
| Auto-aggregation threshold | 1,000 |
| Aggregation batch limit | 500 |
| Public language | Japanese |
| Per-item/per-browser limit | 3 per 24 hours |
| Public count | Hidden |
| Refresh interval | 5 seconds when counts are visible |

## Implemented components

1. `install/index.php` and Japanese/English translations.
2. Beginner-oriented preparation instructions, environment checks, and installed-state detection.
3. Database verification and fixed schema execution.
4. Configuration, secret, and admin-password storage.
5. Completion, rerun prevention, and Admin locking until `install/` is deleted.
6. End-to-end verification against a dedicated empty XAMPP database.

## Checks before production deployment

- Whether production can place `config/` outside the public document root. The first version keeps the current layout and requires access denial.
- The database user needs `CREATE`, `SELECT`, `INSERT`, `UPDATE`, and `DELETE`. If a hosting panel does not make individual selection clear, instruct users to grant all privileges only on the dedicated Pollyanna database.
- Require deletion of the entire `install/` directory after installation and keep admin access locked until deletion. Use configuration, credentials, and the completion marker to prevent reruns.

## XAMPP verification

On 2026-10-04, the full flow was verified using an isolated `Pollyanna_Installer_Test` copy, a dedicated empty database, and a localhost-only database user. Environment checks, database verification, basic settings, review, table creation, configuration saving, and the completion screen all succeeded. While `install/` remained, Admin showed only the deletion requirement and denied login. Renaming `install/` unlocked Admin; the password created during installation successfully opened the empty-database Dashboard. A dedicated page then verified Like submission, restored heart state, public count display, aggregation on Admin access, and Dashboard charts.
