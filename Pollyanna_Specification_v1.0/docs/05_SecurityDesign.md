# Security Design

-   HMAC-SHA-256
-   Secret Key generated using random_bytes(32)
-   Raw IP addresses are never stored.
-   Security Mode enabled while install directory exists.
-   Security Check required before normal operation.
