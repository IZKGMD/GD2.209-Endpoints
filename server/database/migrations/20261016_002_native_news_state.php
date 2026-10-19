<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_news_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    version BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
INSERT IGNORE INTO mucho_news_state (id, version) VALUES (1, 1000000)
SQL,
];
