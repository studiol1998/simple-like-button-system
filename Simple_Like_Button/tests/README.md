# PHP 8.3 / 8.4 / 8.5 regression baseline (stage 1)

Run from `Simple_Like_Button/` using the PHP executable being evaluated:

```text
php tests/run-regression.php
/path/to/php83 tests/run-regression.php
/path/to/php84 tests/run-regression.php
/path/to/php85 tests/run-regression.php
```

The runner uses `PHP_BINARY` for each child process and explicitly selects the
parent's loaded php.ini, or `-n` when no php.ini is loaded. The inherited environment
still controls additional INI scanning. Per-invocation `-d` settings are not
forwarded; put required extension settings in the selected php.ini/scan directory.
Requirements: CLI, `proc_open`, session, PDO and
`pdo_sqlite`. Missing suite extensions produce SKIP, not PASS. Exit codes: 0 = all
selected suites passed, 1 = failure, 2 = incomplete (skips or unavailable runner).
The runtime policy classification is informational and independent of test success.
Running an old PHP binary for migration investigation does not authorize its use
in production. No packages or PHP versions are downloaded by this runner.

## Isolation and coverage

Only the explicitly listed suites run. Each runs in a fresh PHP process with
E_ALL diagnostics treated as test failures (except explicitly suppressed errors).
PHP error logging is disabled for child processes. Child output is consumed but
not forwarded, even on success, to avoid leaking paths from startup diagnostics.
Uncaught test exceptions use a fixed message without a stack trace. The runner
reports suite outcomes; standalone suites report assertion counts. An existing
test deliberately emits a cleanup-failure message as part of its failure test.
No application config is loaded, no MySQL server is contacted, and existing
configuration, databases, sessions and logs are not written. Sessions use a
test-only memory handler; SQLite uses `:memory:`. CSV tests invoke the actual
private writer via reflection and create/delete one uniquely allocated file in
the OS temporary directory. Run these tests only from CLI, never upload tests as
part of the production package.

| Suite | What it validates |
|---|---|
| runtime-policy-tests.php | Boundary versions, numeric comparison, unsupported branches, invalid/prerelease input, vendor revisions, CLI/Web scope |
| phase1-php-tests.php | Actual identifier/Unicode and URL normalization functions; some API source invariants |
| phase2-origin-tests.php | Actual Origin policy, scheme/host/port, subdomain and URL decisions |
| admin-password-policy-tests.php | Actual password rules, bcrypt boundaries, legacy verification |
| runtime-compatibility-tests.php | PHP session lifecycle with memory storage; actual LikeService reads with SQLite; date/DST primitives; actual CSV writer |
| aggregation-review-tests.php | Actual aggregation using a MySQL-to-SQLite test adapter, cleanup failure and total preservation |

These are functional/unit tests, not syntax checks. They do **not** certify real
MySQL SQL behavior, GET_LOCK, isolation/concurrency, Web cookies/HTTPS, HTTP CORS
headers/preflights, installer flows, or complete timezone archive/reset recovery.
Session/date primitive tests must not be reported as end-to-end admin/timezone tests.
The existing MySQL/HTTP/browser suites remain separate: several create/drop
databases or write fixtures. They are intentionally excluded and require a later
isolated test environment, not production credentials or an existing application DB.

For syntax validation, run `php -l` separately on tracked and newly added PHP files.
Record PHP version, SAPI, extensions, per-suite results and skips for each run.
A string such as `8.3.36` in a unit-test fixture does not mean PHP 8.3 was executed.

## RuntimePolicy contract

`require_once 'src/RuntimePolicy.php'`, then call `RuntimePolicy::current()` in
the process being inspected, or `RuntimePolicy::evaluate($version, $sapi)` in tests.
The class has no application dependencies, I/O, headers, session changes or exits.
It is not connected to bootstrap, admin, public API or the installer in stage 1.
Existing beta configuration/data is neither inspected nor rejected by loading it.

The returned array provides `status`, stable `reason_code`, a fixed English
`reason`, parsed core `version`/`branch`, `minimum_fixed_version`,
`supported_branch`, `meets_upstream_minimum`, `package_revision`,
`execution_context` and scope/security notices. UI translation should use reason
codes. Raw malformed input, paths and credentials are not included in reasons.

| Status | Meaning |
|---|---|
| supported | Branch 8.3/8.4/8.5 meets its upstream PHP2Shell floor (8.3.36/8.4.27/8.5.12); not a full security or compatibility certificate |
| backport_verification_required | Recognized numeric package revision below the upstream floor; independent official vendor evidence is required, never automatic approval |
| unsupported | Below-floor upstream version, unsupported branch, prerelease or unrecognized/malformed string |

Versions use bounded canonical `major.minor.patch` numbers with an optional
numeric package revision such as `-0ubuntu0.24.04.13`, `-1.el9` or Windows `-1`.
Comparison uses `version_compare` on the core version, not lexical comparison or
the vendor revision. Unknown suffixes are conservatively rejected; extending the
parser later requires documented package examples and tests. Prerelease markers
in package suffixes are also rejected. No trusted-vendor flag or bypass exists.

Vendors can backport without changing PHP_VERSION at all. Thus `unsupported` with
`below_upstream_minimum` means the string does not meet the upstream baseline,
not proof that the actual vendor binary is exploitable. Even `supported` cannot
prove package integrity, dependency fixes, all PHP2Shell-related settings or
future security updates. Recommend the latest security patch in each supported
branch; do not claim these historical floors remain the latest versions.

`cli`/`phpdbg` results describe CLI only; `cli-server` is Web, not CLI. Known Web
SAPIs are labeled Web; unknown SAPIs remain unknown. The SAPI never bypasses the
version policy. The Web worker must be inspected separately in a later installer
integration, without publishing phpinfo or adding a public diagnostic endpoint.

Future stages: official vendor evidence policy, Web preflight integration,
installation stopping rules, real-server compatibility, Windows build/dependency
checks, security notices and release-package allowlist integration. None is
implicitly enabled by this stage.
