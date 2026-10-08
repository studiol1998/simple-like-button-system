CREATE TABLE IF NOT EXISTS pollyanna_allowed_origins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scheme VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    host VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    include_subdomains TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_polyallowed_origin (scheme, host, port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
