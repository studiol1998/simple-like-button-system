CREATE TABLE IF NOT EXISTS pollyanna_access_buckets (
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    api ENUM('state', 'like') NOT NULL,
    bucket_at DATETIME NOT NULL,
    request_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
    reported TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (ip_hash, api, bucket_at),
    KEY idx_polyaccess_bucket_expiry (api, bucket_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS pollyanna_access_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    detected_at DATETIME NOT NULL,
    api ENUM('state', 'like') NOT NULL,
    window_seconds INT UNSIGNED NOT NULL,
    request_count BIGINT UNSIGNED NOT NULL,
    blocked TINYINT UNSIGNED NOT NULL,
    block_started_at DATETIME NULL,
    released_at DATETIME NULL,
    release_method ENUM('automatic', 'manual') NULL,
    PRIMARY KEY (id),
    KEY idx_polyaccess_history_expiry (detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS pollyanna_access_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    started_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    history_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_polyaccess_block_ip (ip_hash),
    KEY idx_polyaccess_block_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
