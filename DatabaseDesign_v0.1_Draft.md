# Pollyanna Database Design

Version: 0.1 Draft

------------------------------------------------------------------------

# 1. Design Policy

Pollyanna records **Like events**.

Aggregated values are **not** stored. Statistics are calculated from
recorded events.

Personally identifiable information is not stored.

Only the minimum data required for operation is retained.

------------------------------------------------------------------------

# 2. Data to Store

## Identifier

A string identifying the Like target.

### Specification

-   UTF-8 (utf8mb4)
-   Maximum 50 characters
-   Empty string not allowed
-   Leading/trailing whitespace removed before validation
-   Newlines not allowed
-   NULL characters not allowed

## created_at

Timestamp when the Like was submitted.

Used for daily, weekly, monthly and yearly aggregation.

## client_hash

Browser identifier generated from Cookie and LocalStorage.

Used for rate limiting.

## ip_hash

HMAC-SHA-256 hash of the client's IP address using an
installation-specific secret key.

Used only as a secondary measure against rate-limit bypass.

The original IP address is never stored.

------------------------------------------------------------------------

# 3. Data NOT Stored

-   Page title
-   URL
-   Referer
-   Raw IP address
-   Cookie value
-   LocalStorage value
-   User-Agent

------------------------------------------------------------------------

# Notes

This document is a draft and will be expanded with table definitions,
indexes and constraints.
