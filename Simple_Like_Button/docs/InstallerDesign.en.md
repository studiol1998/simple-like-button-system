# Web Installer Design

Created 2026-10-03. This is the starting point for the next work session.

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

## Screen flow

1. **Environment check**: list requirements and actionable failures.
2. **Database setup**: enter credentials and verify connection only; never redisplay the password.
3. **Basic settings**: choose timezone and enter/confirm the admin password, with a visibility toggle.
4. **Confirmation**: summarize operations without displaying database passwords or secrets.
5. **Installation**: create tables, then save configuration and authentication data.
6. **Completion**: link to Admin, README, and the embedding example.

## Safety design

- Use a session and CSRF token throughout setup.
- Never concatenate submitted connection values into SQL. Execute only bundled fixed schema SQL.
- Never expose database passwords, application secrets, or admin passwords in errors or logs.
- Write configuration through temporary files and atomic replacement.
- Treat a valid `config/config.php` plus admin credentials as an installed system.
- Repair and overwrite flows are outside the first installer release.
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

## Next implementation order

1. Create `install/index.php` and installer translations.
2. Implement environment checks and installed-state detection.
3. Implement database verification and fixed schema execution.
4. Save configuration, secret, and admin password.
5. Implement completion and rerun prevention.
6. Verify the complete flow against a fresh XAMPP database.
7. Update README and the implementation plan with the actual procedure and results.

## Questions for the next session

- Whether production can place `config/` outside the public document root. The first version keeps the current layout and requires access denial.
- Whether to include a manual SQL path for hosts whose database account lacks `CREATE TABLE` permission.
- Whether completion should require deleting `install/`, or rely on installed-state locking. The default design uses configuration and credential existence as the lock.
