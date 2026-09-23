CREATE TABLE likes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_id VARCHAR(255) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(page_id),
    INDEX(created_at)
);

CREATE TABLE like_settings (
    id INT PRIMARY KEY,
    mode ENUM('enabled','maintenance','disabled') NOT NULL,
    message VARCHAR(255) NOT NULL
);

INSERT INTO like_settings VALUES
(1,'enabled','現在メンテナンス中です');
