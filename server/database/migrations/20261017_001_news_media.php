<?php
declare(strict_types=1);

return [<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_news_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('image','youtube') NOT NULL,
    filename VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    target_url VARCHAR(512) NULL,
    image_data MEDIUMBLOB NOT NULL,
    KEY idx_news_media_post (post_id,id),
    KEY idx_news_media_filename (filename),
    CONSTRAINT fk_news_media_post FOREIGN KEY (post_id)
        REFERENCES mucho_news_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL];
