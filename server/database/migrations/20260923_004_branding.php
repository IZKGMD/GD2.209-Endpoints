<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_branding (
    id TINYINT UNSIGNED NOT NULL,
    server_name VARCHAR(64) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
INSERT INTO mucho_branding
    (id,server_name)
VALUES
    (1,'Mucho GDPS')
ON DUPLICATE KEY UPDATE
    server_name=server_name
SQL,
];
