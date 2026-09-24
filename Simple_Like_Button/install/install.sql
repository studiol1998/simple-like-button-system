CREATE TABLE IF NOT EXISTS pollyanna_likes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier VARCHAR(50) NOT NULL,
    client_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_polylikes_identifier_created_at (identifier, created_at),
    KEY idx_polylikes_client_hash_created_at (client_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
