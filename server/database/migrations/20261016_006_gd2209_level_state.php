<?php

declare(strict_types=1);

/**
 * GD 2.209 persisted death-marker aggregation and level-owned control data.
 * Both tables delete with the parent level and never expose uploader secrets.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_deaths (
    level_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    level_version INT UNSIGNED NOT NULL,
    percent_basis_points INT UNSIGNED NOT NULL,
    x_bucket INT NOT NULL,
    y_bucket INT NOT NULL,
    deaths INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (level_id, account_id, level_version, percent_basis_points, x_bucket, y_bucket),
    KEY idx_mucho_deaths_level (level_id, level_version, percent_basis_points),
    CONSTRAINT fk_mucho_deaths_level FOREIGN KEY (level_id)
        REFERENCES levels(level_id) ON DELETE CASCADE,
    CONSTRAINT fk_mucho_deaths_account FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_controls (
    level_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    contents TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mucho_level_controls FOREIGN KEY (level_id)
        REFERENCES levels(level_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
