# CHANGELOG

## v1.0.0-beta2 (prerelease)

- Added required administrator transport/session classes, the installer configuration generator, and the embedding-example copy script to the release allowlist.
- Hardened administrator HTTPS, session lifecycle, CSRF handling, and concurrent-session behavior.
- Improved the installer-removal notice and bilingual widget-test embedding examples with separate copy buttons and identifier guidance.
- Clarified PHP minimum requirements, the repository security policy, the tested PHP 8.5.12 environment, and administrator deployment/recovery settings.
- Documented automatic identifier collisions, unsupported separation outside the document root, and the pending ERAMINAN.jp field test.
- Kept the package for new installations only; it must not overwrite an existing installation.

## v1.0.0-beta1

- Prepared the beta release with Japanese and English installation guides and GPL-3.0-only licensing.
- Added Origin/CORS settings, configurable button symbols and colors, and a display preview.
- Added access anomaly detection, temporary blocking, and administration controls.
- Improved administrator password handling and concurrent installer execution and recovery.
- Fixed aggregation regressions and added an allowlisted release ZIP build script.

## v0.4.1 Installer Guide

- Added a beginner-oriented Welcome screen before the environment check.
- Documented empty-database creation, database users, database-scoped privileges, and the five connection values required by the installer.
- Added field-level database guidance and warnings for provider-added name prefixes.
- Expanded the installer flow to seven visible stages in Japanese and English.

## v0.4.0 Installer

- Added the bilingual browser-based web installer.
- Added environment and database checks, table creation, secret generation, configuration writing, and admin-password setup.
- Locked Admin after installation until the entire `install/` directory is deleted.
- Prevented installer reruns and overwrites of completed installations.

## v0.3.0 Admin

- Completed the password-protected Dashboard, Details, and Settings screens.
- Added automatic and manual aggregation, statistics charts, display controls, and timezone-change handling.

## v0.1.0 Draft

-   Project started
-   Project name decided
-   Code name decided (Pollyanna)
-   Core architecture defined
-   Identifier-based design adopted
