<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE custom_difficulties (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,

    code VARCHAR(32) NOT NULL,
    display_name VARCHAR(64) NOT NULL,

    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    asset_key VARCHAR(128) NULL,

    enabled TINYINT(1) NOT NULL DEFAULT 1,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_custom_difficulty_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
INSERT INTO custom_difficulties
(code, display_name, sort_order, asset_key)
VALUES
('nightmare', 'Nightmare', 100, 'difficulty_nightmare'),
('impossible', 'Impossible', 200, 'difficulty_impossible'),
('unhuman', 'Unhuman', 300, 'difficulty_unhuman')
SQL,
];
