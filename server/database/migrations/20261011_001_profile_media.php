<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_profile_media (
    account_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('avatar','banner') NOT NULL,
    version CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    image_data MEDIUMBLOB NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id,kind),
    CONSTRAINT fk_mucho_profile_media_account FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
