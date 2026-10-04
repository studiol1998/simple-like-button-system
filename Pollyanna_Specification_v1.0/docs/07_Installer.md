# Installer

## Preconditions

Before opening the installer, the administrator prepares one empty database dedicated to Pollyanna and a database user assigned to it. The user needs `CREATE`, `SELECT`, `INSERT`, `UPDATE`, and `DELETE`. Granting all privileges is acceptable only when scoped to the dedicated Pollyanna database.

The administrator records the database host, port, database name, username, and password exactly as displayed by the hosting control panel. The installer does not create the database or database user.

## Screen flow

1. Welcome and database preparation guide
2. Environment check
3. Database connection settings and verification
4. Aggregation timezone and admin password
5. Installation review
6. Table and configuration installation
7. Completion and mandatory installer deletion

The Welcome screen explains database terminology, points users to the hosting control panel's MySQL or database menu, and warns that providers may add a prefix to database and user names.

## Installer operations

- Verify PHP 8.1 or later, PDO MySQL, JSON, sessions, writable `config/`, schema availability, and Apache access protection.
- Verify the submitted MySQL or MariaDB connection without redisplaying the password.
- Create tables and initial data from bundled fixed SQL.
- Generate a 64 hexadecimal character secret with `random_bytes(32)`.
- Hash the admin password and store only its hash.
- Atomically write configuration, admin credentials, and the installation completion marker.
- Detect existing installations and prevent overwrite or rerun.

## Completion lock

After a successful web installation, Admin remains locked while both `config/install_complete.json` and the `install/` directory exist. The completion screen instructs the administrator to delete the entire `install/` directory. Deleting only its entry point does not satisfy the unlock condition.

Normal operation starts after the directory is removed. A completed installation cannot be rerun by restoring the installer directory.
