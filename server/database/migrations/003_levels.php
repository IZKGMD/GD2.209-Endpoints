<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE levels (
    level_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(64) NOT NULL,
    description TEXT NULL,

    level_data LONGTEXT NOT NULL,

    level_version INT UNSIGNED NOT NULL DEFAULT 1,
    game_version INT UNSIGNED NOT NULL DEFAULT 22,

    length SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    difficulty SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    custom_difficulty SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    rate_tier SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    custom_rate_tier SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    downloads INT UNSIGNED NOT NULL DEFAULT 0,
    likes INT NOT NULL DEFAULT 0,

    stars SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    is_unlisted TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (level_id),

    KEY idx_levels_account (account_id),
    KEY idx_levels_difficulty (difficulty),
    KEY idx_levels_custom_difficulty (custom_difficulty),
    KEY idx_levels_rate (rate_tier),
    KEY idx_levels_created (created_at),

    CONSTRAINT fk_levels_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
