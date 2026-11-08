# Pollyanna Development Status and Plan

Creator: にくきぅSYSTEMs (Nikukiu-SYSTEMs)

Status: Approved documentation for the published v1.0.0-beta2 pre-release. [Specification](Specification.en.md) is authoritative for behavioral rules; [Installation](Installation.en.md) is authoritative for operations and recovery. The six current Japanese/English documents are the reference set; the 19 old originals are preserved in archive/legacy/.

This consolidation uses AI assistance for document comparison and editing. The creator's responsibility for design/specification decisions is distinct from AI assistance with implementation, review, and testing. Where the record does not identify who performed a historical task, no attribution is inferred. This phase consists only of static comparison and documentation; program and database tests have not been rerun.

## 1. Current Status and Status Definitions

β-2 is a pre-release with implemented functionality, not a stable release. Implementation and isolated-test records exist for new installation, the Admin dashboard, public APIs, Like limits, aggregation, CSV, and related features. This does not establish that real-site operation, real TLS, or complete browser workflows have all been verified.

| Status | Meaning |
| --- | --- |
| Implemented | Present in the code. Separate from completed verification in actual operation |
| Verified | Successful only within the recorded environment and test scope |
| Planned implementation | Future work adopted by the creator; not implemented yet |
| Under consideration | Adoption, content, and timing are undecided |
| Re-verification required | Documentation/code differences or insufficient evidence remain |

Button embedding/sharing/automatic identifiers, state retrieval, Japanese/English display, Like limits, count refresh, daily/monthly/yearly/hourly aggregation, administrative settings, the installer, administrative security, Origin/CORS, and abnormal access detection are implemented. Verification scope is separated into Chapter 3, and conditions/specification differences into Chapter 4. Old statements such as “the installer is next” and “authentication is not implemented” must not be readopted as future work.

<a id="document-versioning"></a>

### 1.1 Applicable Version and Documentation Versioning

The six current Japanese/English documents apply to the published v1.0.0-beta2 and have completed approval, translation, and comparison review. Including historical versions and plans for v1.0.0 and v1.0.1 or later does not mean the current code is at those versions. No separate document version numbers are added.

The published β-2 code and documentation are associated through annotated tag v1.0.0-beta2 and commit 08d6d3c0bbec051e9f602a68d9c70dc1f0b28892. The publication snapshot remains available through the tag. Subsequent documentation corrections are tracked by Git commits on main without rewriting the existing tag, Release, or distribution ZIP.

When code changes in the future, update behavioral rules in Specification, operations/environment/recovery conditions in Installation, and implementation status/outstanding issues/test results here. Separate intended adoption from implemented features, and record the environment, scope, and results when tests finish. Review changed descriptions again, then record the code/document relationship after approval. Do not reinterpret released history or test records according to later specifications.

## 2. Development History

The following history preserves the established ImplementationPlan record. Completion dates are not newly inferred or corrected. Items such as localhost initial setup on 2026-10-03 are historical records, not a complete account of current administrative transport settings. See Installation for current operations. Temporary database creation/deletion in the test records below describes past work, not operations in this phase.

### 2.1. Initial Version Plan and Recorded Milestones

The 0.0.x–0.1.0 entries below retain the stage names and status of the original memo. Do not infer mappings to later CHANGELOG versions such as 0.3.0, 0.4.0, or 0.4.1.

| Stage | Completion date | Original status and implementation | Verification and notes |
| --- | --- | --- | --- |
| 0.0.0 | 2026-09-24 | Organized the application's basic concept | Expanded the earlier idea of a security-hardened Web applause system into Like buttons, administration/log viewing, installation made as simple as possible, and related elements. This became the basic concept |
| 0.0.1 | 2026-09-24 | Targeted minimal database connectivity, identifier/Like persistence, input validation, a Like API, and the transition from ♡ to a colored ♥ after successful storage | No explicit test results. Prioritized Like submission and storage rather than implementing every feature of each version at once. No Like limit at this stage; administration/operations settings through 0.1.0 were planned to cover it |
| 0.0.2 | 2026-09-24 | Restored Like state for the same browser and identifier after reload | Browser identification considered from 0.0.1, using a localStorage browser token and existing tables. Verification targets included the stored-event query API, reload restoration, and isolation across browsers/identifiers. Requirements were also expanded as the reference through v0.0.x |
| 0.0.3 | 2026-09-25 | Introduced Like counts. Before the Admin dashboard, configuration-file show_like_count (default false) controlled display | Hidden mode returned no counts from the public API. Before aggregation, counts were stored-event counts per identifier |
| 0.0.4 | 2026-09-29 | Daily/monthly/yearly aggregation and a simple site-wide display of monthly, 1-hour buckets for the most recent 24 months | Planned to store liked identifiers in localStorage as well, anticipating deletion of individual events after aggregation. Clearing localStorage means a different user. See Specification for implementation details and the relationship to Like limits |
| 0.1.0 | 2026-10-05 | Implemented through the local verification page.<br>Combined the Admin dashboard/installation operations, Like limits, count-display switch, and display colors for trial use on the creator's site | Inclusion of hourly aggregation in the public release was left until after trial use. CHANGELOG v0.1.0 (Draft) records initial structure/design; the relationship between this stage name and public release history requires confirmation |

---

### 2.2. β-1 (Completed 2026-10-16)

- Public-facing explanations
- License decision (GPL-3.0 license)
- Origin/CORS controls
- Button colors/display and preview
- Abnormal access detection and temporary blocking
- Administrative display: administrator passwords, installer, and concurrent-processing recovery
- Aggregation regression tests and distribution allowlist preparation

---

### 2.3. β-2 (Completed 2026-11-05)

- Included 4 required class files and JavaScript for copying embed code
- Addressed administrative transport, sessions, CSRF, and concurrent processing
- Warning for a remaining installer directory
- Embedding examples and identifier explanations
- Prepared PHP requirements and installation/recovery procedures
- Confirmed identifier collision limitations. Separation outside the web root is unverified and unsupported.
- Awaiting real-site testing in a private environment.

---

### 2.4. Implementation History

2026-10-03: Implemented the production Admin dashboard with Dashboard/individual details/settings, Japanese/English switching, 9 color themes, and responsive display. Dashboard displays totals, aggregated/unaggregated counts, Like ID count, the most recent 7 days and 12 months, and current-month hourly data from the real database. Individual details display monthly hourly data, ID search, target periods, daily/monthly/yearly trends, lifetime/period counts, pagination in groups of 20, and installation URLs. Settings are saved by administration/operations and site-display category; time zone changes were connected as independent operations including archival, reset, and interruption/resume.

Administrative authentication was also implemented. Initial setup used localhost to set an administrator password of at least 12 characters and stored its hash in `config/admin_credentials.json`. Subsequent operations use session authentication, session ID regeneration on successful login, CSRF validation, a limit of 5 attempts per minute, and logout. Both initial setup and login screens include password-visibility controls.

Installation URLs are obtained from `data-url`, canonical, `og:url`, then the current URL. Query and fragment are removed, and association with the Like ID occurs only after a successful Like. Referrers and page titles are not collected. Multiple URLs can be registered for the same ID. `install/upgrade-identifier-urls.sql` was provided for existing installations.

Implemented the Like API, state restoration, daily/monthly/yearly/hourly aggregation, rate limits, Japanese/English widgets, local verification page, settings, and time-zone-change archives. Automatic Like count refresh defaults to 5 seconds and supports interval selection. With counts off, only the initial Like-state check runs; counts are not fetched. Section 3.1 consolidates the 2026-09-29 aggregation failure/retry and simultaneous 2-process verification results.

2026-10-04: Implemented the Web installer with Japanese/English display, environment checks, database connection checks, time zone/administrator password settings, review, fixed-SQL table creation, and saving secrets/configuration/credentials/completion markers. After completion, the Admin dashboard is locked before login until the `install/` directory is removed. See Section 3.2 for isolated end-to-end test conditions and results.

2026-10-05: Added a beginner-oriented Welcome/Preparation screen at the start of the Web installer. It explains the dedicated empty database, database user, privileges on the target database, and the 5 connection values to record. Where database/user names receive automatic prefixes, users are instructed to copy the control-panel display exactly. The flow became 7 stages: Welcome, Environment check, Database settings, Basic settings, Review, Installation, Completion.

2026-10-09: Consolidated public widget state retrieval and automatic count refresh per identifier. Multiple buttons sharing an identifier use 1 initial fetch and 1 periodic timer; successful Like results immediately update all buttons. Refresh stops while the page is hidden or maintenance is active; communication failures use exponential backoff up to 60 seconds. Duplicate loading of `like.js` is prevented per page. The Firefox error `Permission denied to access property "nodeType"` has no established causal relationship with continuing POST requests, so DOM processing was left unchanged and investigation remains open.

See Section 3.1 for 2026-09-29 aggregation, compression, interruption recovery, and CSV results; Section 3.6 records threshold-test numbers and conditions at that time. End-to-end checks under actual site conditions and access protection outside Apache remain tasks before production template embedding.

### 2.5. Decision Following Threshold Verification

The default automatic aggregation threshold remained 1,000 after isolated MariaDB verification. The normal 500-event batch and the historical 30-event verification condition are distinct. See Section 3.6 for numbers/environment/results and Chapter 4 for comparison with the current implementation.

## 3. Verification Records and Limits of Guarantees

### 3.1 Aggregation, Failure, and CSV Records

This section records aggregation/failure/CSV test results and their guarantee boundaries. Chapter 2 retains implementation history and decisions; repeated result explanations are replaced by references here.

- A temporary database `pollyanna_integration_tmp` was created on XAMPP MariaDB 10.4.32 and deleted after verification.
- An intentional SQL error in hourly aggregation left the 2 original events intact and all lifetime, period, and hourly aggregates unchanged.
- After removing the error, rerunning reflected only those 2 events; the next aggregation processed 0, confirming no double count. Running 2 PHP processes simultaneously then produced 2 processed events in one and 0 in the other, with final totals of 2 and 0 unaggregated events.

Old-data compression and interruption/resume of time zone changes were verified on 2026-09-29 with a temporary XAMPP MariaDB database and isolated file storage. Tests confirmed count preservation through daily-to-monthly and monthly-to-yearly conversion at the 5-year boundary, addition into existing aggregates, and the daily 50,000-row limit (moving 1 of 50,001 rows to monthly data). Time zone checks covered resuming after 500 events, preserving the old time zone/511 aggregated events/maintenance state on an archive-protection error, resuming after correction, and post-commit recovery.

The historical “move 1 of 50,001 rows to monthly data” statement is a result recorded in the old Japanese/English ImplementationPlan. No test code was found that reproduces its identifier count, date distribution per identifier, input-data creation method, or processing/batch conditions; these conditions remain unverified. It does not establish that the 50,001st Like or creation of the 50,001st row directly triggered automatic processing, or that the limit is strictly enforced for every identifier at every moment.

The separate current tests/aggregation-review-tests.php uses an in-memory SQLite database and a MySQL-SQL test adapter. It directly INSERTs 1 day row each for 50,002 distinct identifiers starting with target-00000, all on the same date: the first day of the month 2 months earlier. It calls aggregateBatch with UTC and a batch of 2, checks that 2 month rows are created, that Dashboard monthly totals are preserved, and that rerunning does not double-count.

Those current-code conditions differ from the historical 50,001-row MariaDB test. The test was not run in this phase and does not establish real-MySQL locking/concurrency or the design scope of the limit. The unverified items in Chapter 4 and Specification Section 5.3 remain.

The following CSV formula protections are test records from the same day.

- Archive CSV containing test identifiers such as `=1+1`, `+SUM(1,1)`, `-1+1`, and `@SUM(1,1)` was generated from a temporary database and opened in Microsoft Excel.
- Column A of `lifetime_totals.csv` and column C of `period_aggregates.csv` displayed strings with leading apostrophes; formulas were not evaluated.
- The verification archive was placed in XAMPP's `log/csv-safety-test-20260929/` and clearly marked as test-only.

These are synthetic test data, not examples of permitted normal input. Current pollyanna_identifier_is_valid rejects ASCII symbols other than “-” and “_”; values containing “=”, “+”, “@”, parentheses, and similar characters therefore do not meet normal identifier input rules. CSV formula protection remains output-side protection.

The old Japanese/English decision logs dated 2026-09-29 establish only that an archive was generated from a temporary database and checked in Excel. The inspected repository documents and tests contain no record showing whether those 4 kinds of values entered through the normal public API, a dedicated test path, direct SQL, or another route. Their insertion path remains unverified.

The separate current tests/runtime-compatibility-tests.php shows a test-only path that directly INSERTs values such as “=SUM(1,2)”, “+formula”, “-formula”, and “@formula” into an in-memory SQLite lifetime-total table and invokes the actual CSV writer through ReflectionMethod. It neither tests public API input acceptance nor proves the same insertion path was used in the 2026-09-29 Excel test. This test was not run as part of this revision.

### 3.2 End-to-End Installer Verification

On 2026-10-04, `Pollyanna_Installer_Test`, isolated from the existing installation, a dedicated empty database, and a localhost-only database user were used to check environment validation, database connection, basic settings, review, table creation, configuration saving, and completion. After completion, retaining `install/` caused the Admin dashboard to show only removal instructions and prevented login. Temporarily renaming `install/` released the lock, allowing login with the installation password and display of Dashboard for the empty database. A dedicated test page also verified Like submission, ♥ state restoration, public counts, aggregation on Admin dashboard access, and Dashboard graphs.

### 3.3 Administrative Security Test Scope and Limitations

The working directory for the relative commands below is Simple_Like_Button/ (D:\Kaihatu\イイネボタン\Pollyanna\Simple_Like_Button), not the repository root. Select the PHP CLI executable under evaluation. These are execution instructions, not commands run during this investigation.

The regression runner requires CLI and proc_open, plus session, PDO, pdo_sqlite, and other facilities required by each suite. Missing extensions mean SKIP, not PASS. Exit codes are 0 for all passed, 1 for failure, and 2 for incomplete. Child processes use PHP_BINARY and the parent's php.ini (or -n if none is loaded). Additional INI scanning depends on the environment; individual -d options on the invocation are not inherited automatically. Enable required extensions in the configuration actually selected.

The 8 regression suites use an allowlist and do not automatically run real-MySQL or HTTP tests. Run HTTP tests separately in an isolated environment supporting CLI, proc_open, pdo_sqlite, session, and loopback communication. Concurrency tests also require CLI process creation and a dedicated writable temporary session area. Do not use production credentials or actual settings; understand that files/directories are created and deleted in OS temporary storage. See existing tests/README.md and the individual tests for details.

The following methods and limits are recorded in AdminSecurityMigration, not results from this phase. Automated new-installation tests that substitute for SQL are distinct from end-to-end tests with a dedicated database.

```text
php tests/run-regression.php
php tests/admin-security-http-tests.php
php tests/admin-session-concurrency-tests.php
```

run-regression.php runs unit/regression tests without communication; admin-security-http-tests.php is an explicitly invoked local HTTP integration test. Concurrent-session testing is described below.

HTTP tests copy code and dummy settings into a unique OS temporary directory, start 2 servers on 127.0.0.1 only, and also send concurrent administrative GET/settings POST requests for the same SID. They do not connect to a persistent database or use actual TLS; trusted termination headers are simulated. Servers and the temporary directory are cleaned up afterward.

They do not verify real TLS, hosting environments, all MySQL functionality, or browser cookie-sending decisions. Old HTTP tests assume PHPSESSID or HTTP operation and need future adaptation for isolated environments. Do not run them in production.

Concurrency tests use only PHP file sessions in an OS temporary directory they create, starting 2–3 PHP CLI processes. Across 7 scenarios they check GET-equivalent processing, actual settings-save functions, logout, expiry boundaries, CSRF renewal, and ordering of password changes across SIDs. Any unnecessary 503/RETRY is a failure. Storage-method-dependent read/write/close failure notifications use an injected handler; corruption is also tested with native temporary session files. OS disk failure or power loss is not reproduced.

Additional new-installation tests link the actual installer Basic settings form, shared configuration generation, and actual Admin dashboard login using generated files. They also check removal-instruction conditions for complete/partial/uninstalled states, Japanese/English display, unchanged sessions during instructions, HTTP rejection, explicit development exceptions, and uppercase subdirectories. HTTPS is simulated through server variables in isolated test code. Database connection steps are substituted through test sessions; SQL is not executed. Complete installation including MariaDB, real TLS, and real browsers requires a dedicated verification environment.

### 3.4 Prior-Phase Report on β-2 Distribution and Final Regression

The prior-phase report in the conversation records verification of the 37 distribution ZIP files, extracted-file syntax, HTTP startup without a database, new installation with a dedicated database, administrator login/logout, settings saves and Japanese/English display, and API counts from 0 to 3, rejection of the 4th Like, and independence of a different identifier. Mock verification of the copy feature is separate from actual browser clipboard operation.

The final regression report records successful PHP syntax checks, 8 regression suites, administrative HTTP, concurrent sessions, JavaScript syntax, language JSON, and distribution-dependency checks on PHP 8.5.12. Committed distribution targets and the verified ZIP were reported to have no substantive differences apart from newline normalization. These remain prior-phase reports, not re-verification by this consolidation work or guarantees for untested environments.

The distribution allowlist already includes RequestSecurity.php, AdminSession.php, AdminSessionStore.php, InstallerConfig.php, and embed-copy.js. RuntimePolicy.php is for diagnostics/regression, not a runtime dependency, and is excluded from the ZIP. Future integration into normal operation would require reassessing dependencies and inclusion. The current exclusion of docs/, tests, actual configuration, credentials, sessions, and logs is unchanged in this phase.

### 3.5 Incomplete Verification

Verify real-site deployment, actual TLS/production proxies, real-browser rendering/clicks/shared synchronization/copying, PHP versions other than 8.5.12, WebViews/major applications, non-Apache access protection, and real-host file locks/capacity/storage failures separately from recorded limited tests. Officially supported PHP versions and actually tested versions are not equivalent.

AlmaLinux 10.1 is a planned new-installation verification environment, not a verified platform merely because it is named. Linux ownership/write permissions/SELinux, non-Apache direct-access denial, PHP and static-asset delivery in cgi-bin/, and mixed-script/RTL display and copying remain real-environment checks to perform as applicable to deployment conditions.

### 3.6 Integrated Threshold Verification

Automatic aggregation thresholds were checked on isolated MariaDB. At 999 events, none were aggregated. Posting the 1,000th through the API aggregated 500. After opening the Admin dashboard in Edge, an automatically executed 30-event batch left 470 unaggregated, 530 aggregated, and a total of 1,000, confirmed in the interface and database. The default automatic aggregation threshold remained 1,000.

The 30-event batch records the conditions at that time; it does not establish a current normal administrative batch limit of 30. Its relationship to the normal 500-event batch remains a verification item in Chapter 4.

### 3.7 Current Origin Tests and Execution Guidance

Both files still exist. The working directory is Simple_Like_Button/, as in Section 3.3. The following is guidance, not results from this phase.

| File | Currently identified scope and conditions | Relationship to the regression runner and other tests |
| --- | --- | --- |
| tests/phase2-origin-tests.php | Loads actual OriginPolicy code and checks scheme/host/port, default ports, IPv6, rejected syntax, subdomain boundaries, URL Origins, primary-site configuration, and localhost/IP handling. No database connection | Included in the 8 suites of tests/run-regression.php. The same file is called, not retired or replaced |
| tests/phase2-db-tests.php | Requires CLI, pdo_mysql, a MySQL/MariaDB connection, and a dedicated test environment. After creating the fixed schema, drops the Origin table and applies the additional SQL, checking columns, Origin uniqueness, and physical deletion | Not part of the 8 regression suites. SQLite regression and HTTP tests do not substitute for the same MySQL-schema verification |

The standalone Origin unit-test command is:

```text
php tests/phase2-origin-tests.php
```

The database-test command is php tests/phase2-db-tests.php, but run it only after preparing an authorized dedicated MySQL/MariaDB verification environment. The code reads POLLYANNA_TEST_DB_HOST/PORT/USER/PASSWORD and falls back to 127.0.0.1, 3306, root, and an empty password when unset. Explicitly verify all connection values to avoid unintentionally connecting to an existing server. Do not put credentials in Git or reports.

The test CREATEs a database named pollyanna_phase2_test_ plus random characters and executes DROP DATABASE in finally. The connection user needs privileges for CREATE/DROP DATABASE, CREATE/DROP TABLE, SELECT, INSERT, DELETE, and other test operations; normal application-user privileges may be insufficient. Although this is not a test that targets an existing application database, it creates, modifies, and automatically deletes a real database.

It was not run because it conflicts with this phase's restrictions. Do not run it in the future without permission covering database creation and automatic deletion. The Origin unit test does not guarantee HTTP CORS headers or real-browser preflight, and the database test does not guarantee update compatibility for the installation as a whole.

## 4. Specification/Implementation Differences and Outstanding Issues

The following are observations from static comparison of documents and source, not conclusions re-verified against a real database while organizing this document. The specification is unchanged; these observations provide material for checking current behavior and making decisions.

| Item | Original memo | Static comparison and remaining verification |
| --- | --- | --- |
| Scope of daily-row limit | Maximum 50,000 rows.<br>Application unit unspecified | AggregationService.php counts period_type day rows without filtering by identifier.<br>Recheck the reading that this is a system-wide limit using real-database tests with multiple identifiers |
| Unit of daily records | Described as daily values | The install.sql period-aggregate primary key and aggregation key correspond to period type/date/identifier.<br>Re-verify conditions for 1 row per identifier per day, days without Likes, and time-zone-change effects |
| Boundary and immediacy | Convert to monthly when the limit is exceeded | The implementation moves excess rows above 50,000 within batch limits. It does not guarantee always remaining strictly at or below 50,000 or how many runs clear the excess |
| How the limit changes | Make it configurable through program settings | β-2 manages a fixed 50,000 through DAILY_ROW_LIMIT. The policy is to retain this in v1.0.0.<br>A feature to change it through the Admin dashboard is under consideration for v1.0.1 or later. Feasibility, allowed range, and handling of existing data after changes remain undecided |
| Start of monthly 5-year period | 5 years after monthly conversion | Consolidation compares period_start with the first day of the month 5 years earlier.<br>It could not be established that the implementation starts retention at conversion time; the difference from the original text needs resolution |
| 500 versus 30 events | Normal upper limit 500; integration test processed 30 after administrative access | Current administrative processing uses the batch setting, default 500.<br>Retain 30 as the historical local verification condition, not a current guarantee |
| IP-related records | Investigation IP hashes deleted within 60 days | Current storage separates individual Likes, Like limits, detection buckets, temporary blocks, and history.<br>Individual Likes do not have an ip_hash storage structure. Do not describe all IP-related records as having 60-day retention; check records, deadlines, and deletion triggers separately |
| Hourly aggregation | Implemented; public adoption after trial use | The feature exists in β-2.<br>Do not label it unimplemented; separate stable-release adoption from display-improvement proposals |
| Version correspondence | 0.1.0 implemented through local verification page | Correspondence with the same-name CHANGELOG Draft is unclear. Do not infer a combined mapping among initial-stage names, release history, and dates |

Inspected implementation includes `src/AggregationService.php`, `install/install.sql`, `src/bootstrap.php`, `admin/index.php`, and administrative transport/session/installer classes. Establishing the current specification requires comparison with corresponding design material and regression tests.

### 4.1 PHP Requirements and Warning Display

Adopt the creator's decision that PHP 8.3 or later is the official runtime requirement and 8.2 or earlier is outside official support. The current installer passes 8.1 or later and permits installation on 8.1–8.2. An unsupported-version warning is planned and not implemented yet. Installation on 8.0 or earlier is not permitted.

Required work is to compare Japanese/English display, environment results, and checks through installation execution in install/index.php, adding a warning rather than simply raising the rejection threshold to 8.3. Test rejection of 8.0 and earlier, warning with permission on 8.1–8.2, and the checks for 8.3 or later. Also investigate whether old-PHP guidance is reachable before loading classes containing 8.1 syntax.

Existing README text describes an “implementation minimum of 8.1” and needs revision distinguishing official support at 8.3 or later, installer acceptance at 8.1 or later, and verification on PHP 8.5.12. This phase does not change README, code, or tests. RuntimePolicy's patched-version diagnostics for 8.3/8.4/8.5 are a separate contract; results for unknown branches must not independently narrow official requirements.

### 4.2 Other Comparison Items

- The causal relationship between Firefox's nodeType access error and continuing POST requests is unverified. Do not describe it as fixed through DOM changes.
- Old administrative documents assume 3 tabs, but current code has 5 categories: Dashboard, individual details, administration and operations settings, site display settings, and abnormal access detection. Treat this as a difference in implementation stage, not an unimplemented monitoring feature.
- “Installer hardening not implemented” in old administrative security material describes the overall phase. Administrative environment configuration generation was added later, but installer-wide HTTPS enforcement and installation tokens must not be called implemented.
- Proxy support for the IP used in login attempt limits is a follow-up issue in old documents. Do not declare completion here; inspect the connection-source IP path.
- New Web abnormal access settings enable automatic blocking, but AccessProtectionService fallback defaults disable it. Existing settings must not be changed in bulk; defaults need to be explained by installation path.
- Initial research proposals for Origin normalization, IDN, trailing dots, and public suffix handling must not automatically become established rules. Current OriginPolicy validates ASCII hosts and related syntax. Check adopted scope and safe permission conditions.
- Old beta migration English says “6 times in 6 hours,” whereas Japanese and old code say “10 times in 6 hours.” Do not carry the mistranslation into the current specification.
- The 3 old English design documents contain references to consultation with a person, a “two types”/3-item inconsistency, outdated structure, and future specifications. The three current English documents have been reconstructed from the approved Japanese sources and comparison-reviewed; they are distinct from the old English originals.
- Do not confuse the old Review's settings-table proposal with current config/JSON settings. Do not transfer old logs/ notation or the verification page's 30 events into current log/ and normal 500-event specifications.

The old Review's question about an unavailable IP is addressed in current api/bootstrap.php by a path that catches access-detection exceptions, writes a generic log, and continues other public API processing. Missing required tables similarly prevent use of detection. Describe this as a limit on detection guarantees during failure, not as disabling all input validation or Like limits. Check required tables and configuration before operation.

The old InstallerDesign completion requirement to “save config.php and credentials atomically” is not carried forward as a guarantee that configuration, credentials, and database changes are all saved in one transaction. Later pre-release security updates and current code use locks, exclusive output creation, and partial-state preservation, rejecting general reruns when files are missing. Distinguish exclusive creation of individual files from atomicity of the whole process. Retain the specific recovery conditions in Installation Section 9.2.

## 5. Work Before the v1.0.0 Stable Release

### 5.1. Scope and Completion Criteria

Feature additions to β-2 are generally closed. v1.0.0 adds no new features; work addresses safety, correct operation, and corrections needed for the stable release. Do not relist implemented features as new implementation plans. The stable release completes the initial development phase.

Before the stable release, check Chapter 4 differences affecting safety, count preservation, correct operation, or user-facing explanations, and perform required fixes and regression tests if problems are found. Although retention-extension research belongs to Chapter 6, this does not unconditionally defer current behavioral inconsistencies or incorrect explanations.

### 5.2. Distribution Preparation and Real-Site Testing

Begin real-site operation tests from the verified β-2 distribution. Retain existing distribution-verification results; success using development source alone must not stand in for testing the distribution.

Deploy to the real verification site and embed buttons, then check APIs, buttons, the Admin dashboard, aggregation, time zone changes, and recovery end to end under actual installation conditions. Establish embedding specifications for approximately 20 HTML templates, incorporate them after testing, then prepare to transfer body-content components. Test pages are approximately 200 fixed pages. Also implement embedding in blog-system templates and test automatic identifier assignment.

Checks include real-browser rendering, Likes, state restoration, shared identifiers, manual/automatic identifiers, display/refresh intervals, Japanese/English switching, copying, administrative authentication/saving/operation tests, production HTTPS/reverse proxies/session storage, access denial, and data preservation. Investigate reproduction conditions and effects of the unresolved Firefox issue as well.

Time-zone-change recovery has been verified in isolation, but archive protection, backups, manual deletion, and interruption/resume procedures must be checked at the deployment site. If supporting non-Apache servers, configure and verify equivalent direct-access denial for config/ and log/. Do not guarantee support for unverified environments.

### 5.3. Bug Fixes and Regression Verification

Fix issues found on real sites and, according to their impact, recheck the existing 8 regression suites, administrative HTTP, concurrent sessions, PHP/JavaScript syntax, language JSON, and distribution dependencies. Reuse existing aggregation failure/retry/double-count-prevention, old-data consolidation, and time-zone-recovery tests.

Before the v1.0.0 stable release, conduct fault-injection testing for abnormal access detection. For missing detection tables, IP-conversion failures, database connection failures/SQL exceptions, and lock acquisition/release failures, inspect responses from both state.php and like.php, other Origin/input/Like-limit validation, effects on existing temporary blocks, and the committed state of detection-related database updates. These are unperformed verification tasks; β-2 retains current fail-open behavior. Changing to fail-closed, which would stop the public API when detection is unavailable, remains undecided and is not an adopted implementation plan. Record test environments, results, and unperformed items, separating implementation from verification in actual operation.

Threshold detection already exists. Treat low-load detection as checks of current behavior/load and necessary bug fixes, not a “new implementation plan.” Broad performance improvements are under consideration in Chapter 7.

### 5.4. Documentation, Sample Site, and Stable Distribution

Organize and complete the user guide and build a sample site. Prepare README, Japanese/English installation instructions, limitations, security/recovery procedures, CHANGELOG, licenses/credits, and the stable-release package. Treat hourly aggregation as implemented, review trial results, and record its adoption decision for the stable release; additional graph features are not a release condition.

Organize implementation/test results at each stage to determine commit scope and tagging. Before publication, check GitHub synchronization, distribution targets, ZIP contents, hashes, and runtime dependencies. This is a future publication plan. Publishing stable v1.0.0 completes initial development.

### 5.5 Remaining Documentation Restructuring and PHP Display Work

Approval of the Japanese authoritative documents, English translation/comparison review, README reference changes, archiving of the 19 old originals and audit record, and the β-2 commit/tag/Release publication are complete. The 19 originals remain tracked in Git; they have not been untracked. Outstanding specifications and unperformed tests remain tracked here.

There is a plan to implement and verify the unsupported-PHP warning with the necessary explanation. The warning remains unimplemented, and the start date for the code change has not been determined. Retain the policy of resolving differences affecting safety or correct operation before the stable release.

## 6. Work Categories and Plans for v1.0.1 and Later

Future work is divided into the following 3 categories. They organize existing decisions without newly changing adoption or version plans.

| Category | Meaning | Scope and timing |
| --- | --- | --- |
| ① Implementation adopted | A decision to add the feature exists. This is separate from completed implementation or fully settled details | Separation outside the web root (6.1, target v1.0.1); selectable IP retention (6.6, v1.0.1 or later) |
| ② Investigation/verification adopted | Investigation/testing will be performed; adoption of resulting changes is undecided | Reassessment of daily retention/consolidation (6.2), provisional load model and verification on both shared-hosting profiles (6.3–6.4), load evaluation of current threshold detection |
| ③ Concepts/proposals with adoption undecided | No promise of adoption, content, or implementation timing | Increase to 100,000 rows or more, selectable daily retention, confirmation UI for shortening retention, hourly-display/retention extensions, and Chapter 7 additional-feature proposals |

A category ② decision to reassess does not imply a category ① decision to add the feature being evaluated. Chapter 7 real-device checks remain test-management information, separate from official browser/application support decisions.

### 6.1. Installation Outside the Web Root (① Implementation Adopted; Target v1.0.1)

Installation with components separated outside the web root is currently unverified and unsupported. Implementation and verification are targeted for v1.0.1.

Preserve compatibility with normal installation while allowing configuration and similar files outside the web root. Update the installer and verify operation in both arrangements.

### 6.2. Reassessing Daily Retention and Consolidation (② Investigation/Verification)

In post-release maintenance/improvement, first inventory and re-verify the existing specification and implementation. Work is scheduled for v1.0.1 or later; this does not promise implementation of changes in v1.0.1. Determine necessity, content, and implementation version from investigation results.

Check the following:

- Scope of the current 50,000-row limit. Verify global/per-identifier treatment and related behavior against a real database as well.
- Relationship between 5-year retention conditions and the row-count limit. Evaluate how many years of daily detail actually remain.
- Conditions and boundaries for daily-to-monthly consolidation, and the starting point for monthly-to-yearly consolidation.
- Consolidation timing, number of processing segments, locks/transactions, and processing load.
- Whether increasing the limit to 100,000 rows or more is appropriate.
- Whether selectable daily retention is needed.
- Handling irreversible granularity changes, such as daily-to-monthly conversion when retention is shortened. Consider explanations, confirmation, backups, and related needs.

Higher limits, selectable retention, confirmation UI, and similar features are not adopted implementation decisions. Preserve the original design decisions in Specification and decide on the basis of measurements and verification.

### 6.3. Provisional Load Model (Verification Conditions for ②)

Assume 300 or more identifiers on the creator's site currently and 400 or more after 1 year. Use a provisional daily active-identifier rate of 40–60%: for 400 identifiers, at least 1 Like occurs on 160–240 identifiers per day.

The 40–60% is not measured data; it is a provisional design assumption based on site-operating experience. Daily variation can be substantial, so use it to describe average weekly/monthly load. It guarantees neither total Likes nor a constant number of new rows every day.

Use Chapter 4 static observations as verification hypotheses for whether daily records are generated as 1 row per identifier/date and whether the row-count limit is system-wide. Check the implementation path and a real database. Do not calculate a definitive retention duration from unverified conditions.

### 6.4. Verification Environments (Verification Conditions for ②)

Verify data-retention/consolidation behavior and load assuming both high-performance and standard shared-hosting environments. No particular provider or plan is mandatory. Record identifier counts, daily activity distribution, data volume, consolidation, administrative access frequency, and other test conditions, clearly stating environmental differences and the scope to which results apply.

### 6.5. Other Continuing Considerations

Hourly-display extensions and storage of older years are category ③ proposals; load evaluation of current threshold detection is category ②. Adoption/content of subsequent changes require separate decisions. Apply Chapter 5 policy to safety/correctness problems found on real sites.

- A proposal to show hourly graphs as percentages of the selected month's/year's total, together with hourly counts and the period total.<br>Consider the denominator, low-count display, and available periods. Distinguish this from the currently implemented display.
- Whether hourly data for years older than the most recent 24 months is needed and how to store it. Retention expansion is undecided.
- Load evaluation and possible improvement of the current threshold-reached detection path. Do not revert the 500-event limit decision or failure tests to unperformed status.
- Maintenance issues arising from real-site operation. Follow Chapter 5 for safety/correctness issues and identify necessary work before the stable release.

The additional IP-retention policy is consolidated in 6.6.

### 6.6 Additional IP-Related Retention Feature (① Implementation Adopted; v1.0.1 or Later)

The adopted decision covers adding selection, the choices/default below, and confirmation requirements when shortening retention. Detailed design is incomplete for tables/records covered, which timestamp is the creation time, deletion triggers/chunk size, and handling existing buckets/blocks/history. Uniform application to all current storage destinations has not been decided; investigate dependencies before designing it. This is not a current feature.

The decision log's additional policy is Admin dashboard selection of 15, 30, or 60 days, default 30 days. Each record's creation time starts the period; records exceeding it become deletion candidates. When shortening retention, notify the user in a confirmation screen that affected existing records will be deleted, then delete after confirmation. First verify correspondence with current storage and effects on abnormal access detection/blocking. This is an addition planned for v1.0.1 or later, not a condition for v1.0.0 or a currently implemented feature.

### 6.7 English-UI Screenshots for README (Planned for v1.1.0)

The β-2 English README uses the current Japanese-UI screenshots. Replacing them with English-UI screenshots is planned as documentation work for v1.1.0. This is neither a prerequisite for β-2 publication nor a completed screenshot replacement.

## 7. Unadopted Proposals and Browser Verification Tasks

Additional-feature concepts fall under Chapter 6 category ③. The policy of real-device checks and management of unperformed tests are category ② verification work; adoption or official support of particular browsers/applications remains undecided. Keep the Section 7.3 checklist as development-management information after documentation restructuring is complete.

### 7.1 Adoption Decisions from Initial Origin Research

The 2026-10-06 v0.4.1 preliminary investigation (target 4752db2, document df62299) initially recommended allowing missing Origin. Later mobile research and Phase 2 design adopted rejection of missing/null Origin and no Referer fallback. Do not restore the old recommendation to the current specification.

The initial investigation's 20 discussion points are carried forward as follows.

| Discussion point | Adopted or unresolved treatment |
| --- | --- |
| Missing/null Origin | Rejection adopted |
| Primary Origin persistence | Implemented with administrator confirmation |
| Addition to existing installations | Installation migration; individual paths require isolated verification |
| Multiple primary Origins | Distinguish the primary Origin from external permissions. Do not add an arbitrary multiple-primary-Origin specification |
| Nonstandard port | Matching scheme/effective port adopted |
| IDN, public suffix | Check normalization/permission scope in Chapter 4 |
| Subdomains of localhost/IP | Disabled |
| Like cancellation/URL registration failure for disallowed URL Origin | Retain accepted Like, skip URL, and log |
| canonical on another Origin | URL-side permission also required |
| og specification | Adopted as og:url |
| Shared limits/Like state across Origins | Explain localStorage isolation. Unified personal identification/sharing is not guaranteed |
| Maximum external-Origin registrations and ownership checks | Not adopted as established additional requirements; necessity remains under consideration |
| Preflight cache duration | Optional additional design; do not introduce a fixed value |
| Origin-rejection logs and deletion-confirmation UI | Recheck adoption across paths/displays; do not label unverified items implemented |

Hourly percentages/counts/period totals and display of older years remain Chapter 6 considerations. Email notification for sudden increases is also an unresolved proposal in the old list, not a current feature or stable-release condition.

The old AdminInterfaceDesign's 34px headings, 16px baseline/14px supplementary text, 15px graph notes, 3px bar spacing, card positions, tab borders, and other dimensions/layouts are review mockups. Comprehensive comparison of adopted portions has not been performed; do not promote all of them to established current specifications. Static previews save neither database data nor settings. Separately check design intent and implemented guarantees for preserving unsaved input, leave-page notices, preventing partial application within a save category, and display of years with insufficient retained data.

### 7.2 Unresolved Mobile Research Questions

The following remain unresolved in the original investigation. Advanced modes without Origin and similar proposals are not adopted decisions or instructions to change the current strict approach.

1. Minimum officially supported browser versions.
2. Names and versions of social-media/messaging applications to verify.
3. Whether to add an error display when localStorage is unavailable in Android WebView.
4. Whether to wrap localStorage access in try/catch and degrade functionality using a temporary token.
5. Whether private browsing receives official support or limited support with nonpersistent state.
6. Whether a future advanced API mode should support communication without Origin.
7. Whether to provide an HTTPS-hosted verification page for WebViews.
8. How to collect the information needed for in-app-browser bug reports.
9. Whether Fetch Metadata such as `Sec-Fetch-Site` should be used as an auxiliary check. Some clients do not support it, so making it mandatory is not advisable.
10. Whether alternative-engine editions on iOS / iPadOS should be separate official support targets.

### 7.3 Real-Device Verification Checklist

The following are unperformed candidates inherited from the 2026-10-06 investigation. Unchecked boxes do not mean completed work. Minimum supported browser versions and official support for major applications will be determined in a separate decision based on these verification results. Browser/OS policies and engine circumstances are information tied to the research date, not newly verified current information.

#### Common Functionality

- [ ] Initial state retrieval
- [ ] Like submission
- [ ] Count refresh
- [ ] Synchronization of multiple buttons with the same identifier
- [ ] Page reload
- [ ] Like state restoration
- [ ] localStorage persistence
- [ ] rate limit
- [ ] Same-Origin communication
- [ ] Allowed external-Origin communication
- [ ] Successful preflight OPTIONS
- [ ] Origin also present on the actual POST
- [ ] Rejection of disallowed Origin
- [ ] Rejection of missing Origin
- [ ] Rejection of `Origin: null`
- [ ] API error details readable from an allowed external page

#### PC

- [ ] Windows Chrome
- [ ] Windows Edge
- [ ] Windows Firefox
- [ ] Windows Brave
- [ ] macOS Safari
- [ ] macOS Chrome
- [ ] macOS Firefox

#### Android

- [ ] Chrome normal mode
- [ ] Chrome Incognito mode
- [ ] Firefox
- [ ] Brave
- [ ] Android System WebView with DOM Storage enabled
- [ ] Failure display when DOM Storage is disabled in Android System WebView
- [ ] Representative social-media in-app browsers
- [ ] localStorage after application exit/restart
- [ ] Token regeneration after browser-data deletion

#### iOS / iPadOS

- [ ] Safari normal mode
- [ ] Safari Private Browsing
- [ ] Chrome
- [ ] Edge
- [ ] Brave
- [ ] WKWebView with persistent `WKWebsiteDataStore`
- [ ] WKWebView with nonpersistent `WKWebsiteDataStore`
- [ ] Representative social-media in-app browsers
- [ ] localStorage after application exit/restart
- [ ] Token regeneration after website-data deletion

#### HTTP Information to Observe

- [ ] Origin on OPTIONS
- [ ] Access-Control-Request-Method on OPTIONS
- [ ] Access-Control-Request-Headers on OPTIONS
- [ ] Origin on POST
- [ ] Presence and value of Referer, without using it for decisions
- [ ] Access-Control-Allow-Origin in responses
- [ ] Vary: Origin in responses

### 7.4 Research References

The following URLs preserve sources recorded in the old mobile research. External page contents and reachability were not rechecked in this phase. Distinguish standards-based expectations, official engine documentation, and items needing real-device checks for individual applications.

#### Web Standards and Cross-Browser References

- [WHATWG Fetch Standard — Origin header / CORS protocol](https://fetch.spec.whatwg.org/)
- [MDN — Origin header](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Origin)
- [MDN — Cross-Origin Resource Sharing (CORS)](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CORS)
- [MDN — Access-Control-Allow-Origin](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Access-Control-Allow-Origin)
- [MDN — Referrer-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referrer-Policy)
- [MDN — Web Storage API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Storage_API)

#### Android

- [Android Developers — WebSettings.setDomStorageEnabled](https://developer.android.com/reference/android/webkit/WebSettings#setDomStorageEnabled(boolean))
- [Android Developers — WebView](https://developer.android.com/reference/android/webkit/WebView)

#### Apple / WebKit

- [Apple Developer — WKWebViewConfiguration.websiteDataStore](https://developer.apple.com/documentation/webkit/wkwebviewconfiguration/websitedatastore)
- [WebKit — Tracking Prevention in WebKit](https://webkit.org/tracking-prevention/)
- [WebKit — App-Bound Domains](https://webkit.org/blog/10882/app-bound-domains/)
- [Apple Developer — Using alternative browser engines in the European Union](https://developer.apple.com/support/alternative-browser-engines/)
- [Apple Developer — Using alternative browser engines in Japan](https://developer.apple.com/jp/support/alternative-browser-engines-jp/)
- [Apple Support — Clear Safari history, cache, and cookies](https://support.apple.com/105082)

## 8. The 19 Source Documents and Information Preservation

The following historical mapping records content comparison during consolidation; it does not make old documents secondary authoritative copies of the current specification. The 19 old originals have been moved unchanged to archive/legacy/. Unless stated otherwise, planned or unperformed work in this chapter describes the restructuring stage at that time.

| Source file | Consolidation destination and retained information |
| --- | --- |
| DatabaseDesignReview.md | Data/limits/settings in Specification. Undecided items/proposals separated into Chapter 4 here |
| DatabaseDesignDecisionLog.md | Adoption rationale/aggregation/identifiers in Specification; tests, additional IP-retention policy, and outstanding issues here |
| ImplementationPlan.md | History/status/plans in Chapters 1–6 here. Current rules separated into Specification and operations into Installation |
| AdminDashboardItems.md | Current displays/message settings in Specification/Installation. Old localhost and unimplemented-authentication states distinguished as history |
| AdminInterfaceDesign.md | Adopted interface functionality/save categories in Specification. Dimensions/unadopted display proposals in Chapter 7 here |
| InstallerDesign.md | Preparation, 7 screens, safety, defaults, and recovery in Installation. End-to-end tests in Chapter 3 here |
| AdminSecurityMigration.md | Operations/settings/session guarantees/recovery in Installation Chapters 3–5 and 8–9. Tests/inclusion decisions in Chapter 3 here |
| LegacyMigration.md | Old-beta incompatibility/migration cautions in Installation. History and mistranslation comparison here |
| OriginCorsDesign.md | Origin/CORS in Specification; configuration/migration in Installation |
| OriginCorsResearch.md | Adoption decisions and Origin/anonymous-identification limitations in Specification. Old recommendations and unresolved points in Chapter 7 here |
| OriginCorsMobileResearch.md | Usage limitations in Specification/Installation. Unresolved items, all real-device checks, and references in Chapter 7 here |
| DatabaseDesignReview.en.md | Compare differences from Japanese; reference for English reconstruction, not an independent current specification |
| DatabaseDesignDecisionLog.en.md | Same approach; do not carry forward errors such as “two forms” versus 3 items |
| ImplementationPlan.en.md | Same approach; do not import outdated structure/dates/planned-work classifications back into Japanese |
| AdminDashboardItems.en.md | Compare with the corresponding old Japanese administrative list; do not describe current authentication as unimplemented |
| AdminInterfaceDesign.en.md | Reference for proposals corresponding to Japanese; do not establish dimensions/layout as final |
| InstallerDesign.en.md | Compare with Japanese; retranslate after approval of the new Japanese documents |
| LegacyMigration.en.md | Check the limit mistranslation against Japanese/old code; do not adopt 6 times in 6 hours |
| OriginCorsDesign.en.md | Align with Japanese; use Japanese as the authoritative source for new English documents |

### 8.1 Information Not Copied Verbatim and Remaining Checks

- Do not carry old scope declarations, repeated paragraphs, old section numbers/cross-references, or historical commit-discussion text into current procedures. Historical versions, dates, test numbers, and reasons are retained in the text.
- Do not copy every dimension/layout from old UI proposals or all browser-specific explanations from early research verbatim. Preserve them in the originals as unadopted mockups and dated research, while carrying forward necessary limitations, unresolved points, and all real-device checks.
- Preserve English documents for translation without adding their old explanations to the new Japanese specification. Creating the three new English documents is a separate phase.
- Verification of current retention conditions, IP storage, version correspondence, UI details, and similar matters is incomplete. Do not infer specifications to fill the gaps.

### 8.2 Information Management After Restructuring

The Chapter 8 mapping, explanations of material not copied verbatim, and verification scope specific to restructuring may move to historical material with the creator's approval after consolidation approval, inheritance checks, reference switching, and original-preservation checks are complete. Consolidation and publication are complete; the mapping remains here as restructuring history.

Do not move implementation status, outstanding issues, development plans, Chapter 3 test evidence/guarantee boundaries, or the Section 7.3 unperformed-test checklist into historical material merely to reduce line counts. Preserve decision rationale and technical limitations during transfer.

### 8.3 Verification Scope During Consolidation (Historical)

Check preservation of the existing 19 documents, the inheritance mapping, important operational information, PHP policy, separation of old specifications, headings/tables/code/relative links in the three new documents, and clear differences in code. No application regression tests or database operations are performed. Existing README references are also retained until review approval.

### 8.4 Item-Level Comparison of the Old 19 Documents (Second Pass)

An item-level audit checked inheritance from the old 19 Japanese/English documents into the three new documents to prevent missing specifications/tests/unresolved issues and accidental adoption of old specifications. It covers 1,519 comparison units including headings, paragraphs, list items, table rows, and code examples—not 1,519 independent specification requirements.

After corrections, totals are 431 in ① correctly inherited, 975 in ④ preserved as historical material, and 113 in ⑤ conflicting across old documents or adoption undecided. Corrected ② inaccurate/③ missing-information items, comparison method, category definitions, all IDs/original lines/destinations/reasons, and preservation records for the old originals are retained in the Japanese audit record [LegacyDocumentAudit.md](archive/audit/LegacyDocumentAudit.md).

Audit category ⑤ does not establish specifications. Track outstanding items in Chapters 4, 6, and 7 without promoting them to established decisions absent the creator's judgment or additional verification. The old 19 originals are unchanged. Historical storage of audit records follows Section 8.2.

### 8.5 Publication-Preparation Categories After the Second Pass (Historical)

This assessment concerns the β-2 pre-release. Do not treat completion of stable-release verification as a prerequisite for publishing β-2. These are investigation-result categories, not instructions to implement or publish in this phase.

| Category | Scope/location | Reason and required action |
| --- | --- | --- |
| A: Required before publication | All three new documents, Japanese/English README, entry points to old English/old documents | Approval of these three documents and publication scope decisions are required. Do not present old English retention/authentication/person-related wording alongside current authoritative documents. The creator decides old-version labeling/publication scope if English reconstruction is not ready |
| A: Required before publication | PHP descriptions in root/application Japanese/English READMEs | Align explanations to distinguish official requirements of 8.3 or later from installation permission on 8.1–8.2. State the not-implemented warning and verification on 8.5.12. Do not independently change code checks |
| A: Required before publication | Administrative-document URLs in application Japanese/English READMEs, tests/README, and publication locations of the three new documents | Perform the specific I10.1 link switches and existence checks. Do not withdraw old documents before replacement references work. A relative link to docs absent from the ZIP must not be the only user entry point |
| A: Required before publication | Consistency of distribution README with the verified ZIP | README or similar changes require a separate comparison of distribution version/content/hashes. No ZIP generation or overwrite in this phase |
| B: Publication possible with limitations stated | Retention conditions in S5.3 and D3.1/4 | Historical 50,001-row identifier counts and related details are unverified. Current SQLite tests with 50,002 identifiers use different conditions. Avoid strict retention/limit guarantees |
| B: Publication possible with limitations stated | IP records in S4.2 and D4/6.6 | Clearly state that per-storage details and future selection features are incomplete. Explain paths where detection cannot continue after detection failures or missing tables |
| B: Publication possible with limitations stated | Migration in I8 and 503 in I9.4 | No general overwrite-update guarantee. Responses do not uniquely identify a 503 cause; resending after a save also needs care |
| B: Publication possible with limitations stated | D3.3/3.5/7.3 and I1 | Distinguish simulated TLS/SQLite/CLI from real MySQL/TLS/browsers. Do not guarantee verification on all PHP 8.3-or-later environments. State that the PHP 8.1–8.2 warning is not implemented |
| C: Stable-release or follow-up work | Real-site tests/user guide/sample site in D5 | β-2 is a pre-release before field testing; retain these as work through stable v1.0.0 |
| C: Stable-release or follow-up work | Separation/IP-retention selection/retention reassessment/display proposals in D6–7 | Preserve existing v1.0.1 targets/later plans; distinguish decisions to investigate from decisions to adopt features |

The table preserves the second-pass classification. At that time, category A README changes, English/old-document publication policy, ZIP consistency, and GitHub reachability were outside consolidation scope and unperformed. README updates, English comparison review, original preservation, distribution ZIP comparison, and tagged-document reachability checks were subsequently completed, and β-2 was published as a pre-release. Category B limitations are reflected in the approved documentation and publication explanations; this does not resolve the unverified conditions themselves. Even for category C, if a concrete safety/count-preservation/correctness issue is found, reassess necessary pre-publication action under Chapter 5.
