<?php

declare(strict_types=1);

return [
    <<<'SQL'
ALTER TABLE levels
    ADD COLUMN binary_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER game_version,
    ADD COLUMN copy_password VARCHAR(64) NOT NULL DEFAULT '0' AFTER level_data,
    ADD COLUMN extra_string VARCHAR(255) NOT NULL
        DEFAULT '29_29_29_40_29_29_29_29_29_29_29_29_29_29_29_29'
        AFTER copy_password,
    ADD COLUMN level_info TEXT NULL AFTER extra_string,
    ADD COLUMN settings_string TEXT NULL AFTER level_info,
    ADD COLUMN song_ids TEXT NULL AFTER settings_string,
    ADD COLUMN sfx_ids TEXT NULL AFTER song_ids,
    ADD COLUMN wt INT NOT NULL DEFAULT 0 AFTER sfx_ids,
    ADD COLUMN wt2 INT NOT NULL DEFAULT 0 AFTER wt,
    ADD COLUMN ts BIGINT NOT NULL DEFAULT 0 AFTER wt2
SQL,

    <<<'SQL'
CREATE TABLE level_download_events (
    level_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (level_id, ip_address),

    CONSTRAINT fk_level_download_level
        FOREIGN KEY (level_id)
        REFERENCES levels(level_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
