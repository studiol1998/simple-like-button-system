# Legacy Prototype Migration Notes

The previous beta package is preserved in `legacy/like_button_beta_package/`.
It is a source reference, not a production-ready release. The v1.0 design in
this directory takes precedence when behavior or data design differs.

## What carries forward

| Beta element | v1.0 destination | Migration direction |
| --- | --- | --- |
| `like.js` and `.like-area` widget | `assets/` and embedding/API docs | Keep the small embed model; use an opaque `identifier` instead of `page_id`, validate input, and handle API/network errors. |
| `api/like.php`, `api/count.php` | `api/` | Keep separate write and count endpoints; define response formats, method checks, and status codes in the API specification. |
| `LikeService.php` | `src/` | Keep database operations in a service; implement the configured privacy-preserving rate limit and identifier rules. |
| `sql/install.sql` | `install/` | Keep install-time table creation; replace the beta schema with the v1.0 schema and constraints. |
| `like_settings` modes | `settings` and admin design | Preserve enabled, maintenance, and disabled behavior, with validated settings and safe admin access. |
| six-hour / ten-like limit | `settings` | Treat as a candidate default only. The v1.0 settings model must define the limit window and count explicitly. |

## Gaps to resolve before reusing beta code

- Beta uses `page_id` and allows 255 characters; the v1.0 Phase 1 policy uses
  an `identifier` limited to 100 Unicode code points.
- Beta hashes the raw IP with plain SHA-256. The current design requires an
  installation-specific HMAC key and does not retain raw IP addresses.
- Beta rate-limits globally by IP, with no client hash, and does not define
  atomicity or concurrency behavior.
- The write API accepts loosely decoded JSON, does not validate identifier
  length or embedded control characters, and ignores the result of insertion.
- The count API does not validate the identifier or constrain request method.
- The JavaScript interpolates an unescaped identifier into a URL, assumes
  successful JSON responses, and increments a displayed count locally.
- The database draft has no foreign constraints or settings defaults, and its
  schema does not match the current v1.0 data model.
- The beta config contains placeholder database credentials and has no
  generated secret key or installation security check.

## Migration rule

Use the beta package to recover intended user-facing behavior and endpoint
responsibilities. Reimplement those behaviors against the v1.0 API, database,
and security documents. Do not deploy the preserved beta directory directly.
