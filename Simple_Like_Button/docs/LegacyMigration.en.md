# Legacy Beta Migration Notes

The previous PHP beta is preserved for reference in `../legacy/like_button_beta_package/`. New implementation work follows the v1.0 specification in the parent directory.

## Behaviors and components to consider

| Beta behavior or component | Direction for Pollyanna |
| --- | --- |
| Simple HTML embed and JavaScript widget | Keep the easy embed concept, use the opaque `identifier` defined by the specification, and handle communication errors. |
| Separate Like and count APIs | Keep the separation; define validation, HTTP methods, response formats, and status codes from the API specification. |
| Database operations in a service class | Keep database access in a service layer and follow the current data and privacy policies. |
| SQL installation | Keep database setup as an installation step, but use the current schema rather than the beta schema. |
| Enabled, maintenance, and disabled modes | Decide their behavior according to the current admin and settings specifications. |
| Six Likes per six hours | Treat this only as a beta default candidate and follow the finalized rate-limit specification. |

## Known beta limitations

- The beta uses `page_id` up to 255 characters; the current design limits `identifier` to 50 characters.
- It hashes IP addresses with plain SHA-256. The current design specifies HMAC with an installation-specific secret key.
- It applies a global IP-based limit and does not define client hashes or concurrent request behavior.
- The write API does not fully validate JSON or identifiers and does not check whether database insertion succeeded.
- The count API does not validate the identifier or HTTP method.
- The JavaScript does not safely encode identifiers in URLs and assumes successful responses.
- The config contains placeholder database credentials and has no secret-key generation or post-install security check.

Use the beta to recover intended behavior, then implement it against the current specifications. Do not deploy the preserved beta package as-is.
