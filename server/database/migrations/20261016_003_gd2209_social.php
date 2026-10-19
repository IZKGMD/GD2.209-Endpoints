<?php

declare(strict_types=1);

/**
 * Geometry Dash 2.209 social foundations.
 * Foreign keys prohibit orphan follower relations after account deletion.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_follows (
    follower_account_id BIGINT UNSIGNED NOT NULL,
    followed_account_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_account_id, followed_account_id),
    KEY idx_mucho_follows_target (followed_account_id, created_at),
    CONSTRAINT fk_mucho_follows_from FOREIGN KEY (follower_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_mucho_follows_to FOREIGN KEY (followed_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_muted_users (
    account_id BIGINT UNSIGNED NOT NULL,
    muted_account_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id, muted_account_id),
    KEY idx_mucho_muted_target (muted_account_id),
    CONSTRAINT fk_mucho_muted_from FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_mucho_muted_to FOREIGN KEY (muted_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
