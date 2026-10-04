# Security Design

-   HMAC-SHA-256
-   Secret Key generated using random_bytes(32)
-   Raw IP addresses are never stored.
-   After a successful web installation, Admin is locked while both the completion marker and the `install/` directory exist.
-   The entire `install/` directory must be deleted before normal Admin operation.
-   Valid configuration and admin credentials prevent installer reruns and overwrites.
