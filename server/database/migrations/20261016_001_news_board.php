<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_news_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(140) NOT NULL,
    body TEXT NOT NULL,
    external_url VARCHAR(512) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    author_admin_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    KEY idx_news_published (is_published, is_pinned, published_at),
    KEY idx_news_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
