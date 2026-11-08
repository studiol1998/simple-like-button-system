# Origin and CORS design (v1.0 Phase 2)

The public widget APIs, `api/state.php` and `api/like.php`, require an `Origin` header. Missing, null, malformed, or unauthorized origins receive 403. Referer is never used as a fallback. This policy does not apply to Admin or the installer.

The primary site group is configured with `site_origin`, `site_base_domain`, and `site_base_domain_include_subdomains`. External sites are stored in `pollyanna_allowed_origins` with scheme, host, effective port, and the subdomain option. Scheme and port must match. A host matches exactly or, when enabled, ends with a dot followed by the allowed host. Subdomain matching is disabled for localhost and IP addresses.

Only HTTP and HTTPS origins are accepted. Scheme and DNS host are lowercased and default ports are omitted when serialized. Paths, queries, fragments, and credentials are rejected. Authorized cross-origin responses echo the specific allowed origin and include `Vary: Origin`; wildcard and credentialed CORS are not used.

OPTIONS validates the origin and only accepts POST with Content-Type as the requested header, returning 204. POST validates the origin again. After a Like succeeds, the page URL origin is checked independently. An unauthorized page URL is logged and skipped without undoing the Like.

Existing installations run `install/upgrade-allowed-origins.sql`, then save the primary origin and base domain in Admin. Run `tests/phase2-origin-tests.php` for unit checks and `tests/phase2-db-tests.php` for database checks.