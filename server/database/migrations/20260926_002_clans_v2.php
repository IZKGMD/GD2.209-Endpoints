<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_clan_bans (
    ban_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clan_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    banned_by_account_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(160) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (ban_id),
    UNIQUE KEY uq_mucho_clan_ban (clan_id, account_id),
    KEY idx_mucho_clan_bans_account (account_id, expires_at),

    CONSTRAINT fk_mucho_clan_bans_clan
        FOREIGN KEY (clan_id)
        REFERENCES mucho_clans(clan_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_mucho_clan_bans_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_mucho_clan_bans_actor
        FOREIGN KEY (banned_by_account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
