CREATE TABLE IF NOT EXISTS pollyanna_likes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier VARCHAR(50) NOT NULL,
    client_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_polylikes_identifier_created_at (identifier, created_at),
    KEY idx_polylikes_client_hash_created_at (client_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS pollyanna_rate_limit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier VARCHAR(50) NOT NULL,
    client_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_polyrate_identifier_client_created (identifier, client_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS pollyanna_lifetime_totals (
    identifier VARCHAR(50) NOT NULL,
    total_likes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS pollyanna_period_aggregates (
    period_type ENUM('day', 'month', 'year') NOT NULL,
    period_start DATE NOT NULL,
    identifier VARCHAR(50) NOT NULL,
    like_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (period_type, period_start, identifier),
    KEY idx_polyperiod_identifier (identifier, period_type, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS pollyanna_hourly_aggregates (
    month_start DATE NOT NULL,
    hour_of_day TINYINT UNSIGNED NOT NULL,
    like_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (month_start, hour_of_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS pollyanna_identifier_urls (
    identifier VARCHAR(50) NOT NULL,
    url_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    canonical_url TEXT NOT NULL,
    first_seen_at DATETIME(6) NOT NULL,
    last_seen_at DATETIME(6) NOT NULL,
    PRIMARY KEY (identifier, url_hash),
    KEY idx_polyidentifier_urls_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
