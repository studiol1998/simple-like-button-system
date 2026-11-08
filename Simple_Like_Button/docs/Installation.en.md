# Pollyanna Installation and Operations

Creator: にくきぅSYSTEMs (Nikukiu-SYSTEMs)

Status: Awaiting review of the documentation restructuring. Applies to v1.0.0-beta2. See the [current specification](Specification.en.md) for behavioral rules and the [development plan](DevelopmentPlan.en.md) for implementation status, tests, and outstanding issues. This guide provides detailed preparation, operation, and recovery procedures. No installation or database changes were performed when preparing this document.

This document applies to v1.0.0-beta2 and is awaiting review. No document-specific version number is added. After approval, the relationship between the documentation and code will be recorded through Git commits and release tags that have actually been created and verified. See Section 1.1 of [DevelopmentPlan](DevelopmentPlan.en.md) for the shared versioning policy.

## 1. Runtime Requirements, Installation Eligibility, and Verification Record

| PHP | Official support | Installation eligibility and display |
| --- | --- | --- |
| 8.3 or later | Official runtime requirement | Permitted if the other mandatory conditions are met |
| 8.1–8.2 | Outside support | The current installer permits these versions. An unsupported-version warning is planned but is not necessarily displayed now |
| 8.0 or earlier | Outside support | Installation is not permitted |

The current installer's environment check accepts 8.1.0 or later. Passing on a version below 8.3 does not confer official support. Rejection of 8.0 and earlier does not guarantee that an explanatory screen can always be rendered on old PHP versions. The PHP 8.1–8.2 warning is recorded as not implemented in DevelopmentPlan.

The recorded automated verification environment is PHP 8.5.12 (CLI and the built-in HTTP server). This does not mean correct operation has been verified on all environments such as 8.1, 8.2, and 8.3. Use PHP within its security support period with necessary fixes applied, and check Web PHP and CLI PHP separately.

The diagnostic RuntimePolicy records branch-specific minimum versions of 8.3.36/8.4.27/8.5.12. These are diagnostic criteria for particular fixes, not guarantees of the latest version, protection against all vulnerabilities, or compatibility. Check individual vendor backports against official evidence. RuntimePolicy is not integrated into normal operation or the installer and is not included in the ZIP. Do not treat it as the same determination as official support or installation eligibility.

PDO MySQL, JSON, sessions, and InnoDB on MySQL or MariaDB are required. Check write access to configuration and archive destinations, file locking and exclusive creation, and the requirements for administrative sessions using PHP's standard files handler. Installation with components separated outside the web root is unverified and unsupported and is not part of the current procedure.

## 2. Preparing the Database, Files, and Web Server

1. Create a dedicated empty database for Pollyanna. Do not perform a new installation into an existing application's database.
2. Create or select a database user and grant CREATE, SELECT, INSERT, UPDATE, and DELETE on that database. If individual privileges are difficult to specify in the hosting control panel, grant “all privileges” only on the dedicated database, not on the whole server.
3. Record the 5 connection values: database host, port, database name, username, and password. Where prefixes are automatically added, use the displayed values exactly. Do not store credentials in public documents, logs, or Git.
4. Extract the distribution ZIP and deploy it using the standard pollyanna/ structure. Preserve the relative structure of admin/, api/, assets/, and related directories even if the installation name or location changes.
5. Verify that the PHP execution user can write to config/ and log/. On Apache, verify that both .htaccess files actually deny direct access. Other web servers require equivalent server configuration.
6. Check production administrative HTTPS, proxies, and session storage conditions against Chapters 4–5. Shared/network file systems and different PHP execution modes require verification of lock behavior. Do not disable protection to install.

The installer does not create the database itself or database users. Do not deploy preserved old-beta code directly into the web root.

On Linux, verify ownership, write permissions for the PHP execution user, and SELinux; do not make the whole installation mode 777. Verify actual denial of access to config/ and log/, including whether Apache honors .htaccess. No cron, Redis, external API, or additional library is required.

## 3. New Installation

The interface has the following 7 stages.

| Stage | What to check |
| --- | --- |
| Welcome | Prepare the database, user, privileges, and connection values |
| Environment check | PHP, PDO MySQL, JSON, sessions, required directories, access protection, and related conditions |
| Database settings | Enter connection values and check connectivity. The password is not redisplayed |
| Basic settings | Time zone, administrator password and confirmation, primary site Origin/base domain, and administrative transport settings |
| Review | Check the configuration, tables, and administrator endpoint to be created. Database passwords and secrets are not displayed |
| Installation | Fixed SQL, secret, configuration/credentials, and completion-state storage |
| Completion | Remove the entire install/ directory and log in through the verified administrator URL |

Administrator passwords follow the Specification rules. The installer generates the secret. Normally use the Web installer; do not assume that the configuration template and SQL alone also establish administrative authentication and completion state.

Defaults are aggregation time zone Asia/Tokyo, system state (system_state) enabled, aggregation threshold 1,000 events, batch size 500, Japanese public display, 3 Likes per 24 hours, hidden counts, and a 5-second refresh when counts are displayed. See the current InstallerConfig and configuration template for generated values such as the theme.

For manual installation, create config/config.php from config/config.example.php, set an installation-specific 64-character hexadecimal app_secret, and execute install/install.sql against the target database. Administrative credentials and completion state must also be established. The template and SQL alone do not complete installation; normally use the Web installer.

### 3.1 Explicitly Confirming the Administrative Environment

Always select the administrative environment in Basic settings and confirm the administrator Origin. The initial selection is blank; `localhost` or HTTP access alone does not switch the installation to development mode.

- Production: The administrator Origin must use HTTPS. Specify only scheme, host, and port, with no path or credentials. The public `site_origin` is not automatically rewritten.
- Local development: In addition to explicit selection, confirmation that this is direct local development is required. Existing policy checks that both the actual connection source and Host are loopback and that no forwarding headers are present. Do not use this exception through a LAN, public connection, tunnel, or proxy.
- The administrator path is calculated from the installer's location; modified POST values are rejected. Uppercase characters in `/Pollyanna/` are preserved. Environments where rewriting changes the actual path require separate checks.
- The review screen also displays the environment, Origin, and path, and they are validated again immediately before execution. Old wizard sessions without this selection must return to Basic settings and reconfirm it.

For `http://localhost/Pollyanna/`, select local development and verify the administrator Origin as `http://localhost` and the automatic path as `/Pollyanna/admin/`. The generated configuration has this form:

```php
'admin_security' => [
    'environment' => 'development',
    'origin' => 'http://localhost',
    'admin_path' => '/Pollyanna/admin/',
    'trusted_proxies' => [],
],
```

After completion, delete `install/` from the application location or move it outside the web root before connecting to the verified administrator Origin plus path. While `install/` remains, the existing installation-state checks display only Japanese/English removal instructions. Administrative sessions, authentication, and settings saves do not start. Instructions take priority even when transport settings are invalid, but this is not an exception allowing administrative operations.

Generated `trusted_proxies` is empty. Production environments using a TLS-terminating proxy require explicit settings based on provider information before administrative access. Proxy headers are not automatically trusted. This addition does not include installer-wide HTTPS enforcement or an installation token.

An existing HTTP installation's `config.php` is not automatically updated. Do not reinstall into its database; add only the administrative settings using the migration procedure below.

Configuration generation is centralized in `src/InstallerConfig.php`, shared by the installer and tests. A recursive comparison of entries and defaults against `config.example.php` is also performed. Actual Origins, paths, and secrets depend on the installation, so tests explicitly account for differences from omitted values and placeholders in the template. The template's abnormal access settings also match the defaults for new Web installations. Existing configuration files and saved settings are not changed.

### 3.2 Primary Site Settings and Post-Installation Checks

Candidate primary Origins and base domains are suggested from the accessed URL, but must be checked and corrected rather than trusted automatically. Subdomain permission is ON by default and disabled for localhost and IP addresses. These settings are separate from the administrator Origin.

After removing the entire install/ directory, log in with the configured password and check settings saves/reloads, the operation test, a button with a manual identifier, count retrieval, Likes, and the limit. This does not establish that all features have already been verified on a real site.

## 4. HTTPS, Administrator Endpoints, and Proxies

Chapter 8 also describes administrator-specific Origin/path configuration and behavior when omitted. There is no compatibility fallback permitting production operation over HTTP. Generating administrative settings in the installer is separate from enforcing HTTPS for all installer traffic. Prepare an HTTPS path for entering production credentials as well.

### 4.1 TLS-Terminating Proxies

List connection-source IPs in `trusted_proxies` only when a TLS-terminating proxy is present. Only exact IP matches are allowed; there is no CIDR support, allow-all setting, or automatic registration from headers. The proxy must preserve Host correctly and overwrite `X-Forwarded-Proto` rather than inherit user input, sending a single `https` or `http` value.

Missing headers, whitespace, comma-separated values, or invalid values on trusted connections stop processing with 400. Forwarding headers from unregistered connections are not evidence of HTTPS. `Forwarded` and forwarded Host, port, or client-IP headers are not used for this determination either. Proxy support for the IP used in login attempt limits is a follow-up issue in the source material; see DevelopmentPlan's outstanding issues for its verification status. Even a loopback proxy cannot be used for the existing local-only initial password setup.

Production HTTP GET requests receive a 302 redirect to the fixed HTTPS administrator URL without the original query. HTTP POST and similar requests are rejected with 403 without starting a session, authenticating, or saving settings. Invalid Host yields 400; undetermined configuration yields 503. Accepting a POST and then redirecting it is not treated as safe.

### 4.2 Explicit Local Development Exception

```php
'admin_security' => [
    'environment' => 'development',
    'origin' => 'http://localhost:8080',
    'admin_path' => '/pollyanna/admin/',
    'trusted_proxies' => [],
],
```

The HTTP exception requires all of the following: explicit development configuration, a loopback Origin/Host, and a loopback connection. Forwarding headers or proxy settings disqualify the connection from this exception. Use XAMPP and similar tools through direct local connections; do not expose them over a LAN, tunnel, or undeclared proxy. Only development HTTP omits Secure; other cookie protections remain.

## 5. Administrative Sessions, Concurrency, and Storage Failures

### 5.1 Storage Methods, Settings, and Permissions to Check First

The guarantee covers the standard `files` handler on a local file system with working exclusive locks. Flat `session.save_path` and `0;MODE;path` are supported. Hierarchical session storage, Redis, Memcached, custom user handlers, and similar alternatives do not automatically receive the same guarantees; administrative authentication stops with 503. Other methods require an adapter whose locking, failure-reporting, and strict mode contracts have been checked. Test dependency injection is a mechanism available only to trusted PHP code, not an HTTP or configuration switch for disabling protection.

- Required PHP settings are `session.use_cookies=1`, `session.use_only_cookies=1`, `session.use_trans_sid=0`, and `session.auto_start=0`. Invalid settings stop processing on the safe side. `session.use_strict_mode=1` is enabled before starting the session.

- Cookies are Host-only, limited to the administrator path, HttpOnly, SameSite=Strict, and Secure in production.

The implementation checks that the native handler reports success, but does not prove durability after power loss, fsync, protection against silent storage loss, or exclusive locking on systems such as NFS. Garbage collection or storage-retention settings may remove sessions earlier than the application's own expiry. Real-environment permissions, capacity, handlers, and file systems must be checked before deployment.

Before deployment, check storage permissions/capacity, the handler, and file-system locking. Do not bypass protection by switching to an unsupported method. See Sections 5.4–5.5 for the guarantees of internal processing.

### 5.2 Operations When a 503 Occurs

Transport settings, storage methods, and storage failures at session start or finish can produce the same 503. Do not infer a unique cause solely from the response body; use the table in Section 9.4.

- Output and successful redirects are buffered and replaced with 503 if the final storage check fails. Configuration-file updates and session storage are not one transaction. A settings change may already have completed when storage fails, so check state after recovery.

Do not automatically resend after storage failure. Check and restore the server-side storage method, destination permissions, and capacity, then log in again and check which settings took effect. Preserve credentials and secrets; do not respond by bulk-deleting sessions or bypassing authentication. See Sections 9.1 and 9.3 for recovery. Section 5.5 explains why settings/database updates and sessions are not a single transaction.

### 5.3 Session Identification, Authentication State, and Expiry

- The name combines `pollyanna_admin_` and the installation ID, separately from the installer's session name.
- Stored state is also associated with the installation ID. Passing another installation's or the installer's SID in the administrative cookie switches to a new administrative session without changing the existing record.
- To specify the ID explicitly, set `instance_id` to a unique 32-character lowercase hexadecimal value. When cloning the same secret into another installation, also ensure that the IDs do not collide.
- Requests specifying the dedicated session name in the URL or POST are rejected.
- Session ID regeneration and CSRF renewal at authentication are retained. Authentication time and last administrative access are stored. Sessions expire after 30 minutes of inactivity or an absolute 8 hours. Public API polling does not extend them.
- Clock rollback, incomplete authentication state, and credential changes also invalidate sessions.
- Logout destroys the session and expires the cookie. Changing a password clears current authentication; other sessions are invalidated at their next access when the credential stamp is checked.

### 5.4 Native Locks and Storage Result Checks

- PHP's standard `files` storage is used, with the native handler wrapped within the request. Persistent PHP configuration and storage destinations are not changed. Full-state rereading and exact-equality checks have been removed. The native lock acquired during reading remains held through authorization, processing, and the final save.

The old processing compared the entire reread session and failed if another request updated it after `session_write_close()` released the lock. The current processing observes read at startup and write/close at completion, detecting PHP warnings, exceptions, and failure return values from the handler. The return value of `session_write_close()` alone is not the success criterion. Delegation to the parent `SessionHandler` allows storage while retaining the lock acquired by the standard file handler.

During logout or ID regeneration, contents are cleared under the lock before native deletion, so a waiting request that already opened the old file cannot read old authentication. Invalidated authentication does not revive without a new login, and CSRF validation remains in place.

### 5.5 Concurrency with the Same or Different SIDs and Guarantee Boundaries

Requests with the same SID wait serially. Long aggregation operations can cause delays, but locks are not reacquired solely to verify storage. Database-backed sessions have not been introduced.

For password changes across different SIDs, the credential comparison is the authorization boundary. An old session checked after the change is rejected, but work authorized before the change is not cancelled mid-operation. Even if that work completes, the next request checks invalidation against the new credentials.

State for the same SID is serialized; this change does not make all concurrent configuration-file updates from different SIDs atomic. Session storage and database/configuration updates are not a single transaction. Settings may already have changed even when storage failure returns 503. Do not automatically resend.

## 6. Button Embedding, Origin Settings, and Messages

The following is a standard embedding example. Replace the installation path, URL, and identifier with the actual values.

```html
<link rel="stylesheet" href="/pollyanna/assets/like.css?v=1">
<div data-pollyanna-like data-identifier="article-001" data-url="https://example.com/articles/001"></div>
<script defer src="/pollyanna/assets/like.js"></script>
```

When CSS changes, change the URL's v value to refresh caches. Use manual identifiers to keep aggregation subjects fixed. Automatic identifiers can, for example, both become news_article_001 for the first button on /a/news/article.html and /b/news/article.html. An empty attribute is different from an omitted attribute. See Specification for shared-button and URL-registration rules.

For use on an external Origin, add permission in administration and operations settings. Specify only scheme, host, and port, without a path. If canonical or a similar URL has a different Origin from the displayed page, the URL-registration Origin must also be allowed. Removing permission deletes only the permission setting, not existing Likes or other data.

Installation on file:, data:, sandbox-generated null Origin, or custom schemes is outside scope. For WebViews, check JavaScript, Fetch, and DOM Storage/Website Data Store requirements. State may be lost through private mode, application exit, data deletion, OS cleanup, and similar events. Each social-media application's behavior requires real-device checks.

Edit public messages in success_messages in lang/widget.ja.json and lang/widget.en.json. Preserve existing keys such as like01 and change their values. For additions, use the next sequential key, such as like07, consistently in both languages. If a selected key is deleted or changed, select it again in the Admin dashboard. JSON does not allow comments. The administrative language is independent of the public language. Additional administrative languages use lang/admin.<言語コード>.json, where the placeholder represents the language code; check consistency with the existing Japanese and English keys.

### 6.1 Installation Location and URLs

The standard example is /pollyanna/. Rename it, use nested subdirectories, or place its contents directly in the document root while retaining the relative admin/, api/, assets/, src/, and related layout and avoiding existing-file conflicts. For a document-root installation, use /assets/like.css and /assets/like.js in the embed. Relative URLs resolve against the embedding HTML page, so check each page. Assets loaded from another Origin require complete HTTPS URLs and suitable Origin permissions. CORS is not user authentication.

cgi-bin/ may treat every file as CGI and is not recommended as the default location. Use it only after verifying both PHP execution and static CSS/JavaScript delivery. Review Origin settings when host, scheme, or port changes. Automatic identifiers depend on path and DOM order, so moving a page may change its aggregation subject.

### 6.2 Authenticated Widget Test

Remove the entire install/ directory, sign in, and open Widget test at admin/?view=widget-test. Two buttons check shared-identifier state synchronization, count updates, and normal Like limits. They use the real API and database; test clicks enter normal statistics and rankings, and abnormal access detection applies. Administrative authentication does not authenticate public API users.

The fixed identifier is Pollyanna-test_01（ポリアンナテスト_01）. If Japanese is inconvenient, edit the single $testIdentifier definition in admin/widget-test-view.php. This creates another aggregation subject and leaves old data intact. The limit is 100 Unicode code points; whitespace, control characters, and ASCII symbols other than “-” and “_” are prohibited. No Unicode normalization is performed.

Chinese (测试01), Korean (테스트01), Arabic (اختبار01), Hebrew (בדיקה01), Russian (Тест01), and Greek (Δοκιμή01) can also be used. Input acceptance does not guarantee RTL display quality. Do not add directional control characters to identifiers; isolate direction in presentation with bdi and dir="auto", and verify mixed-script display and copying in an actual browser.

### 6.3 Configuring and Releasing Abnormal Access Blocks

The “Access concentration detection” tab shows monitoring, active blocks, and the last 60 days of history above its settings. History page sizes are 20 (default), 50, 80, or 100, with the choice saved in the browser. See Specification Sections 4.2–4.3 for behavior, expiry cleanup, and failure limitations.

New Web installations enable detection and automatic blocking. Existing saved settings remain unchanged; older installations without a settings file default to detection ON and automatic blocking OFF. Detection OFF also disables automatic blocking and its control. Re-enabling detection does not automatically enable blocking. Detection ON with automatic blocking OFF provides monitoring-only operation. Legacy detection OFF/automatic blocking ON settings are normalized to both OFF on reading and cannot be saved inconsistently.

Like information retrieval uses state.php for automatic count/state checks; its default detects more than 2,400 requests in 60 seconds. Like submission uses like.php and defaults to more than 240 in 60 seconds. Configure the monitoring window and request threshold; even with default automatic blocking, inspect history and tune conditions for the site's scale and usage.

The retrieval model assumes a 5-second refresh: about 12 requests/minute per button, about 1,200 for 100 independently refreshing buttons, with twice that allowance giving 2,400. The 240 submissions/minute threshold averages 4 per second and is provisional. Neither value is measured traffic or proof of an attack. Buttons sharing an identifier share retrieval; visible pages, refresh intervals, and usage change traffic. Shared IPs and reverse proxies may cause false blocks.

Confirm the target management ID and use the confirmation action to release a block manually. This resets both API counters. Turning detection or automatic blocking OFF alone does not release active blocks. After expiry or manual release, new detection/reblocking follows the current settings. Check administrative notices for missing tables or read failures and logs for public detection failures. Detection failure does not guarantee success of the public API as a whole. Verify operation and monitor logs; do not recover by disabling authentication or safety mechanisms.

## 7. Operations, Passwords, Backups, and Archives

### 7.1 Administrative Display and Passwords

Before login, URL parameters language and theme change only the temporary display for that session. They persist after login failure or reload but do not save shared administrative settings. Successful login clears the temporary display and restores saved settings. After authentication, language and theme selections are saved through a CSRF-validated POST.

New or changed passwords require at least 12 Unicode code points, no more than 72 UTF-8 bytes, valid UTF-8, and no NUL characters. Character and byte counts differ: for example, 24 typical Japanese characters occupy 72 bytes. Leading and trailing whitespace is retained; Unicode normalization and truncation are not performed. Hashing explicitly uses bcrypt with PHP's default cost. No additional mbstring extension is required.

Existing hashes are not automatically changed during an update or login. The previous login limit of 1024 bytes for old passwords is retained. If an old bcrypt password exceeded 72 bytes, bytes beyond the first 72 cannot be distinguished. Neither the original length nor suffix can be recovered from the hash, so adopting the new rules requires an explicit password change.

In “Change administrator password” under “Administration and operations settings,” submit the current password, new password, and confirmation. Authentication, CSRF validation, the current password, the new rules, and matching confirmation are all required. Attempts are limited to 5 per minute per session. Failure preserves the existing hash and does not redisplay plaintext passwords.

Success ends the current session; other logged-in sessions expire on their next administrative request. Log in again with the new password. Browser Back or POST resubmission cannot repeat the change.

Sessions check a credential fingerprint on every request. Sessions predating this update lack the fingerprint, so one new login is required. The credential JSON format is retained; the secret and database connection values are not changed. There is no unauthenticated reset feature. Keep Web access to config denied.

### 7.2 Backups and Time Zone Changes

Safely back up the code, database, config settings and credentials, secret, and required log archives in corresponding states, and prepare a restoration procedure. Do not put backups in the web root or Git. Session identification for cloned environments follows Chapter 5.

A time zone change is separate from a normal settings save. Confirm that old aggregates and public counts will be reset. After selecting Yes, monitor the stages “Aggregating current data” and “Storing aggregated data.” The site API enters maintenance, and unaggregated events are included in processing.

Reset only after all CSV files and metadata have been saved successfully to the timestamped log directory. Restart from 0 in the new time zone without adding the old totals.

Check the destination shown in the completion notice. Archives are not automatically deleted, and there is no Admin dashboard download/delete feature. If capacity becomes an issue, the administrator manually deletes the relevant server archive after taking any required local backup. Keep direct access to config/ and log/ denied. Do not assume CSV can restore granularity lost through consolidation or deleted events.

## 8. Updates and Migration

The β-2 distribution ZIP is intended for new installations. General-user overwrite updates or automatic migration from old versions are not officially guaranteed. Section 8.1 is technical guidance for owners managing an existing β-1 installation to migrate administrative transport settings, subject to backups and verification on an isolated copy.

It is not an update procedure guaranteeing compatibility across all schemas, old versions, or data migrations. Read it together with Section 8.2, and do not repurpose it for reinstalling into an existing database. Existing technical procedures are retained as decision material.

### 8.1 Migrating Administrative Transport Settings from β-1

1. Prepare backups and a restoration procedure for the existing code, database, and configuration.
2. Confirm the HTTPS Origin, installation path, and presence or absence of a proxy for the Admin dashboard.
3. **Before replacing the code**, add the required `admin_security` settings to the existing `config/config.php`. Work on this document does not automatically edit actual settings.
4. Verify the update, login, cookies, and settings saves on an isolated copy before applying it.
5. The new dedicated session name requires administrators to log in again. Adding the administrative transport settings in this section retains existing administrator credentials, database connection values, and app_secret; the procedure does not initialize the existing database, aggregates, or public button browser storage. This does not guarantee compatibility of the update as a whole or the absence of database writes during normal operation.

After changing settings, also check that server facilities such as OPcache are not retaining old settings. This change does not automatically modify server caches or PHP configuration.

If the existing `site_origin` is a valid HTTPS address, production defaults work even without the added settings. The installation ID is derived with purpose separation from the existing `app_secret`, so do not regenerate the secret during an update. Missing or invalid settings do not relax requirements to HTTP.

If the existing `site_origin` uses HTTP, configure the administrative HTTPS `origin` first. Without it, the Admin dashboard stops with 503. This does not change the public API's Origin. There is no safe compatibility fallback for HTTP-only production environments. Defer the update and assess the situation until HTTPS is available.

```php
'admin_security' => [
    'environment' => 'production',
    'origin' => 'https://example.com',
    'admin_path' => '/pollyanna/admin/',
    'trusted_proxies' => [],
],
```

`origin` contains only scheme, host, and port. If public site settings will be changed through the Admin dashboard, explicitly setting this administrator-only Origin is recommended to avoid affecting the administrative endpoint. When omitted, the existing `site_origin` is used.

`admin_path` is the Admin dashboard URL path. When omitted, it is derived from `SCRIPT_NAME` ending in `/admin/index.php`. Specify it explicitly if a front controller or similar setup produces another value. Installation names containing dots or Japanese characters are supported; separators, control characters, and parent references are rejected and the value is URL-encoded. Neither Origins nor redirect destinations are built from `HTTP_HOST`. URLs permitted to authenticate over HTTPS/development HTTP are exactly the configured `admin_path` or its `index.php`. Alias URLs, different encodings, or an incorrect Cookie Path are rejected with 400 without starting a session. When using rewriting, align the user's actual URL with the configuration.

### 8.2 Old Beta Versions and Historical Schema Updates

Old beta versions are references for behavior and migration research, not code to deploy unchanged in production. Their issues include plain SHA-256 IP hashing, insufficient input/HTTP-method/response validation, JavaScript assuming success, and provisional connection settings. Do not assume compatibility with current HMACs, anonymous-browser limits, Origin permissions, transactions, or related mechanisms.

Historical designs include addition procedures using upgrade-identifier-urls.sql, upgrade-allowed-origins.sql, and similar files. After adding the Origin table, the primary Origin, base domain, and related settings must be saved; public Origins are not allowed while these remain unset.

The current distribution ZIP is intended for new installations and does not include these SQL files. It does not guarantee a unified update or data migration for existing installations. Compare the current schema with the existing database on an isolated copy, and do not reinstall without backups and restoration checks. Unverified details of specific migration paths remain in DevelopmentPlan.

## 9. Failure Recovery

### 9.1 Incorrect Administrative Settings

1. Before repeatedly accessing the Admin dashboard, the owner with file-management privileges backs up `config/config.php`. Do not delete the database or credential files.
2. Correct `admin_security.origin` to the actual HTTPS Origin and `admin_path` to the administrator path used in the browser. If a proxy is present, verify its actual connection-source IP and single overwritten header against provider documentation. Do not allow all proxies by guesswork.
3. Check appropriate PHP session settings and storage permissions/capacity. Preserve `app_secret` and `admin_credentials.json`; do not switch to `development` to disable protection.
4. Check PHP syntax and OPcache propagation, then connect through the configured HTTPS URL. Log in using normal administrator password and CSRF validation.
5. If correct values cannot be established, leave the system stopped. Do not create public recovery URLs, skip authentication, reset passwords, or bulk-delete session files.

### 9.2 Concurrent Installer Runs and Partial Failures

New installation and completion retries use a nonblocking exclusive lock on config/.installer.lock. State is rechecked after acquiring the lock; a new installation exclusively creates config/.installer-in-progress. Failure to acquire the lock stops processing before writes. Existing config, credentials, and secrets are neither deleted nor overwritten, and database tables are not reinitialized.

The progress marker is removed only on successful completion; the lock file remains for reuse. Do not delete progress markers from abnormal termination or partial failure solely because time has passed. A remaining progress marker means partial state even if a completion marker exists.

Use “Retry completion” only after an administrator confirms the previous process has stopped and saved config/credentials and required database tables can be validated. Recovery only creates the completion marker and removes the progress marker; it does not regenerate secrets or credentials. Missing config or credentials, or an invalid completion marker, stop processing without automatic recovery. Retain existing output files and arrange backups and review by the server administrator.

The file system must support file locks and exclusive file creation. Test concurrent execution on shared/network file systems or different PHP execution modes. Do not switch to unlocked execution when safety cannot be verified. Neither cron nor Redis is required.

### 9.3 Aggregation, Time Zone Change, and Session Storage Failures

When normal aggregation throws an exception, a rollback is attempted if a transaction is active, and an error is returned to the caller. There is no automatic retry within the same invocation. Unaggregated events retained after a successful rollback can be processed again after the fault is resolved, at the next aggregation trigger or through “Aggregate now” in the Admin dashboard. Event retention is not guaranteed when a failure leaves the commit outcome unknown.

Do not resubmit a Like to rerun aggregation. Like acceptance and subsequent aggregation are separate operations; the Like itself may already be stored even if aggregation fails.

For time zone changes, retain the old time zone, active data, and interruption state, correct the underlying cause such as protection settings, and use the Admin dashboard's confirmation/resume path. Do not clear aggregate tables before the archive is complete. Do not unconditionally disable maintenance or remove progress markers manually to avoid an error.

See Section 9.4 for cause-specific 503 diagnosis. A 503 caused by administrative session storage failure may occur after a settings change has completed. Do not automatically resend: restore permissions, capacity, and the storage method before checking state. Do not disable locks or bulk-delete session files. No authentication bypass or public recovery URL is provided.

### 9.4 Diagnosing Admin Dashboard 503 Responses

Do not identify the cause from HTTP status alone. The owner checks the actual URL, response body, settings, and server-side PHP diagnostics. The following covers what static comparison of current admin/index.php, RequestSecurity, AdminSession, and AdminSessionStore can establish. No implementation with new public diagnostic URLs or cause-specific error codes has been added.

| Stage/response | Possible causes and limits that can be established | What the owner should check |
| --- | --- | --- |
| 503 showing install/ removal instructions | install/ remains in a partial or completed state. Processing stops before transport validation or administrative session startup | Completion/in-progress state, the entire actual install/ directory, Chapter 3 and Section 9.2. Do not force an incomplete installation into a completed state |
| 503 with “管理通信・セッション設定を確認してください。 / Administrator transport or session settings are unavailable.” | The same catch handles configuration loading, administrator Origin/path validation failures, and session startup failures. The body alone cannot identify transport versus storage causes | config.php syntax, administrator Origin/path, secret/installation ID; then Section 5.1 PHP settings, files handler, destination, permissions, and capacity. Follow the order in Section 9.1 |
| 503 with “Administrator session storage is unavailable.” | Storage verification failed in final finish processing. Normal output or Location is discarded and replaced with 503. Finalization may also emit this response after startup fails, so it does not uniquely identify a save-time failure | read/write/close, possible PHP warnings/exceptions/handler failures, storage method, permissions, capacity, corruption. Follow Sections 5.2 and 5.5 when checking whether settings already took effect |
| 400, 403, 302 | Separate from 503. Host/path/proxy-information mismatches yield 400; production HTTP GET redirects to fixed HTTPS with 302; disallowed HTTP operations yield 403 | Agreement between the administrator URL and settings, actual proxy source, overwritten header, and Chapter 4. Do not redirect and resend POST |

No dedicated cause-specific error_log output was identified in the above administrative transport/session catch. Session processing captures warnings, exceptions, and return values to detect failure, but cause-specific logs must not be promised. Existing PHP/web-server diagnostics depend on configuration. The owner checks through private administrative means without publishing logs, settings, credentials, or SIDs.

A 503 from an external web server or proxy, or a public API maintenance 503, is not the same as the Admin dashboard processing in this table. Also check the response source and target URL. After storage failure, do not automatically resend: recover the cause and check applied settings. Switching to development, trusting all proxies, bypassing authentication, or bulk-deleting sessions are not recovery measures.

## 10. Post-Installation Checks and Documentation Versions

After a new installation, check install/ removal, the correct administrator URL/cookies/login, settings saves, the public API, limits, aggregation, and the protected CSV destination. Verify subdirectories, uppercase installation paths, proxies, and file-storage conditions in the actual deployment.

See Chapter 3 of [DevelopmentPlan](DevelopmentPlan.en.md) for existing test environments, scope, and limitations. Success with simulated HTTPS or CLI does not verify production TLS, Web PHP, or browser cookie decisions.

Distribution uses an allowlist for README, licenses, configuration templates, and required code, excluding tests, actual configuration, credentials, sessions, and logs. docs/ is currently excluded from the ZIP. Documentation referenced from README must match the code version. Do not switch README references before approval of this consolidation proposal.

### 10.1 Current ZIP Preservation Policy and Publication-Time Link Changes

The current tools/build-release.ps1 allowlist contains 37 files, including README.md, README.en.md, and install/readme.php, while explicitly excluding docs/. This phase does not change the ZIP, allowlist, or README.

The application README viewed on GitHub and the README in the ZIP are distributed from the same source file. If the application README contains only a relative docs/Installation.md link, it can exist on GitHub but fail in the extracted ZIP because docs/ is absent. Detailed user documentation intended to be reachable from both must use an absolute URL to a GitHub document whose correspondence to the target code has been verified. Repository-only entry points can use relative links.

| Target for revision (not edited in this phase) | Current state and publication-phase destination |
| --- | --- |
| Requirements/installation in root README.md | Distinguish official requirements of 8.3 or later from installer acceptance of 8.1 or later. Add repository-relative links to Simple_Like_Button/docs/Installation.md, Specification.md, and DevelopmentPlan.md |
| Administrative transport documentation in Simple_Like_Button/README.md and README.en.md (currently around line 50) | Currently references https://github.com/studiol1998/simple-like-button-system/blob/main/Simple_Like_Button/docs/AdminSecurityMigration.md. After approval and publication of the new authoritative Japanese documents are confirmed, switch to an absolute URL for Simple_Like_Button/docs/Installation.md in the same repository. Identify it as Japanese documentation while authoritative English documents do not yet exist |
| PHP descriptions in both application READMEs | Distinguish official support for 8.3 or later, permitted but unsupported installation on 8.1–8.2, the not-implemented warning, and the verification record on 8.5.12 |
| Administrative migration reference in tests/README.md (currently around line 114) | Change operations to ../docs/Installation.md and verification scope to ../docs/DevelopmentPlan.md, using Markdown links |
| Cross-references among the three new documents | Retain current relative references within docs/. Finally check that no references remain after withdrawal of the old 19 documents |

When code and documentation versions can be pinned, replace blob/main in absolute URLs with a release tag or commit reference whose existence and corresponding commit have been verified. Do not use uncreated tags as valid references. If main is used, state that documentation can advance ahead of the code and verify reachability at publication. GitHub publication status and URL reachability have not been checked in this phase.

Current old links are not immediately broken by local restructuring because the target documents have not yet been deleted. Verify publication and reachability of new links before removing old documents from publication. install/readme.php displays the bundled README as text/plain, so links may not be clickable in the browser, but absolute URLs can be copied. The bundled README itself must remain available locally after install/ removal.

Changing README creates differences from the README in the verified ZIP, requiring separate rechecks of distribution version, contents, and hashes. Do not overwrite the existing ZIP in this phase. Generate and verify a new distribution only under separate explicit instructions.
