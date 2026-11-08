# Pollyanna Current Specification

Creator: にくきぅSYSTEMs (Nikukiu-SYSTEMs)

Status: Approved documentation for the published v1.0.0-beta2 pre-release. This current specification consolidates the three previous Japanese development reference documents and adopted designs.

This document applies to v1.0.0-beta2. No document-specific version number is added. The published snapshot is recorded by the v1.0.0-beta2 tag; subsequent documentation corrections are tracked by Git commits. See Section 1.1 of [DevelopmentPlan](DevelopmentPlan.en.md) for the shared versioning policy.

## 1. Scope and Document Responsibilities

Pollyanna is a self-hosted Like button system designed for lightweight embedding in websites. An identifier distinguishes the subject whose Likes are counted, rather than the button itself. The Admin dashboard labels “いいね ID” and “Like ID” refer to the same value.

This document defines the currently established behavior. The [development plan](DevelopmentPlan.en.md) is authoritative for implementation and verification status, differences between the specification and code, and future decisions. The [installation and operations guide](Installation.en.md) is authoritative for specific installation and recovery procedures. This document does not resolve outstanding issues by inference. β-2 is a pre-release preceding real-site testing, not a stable release.

The officially supported runtime requirement is PHP 8.3 or later; PHP 8.2 and earlier are outside official support. Versions permitted by the installer and versions actually verified are separate matters. See Chapter 1 of Installation.

## 2. Identifiers and Installation URLs

### 2.1 Identifiers

- Use UTF-8, with utf8mb4 in the database, and a maximum length of 100 Unicode code points.
- Unicode letters, numbers, punctuation, and symbols are supported. The permitted ASCII symbols are the hyphen and underscore. Whitespace, control characters, and other ASCII symbols are rejected.
- An explicit data-identifier provides a manual identifier. Omitting the attribute generates one from the current path and DOM order. An empty attribute is a configuration error.
- Multiple widgets can share the same identifier for the same subject. Use distinct values for different aggregation subjects.
- Automatic generation does not guarantee uniqueness across pages. Use a manual identifier to keep the aggregation subject fixed.

### 2.2 Installation URLs

URL candidates are taken in this order: data-url, canonical, og:url, and the current URL. The query and fragment are removed. A normalized HTTP/HTTPS URL is registered only after a Like succeeds, not merely when state is retrieved.

Multiple URLs can be stored for the same identifier. The association is managed using the URL's SHA-256 and the identifier, with first-seen and last-seen timestamps. URLs containing credentials are rejected.

The request Origin and the registered URL's Origin must each be allowed, but they do not have to be identical. A disallowed URL Origin does not undo an accepted Like: URL registration is skipped and the condition is recorded in the server log.

Page titles and referrers are not collected.

## 3. Buttons, Like State, and Public Counts

The button changes from its unliked appearance to its liked appearance only after the server accepts the Like. Communication failures, maintenance, and limit responses are distinguished from normal success. The public JavaScript retrieves the initial state and reflects Like results.

A random anonymous browser token is stored in localStorage; the server stores its HMAC value. Identifiers that have been liked are also retained in the browser to help restore the display after aggregation deletes individual events.

The server uses unaggregated events to check state and reflects confirmed Like state in the browser. It does not retain per-browser history on the server long term solely to display Like state.

Clearing localStorage or changing the browser or storage area may cause the browser to be treated as a different anonymous user. Browser identification is not identity authentication. localStorage is isolated across Origins even for the same identifier, so shared state and shared limit allowances are not guaranteed.

Public counts are hidden by default. When enabled, they combine aggregated lifetime totals and unaggregated events so that aggregation does not lose counts. When disabled, the public API returns no counts and only the initial state check is performed.

Public count refresh intervals are 5 seconds (default), 10 seconds, 30 seconds, 60 seconds, and 0 seconds (no refresh). Buttons on the same page with the same identifier share their initial fetch and refresh timer, and immediately synchronize successful Like results.

Refresh stops while the tab is hidden or maintenance is active. Communication failures use exponential backoff up to 60 seconds. Duplicate initialization of like.js is prevented.

The system provides Japanese and English display, selectable success messages, fixed heart/diamond/spade/club/star/musical-note presets, separate symbol colors before and after a Like, and a count color. The unliked color is selected from dark gray #666666 or light gray #cccccc; the liked color is specified as #RRGGBB. When display settings are absent, the defaults are a heart, #666666, and #e53955. See Chapter 6 of Installation for configuration and message-editing procedures.

## 4. Like Limits and Abnormal Access Protection

### 4.1 Like Limits per Subject and Anonymous Browser

Accepted Likes are counted over the preceding 24 hours for each identifier/anonymous-browser pair. The limit is selectable from 1–5 Likes, with a default of 3. An IP hash is not used to identify a person for this limit. Individual aggregation events and limit-check records are separate. Expired limit-check records are deleted on the next Like for the same identifier and browser. Locks and transactions prevent concurrent submissions from being stored beyond the limit.

### 4.2 Abnormal Access Detection

Public API access monitoring and temporary blocking are independent of the per-subject Like limit. Detection uses the connecting IP during processing and handles an IP-derived HMAC. Using a raw IP during processing is different from storing it as a persistent Pollyanna record. Therefore, “no IP-based per-subject Like limit” must not be read as “no IP-related blocking.”

The administrative interface provides monitoring, blocking, unblocking, and history. The policy of not displaying IP hash values themselves in the normal Admin dashboard is retained.

In the statically inspected api/bootstrap.php, REMOTE_ADDR is obtained and passed to AccessProtectionService::ipHash. That class converts the IP using inet_pton and generates HMAC-SHA-256 with a purpose-separated key derived from the installation secret. The inspected database-write paths store IP hashes in access detection buckets and temporary blocks. The current history schema has neither a raw-IP column nor an ip_hash column.

This finding is limited to those code paths and SQL. It is not a real-environment audit of all logs or external storage destinations.

β-2 retains the current fail-open behavior specifically for abnormal access detection in api/bootstrap.php. When required detection tables are unavailable or detection throws an exception, a path writes a generic error_log message, stops that detection attempt, and returns to public API processing.

Failure to produce an HMAC for the connecting IP can also enter the exception path.

A detection failure does not guarantee success of the public API as a whole: subsequent database processing, for example, may still fail. Origin validation, input validation, per-subject Like limits, and other independent checks still apply and are not disabled by a detection failure.

However, existing temporary blocks may also be ineffective when detection is unavailable. Even if detection throws an exception, detection-related database updates may already have committed, for example when releasing a lock fails after commit.

Retaining fail-open describes the current β-2 behavior; it does not establish a permanent security policy.

Storage is split among access detection buckets, temporary blocks, and history. The old design statement “investigation IP hashes are retained for 60 days” is not applied uniformly to all storage destinations.

The records covered, retention periods, and deletion triggers remain verification items in Chapter 4 of DevelopmentPlan. Selecting 15, 30, or 60 days is not a current feature.

### 4.3 Abnormal Access Measurement and Blocking Rules

The following describes β-2 behavior recorded in the current code and README. The failure limitations in Section 4.2 and the outstanding review of retention targets and deletion conditions for each storage destination remain unchanged.

| API | Default monitoring window | Default request threshold |
| --- | --- | --- |
| state.php (Like information retrieval) | 60 seconds | 2,400 requests |
| like.php (Like submission) | 60 seconds | 240 requests |

Requests are measured per connection source and API in one-second buckets over the rolling window; detection occurs when the specified count is exceeded. The window accepts 10–3,600 seconds and the threshold 1–1,000,000 requests. Detection-only operation suppresses duplicate history while a detection record remains in the same source/API window.

Valid OPTIONS is not counted; invalid input, disallowed Origins, and invalid OPTIONS are counted. Disallowed-Origin 403 and valid-OPTIONS 204 responses are preserved even during blocking. Blocked allowed Origins receive a CORS-enabled 429 with Retry-After. This differs from the per-subject/per-anonymous-browser Like-limit 429. Blocking covers both public APIs, not the Admin dashboard or ordinary page views.

Automatic blocks expire 48 hours after creation. Exceeding the rule again after expiration or manual release creates a new history record and a new 48-hour block. There is no deadline extension or permanent blocking. Manual release resets both API counters. Detection OFF stops new detection and counter retention; automatic blocking OFF stops new automatic blocks. Existing active blocks remain until expiry, without deadline extension when settings change. As Section 4.2 explains, existing blocks may also be ineffective when detection is unavailable.

IPs come from REMOTE_ADDR; forwarding headers are ignored. Purpose-separated HMACs derived from the existing app_secret are used for short-term detection and block management; history stores neither IPs nor IP hashes. Shared networks and reverse proxies may combine visitors and cause false blocks. This is not network protection against large-scale attacks before they reach PHP or the database.

Expired data is deleted at the next public API check or authenticated administrative access. Valid OPTIONS also runs cleanup without being counted. Physical deletion is delayed without traffic, but expired blocks are not applied at the next check. Current-code history becomes eligible for deletion 60 days after detection. Do not apply this rule uniformly to all IP-related storage or interpret it as an implemented selectable-retention feature. No cron is required. Detection, cleanup, and release are serialized by a short database-scoped exclusive lock.

Internal storage and expiry checks use UTC. Administrative display uses the aggregation time zone and the abbreviation applicable to each timestamp, including daylight saving time. Time zone changes do not change detection history, block deadlines, or protection settings. See Installation Section 6.3 for configuration and release operations.

## 5. Data Structures, Aggregation, and Retention

### 5.1 Data Minimization and Structure

Timestamps are stored in UTC and assigned to the configured time zone during aggregation. Pollyanna's policy is not to store raw IPs as persistent records and to use IP-derived HMACs for detection. The connecting IP is used by the processing described in Section 4.2; this does not mean “IP addresses are never obtained.”

The policy of not collecting or storing page titles, referrers, raw browser tokens, or User-Agent is retained. The secret is kept in a configuration file, not the database.

Access logs maintained by web servers, reverse proxies, hosting services, and similar systems are separate from Pollyanna's own storage and may contain raw IPs. Administrators must check their settings, contents, and retention periods. This specification neither disables those logs nor guarantees the absence of records that have not been inspected. Retention periods and deletion conditions for IP-related records remain items in Chapter 4 of DevelopmentPlan.

The current fixed SQL defines the following 10 tables. See the [initial schema](../install/install.sql) for SQL types and indexes.

| Table | Purpose and main relationships |
| --- | --- |
| pollyanna_likes | Unaggregated events: id, identifier, client_hash, created_at. Indexes on identifier/timestamp and browser hash/timestamp |
| pollyanna_rate_limit_events | Short-lived events for Like limits. Indexes on identifier/browser hash/timestamp |
| pollyanna_lifetime_totals | Lifetime totals per identifier |
| pollyanna_period_aggregates | day/month/year, start date, identifier, and count. Composite primary key on period type/start date/identifier |
| pollyanna_hourly_aggregates | Site-wide month-start date, hour 0–23, and count. Composite primary key on month-start date/hour bucket |
| pollyanna_identifier_urls | Identifier, URL hash, normalized URL, first-seen/last-seen timestamps |
| pollyanna_allowed_origins | scheme, host, effective port, and subdomain permission. Unique constraint on scheme/host/port |
| pollyanna_access_buckets | Detection counts per IP hash/API/time bucket |
| pollyanna_access_blocks | Temporary blocks for IP hashes, expiration, and history association |
| pollyanna_access_history | Detection timestamps, counts, and block/unblock history. The current SQL has no ip_hash column |

The generic settings table proposed in the old Review is not treated as an existing table. Operational settings use configuration and saved JSON files under config, among other existing storage.

### 5.2 Aggregation Triggers and Atomicity

Individual Likes are recorded as events, reflected in aggregates, and then deleted. The automatic aggregation threshold is selectable from 1,000 (default), 2,000, 4,000, 6,000, 8,000, and 10,000 events. Processing runs when the threshold is reached or the Admin dashboard is accessed.

There is no scheduled task such as cron to process below-threshold events solely because time has elapsed. Without either trigger, the events remain unaggregated.

The default upper limit for a normal batch is 500 events. This is separate from the trigger threshold and daily-row limit. For now, the batch limit is not configurable through the Admin dashboard.

Retrieving events, updating aggregates, and deleting processed events take place within the same short database transaction. If an exception occurs while a transaction is active, a rollback is attempted and an error is returned to the caller.

There is no automatic retry within the same invocation. Unaggregated events retained following a successful rollback can be processed again after the fault is resolved, at the next aggregation trigger or through “Aggregate now” in the Admin dashboard.

If the commit outcome is unknown, for example after a connection loss, event retention is not guaranteed.

A database-level lock prevents aggregation processes from running concurrently. Committing deletion and aggregation together is the basis for preventing double counting and record loss.

### 5.3 Long-Term Retention and Hourly Aggregation

Long-term statistics have three basic forms: lifetime totals, period aggregates, and hourly aggregates.

#### Established Design Policy

- Daily data is normally retained for up to 5 years and 50,000 rows. Older data exceeding either retention or row-count conditions is consolidated into monthly data. The 5-year period provides a margin for demand for approximately the most recent 3 years of detail. If the row-count condition is reached first, detail is lost before 5 years; this is not a guarantee that 5 years of daily detail will always remain.
- The β-2 daily limit of 50,000 rows is fixed. The policy is to retain it in v1.0.0. Changing it through the Admin dashboard is not a current feature.
- Monthly data is consolidated into yearly data; yearly data is retained. The original design of retaining monthly data for 5 years is preserved, but its starting point is handled separately as a verification item below.
- Hourly data covers the whole site and is aggregated during the existing batch into monthly, 1-hour buckets. Only nonzero buckets are stored, with the most recent 24 months retained. The structure does not provide per-identifier hourly aggregates.

#### Unverified Implementation Details and Differences from the Original Design

Whether the 50,000-row limit applies globally or per identifier, how creation of the 50,001st row relates to consolidation starting, and how many batches are required to resolve an excess remain unresolved verification items. Static code readings must not be turned into design guarantees. These are tracked as observations and re-verification items in Chapter 4 of [DevelopmentPlan](DevelopmentPlan.en.md).

For the historical 50,001-row test recorded in Section 3.1 of DevelopmentPlan, the number of identifiers, data insertion method, batch conditions, and other details are unverified. The current SQLite test using 50,002 identifiers is a separate, limited test under different conditions. It establishes neither the design scope of the limit nor the conditions for clearing excess rows on real MySQL. See Sections 3.1 and Chapter 4 of DevelopmentPlan.

For monthly retention, the original design “5 years after conversion to monthly data” and the implementation using period_start have different starting points.

The starting point must not be settled by inference, and the actual monthly retention period is not guaranteed. Deciding to reassess retention conditions is separate from deciding to implement resulting changes such as a higher limit or selectable retention periods.

Recalculation from individual events that have already been aggregated, or restoration of monthly/yearly data to daily detail, is not possible. Hourly aggregation is implemented, but its inclusion in the stable release still awaits a decision after trial use. Implementation and formal adoption are separate states.

## 6. Admin Dashboard and Settings

The current code has 5 tab categories: Dashboard, individual details, administration and operations settings, site display settings, and abnormal access detection. The old design's “Settings” is split into administration/operations and site display. Older statements such as “authentication not implemented” and “localhost-only test page” do not describe the current production interface.

| Item | Current functionality and limitations |
| --- | --- |
| Dashboard | Total, aggregated/unaggregated counts, identifier count, most recent 7 days, 12 months including the current month, and hours 0–23 of the current month. Daily and similar displays use aggregates consistent with unaggregated counts |
| Individual details | ID search, period/granularity, lifetime/period counts, trends and tables, pagination in groups of 20, and installation URLs. Hourly data is a separate site-wide panel |
| Aggregate now | Normally runs 1 batch. Separate from reloading a graph. Event retention and rerun conditions on failure follow Section 5.2 |
| Save categories | Administration/operations and site display settings are saved separately. Changing the time zone is an independent operation |
| Display language and theme | Administrative and public languages are independent. 9 themes: System, Light, Dark, Green, Blue, Orange, Red, Purple, Monochrome |
| Abnormal access detection | Monitoring conditions, detection history, temporary blocking, and unblocking |

Distinguish a count of 0 from a fetch failure, a period with no stored data from a measured zero, and consolidated granularity from daily detail. The design policy of providing graphs together with tables, without relying solely on color or mouse interaction, is retained. Adoption of old layout dimensions and future percentage-display proposals is tracked in DevelopmentPlan.

New or changed administrator passwords must contain at least 12 Unicode code points, occupy no more than 72 UTF-8 bytes, be valid UTF-8, and contain no NUL. Hashing explicitly uses bcrypt with PHP's default cost. Leading and trailing whitespace is preserved; no normalization or truncation is performed.

Existing hashes are not automatically updated. A change requires authentication, CSRF validation, the current password, and matching confirmation; successful changes require logging in again. See Chapter 7 of Installation for detailed procedures and old-password limitations.

## 7. Installer Behavior Contract

The installer supports Japanese and English and has 7 stages: Welcome, Environment check, Database settings, Basic settings, Review, Installation, and Completion. It does not create the database itself or database users. Fixed SQL creates tables in a dedicated empty database. A cryptographically random secret represented by 64 hexadecimal digits, configuration, administrator credentials, and a completion marker are saved.

Sessions and CSRF protection are used. Database connection values are not concatenated into SQL. Passwords and secrets are not shown on review screens or written to logs. New installation and completion recovery recheck state under a file lock and exclusively create progress markers and output files.

Existing configuration and credentials are not overwritten, and database tables are not reinitialized. General reruns or overwrite-based repair of completed installations are prohibited.

After a failure, created output files and progress state are retained. Recovery is limited to completion processing after confirming the previous process has stopped and validating the output files. Chapter 9 of Installation is authoritative for details.

While the complete install/ directory remains present, the Admin dashboard in partial or completed installation states stops before login with removal instructions, without starting an administrative session or operation. Deleting only index.php or performing a UI operation does not unlock it. Giving removal instructions priority over invalid transport settings does not create an authentication bypass.

The administrator Origin and path are generated after confirmation and validation. Production administrator Origins require HTTPS. Local HTTP requires explicit selection and verification of a direct loopback connection. This does not mean installer-wide HTTPS enforcement or an installation token is implemented.

## 8. Origin, CORS, and Administrative Security

The public state/like API requires Origin. Missing, null, invalid, or disallowed Origins receive 403, with no Referer fallback. This Origin restriction is not applied uniformly to the Admin dashboard or installer.

The primary site uses site_origin, site_base_domain, and site_base_domain_include_subdomains; external Origins use the allowlist table. The scheme and effective port must match. The host must match exactly or, when allowed, end with “.” followed by the permitted host. Subdomain permission does not apply to localhost or IP addresses. Simple substring matching is not used.

An Origin accepts only an HTTP/HTTPS scheme, host, and port. The scheme and DNS host are lowercased and default ports omitted. Paths, queries, fragments, and user information are rejected. Allowed external Origins receive a specific Access-Control-Allow-Origin and Vary: Origin; wildcards and credentials are not used.

JSON POST requests from external Origins require preflight. OPTIONS also validates Origin and returns 204 when the requested method is POST and the only requested header is Content-Type. The actual POST is validated again. Removing an external Origin in the Admin dashboard does not delete Likes, aggregates, identifiers, or URL history.

Origin permission is not bot authentication. It does not prevent forged Origins, XSS on allowed sites, subdomain takeover, localStorage clearing, or distributed access. Input validation, Like limits, abnormal access protection, and transactions remain necessary.

Administrative authentication uses production HTTPS, session ID regeneration, CSRF protection, login attempt limits, and authentication expiry. Chapters 4–5 of [Installation](Installation.en.md) are authoritative for administrative cookies, storage methods, time limits, concurrency guarantees, and HTTP rejection. Public polling does not extend administrative authentication.

## 9. Time Zone Changes and CSV

A time zone change is treated as a change of operating region. Historical aggregates are not rearranged into the new time zone. After confirmation, unaggregated events are also finalized and archived in a timestamped log/ directory before active aggregates are reset. Public counts restart at 0 and are not added to the old totals.

During the change, the public API enters maintenance and interruption state is saved.

The output files are metadata.json, lifetime_totals.csv, period_aggregates.csv, and hourly_aggregates.csv. They record the change timestamp, old and new time zones, and related information. CSV is generated with fputcsv; identifiers that could be interpreted as formulas receive a leading apostrophe. Archive failure preserves the old time zone and active data; data is not cleared before archival storage is complete.

There is no automatic archive deletion deadline or Admin dashboard feature for downloading or deleting archives. A general statistics CSV download interface must not be described as implemented. See Chapters 7 and 9 of Installation for operations and backups, and Chapter 3 of DevelopmentPlan for test results.

## 10. Established Limitations

- Automatically generated identifiers can collide across different pages.
- localStorage is isolated by Origin, browser, profile, WebView data area, and similar boundaries. User identity continuity after clearing it is not guaranteed.
- Installation targets HTTP/HTTPS web pages. file:, data:, custom schemes, and null Origin are not allowed.
- Availability and persistence of JavaScript, Fetch, and localStorage in WebViews depend on application settings. Official support and minimum versions for individual applications remain undecided; real-device verification is not guaranteed.
- Installation with components separated outside the web root is currently unverified and unsupported.
- The β-2 distribution is intended for new installations. Unconditional updates from old beta versions and data compatibility are not guaranteed.
- The official requirement of PHP 8.3 or later does not mean all versions have been verified or that unpatched versions are safe.
