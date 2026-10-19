<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_user_appearance (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    nickname_color CHAR(6) NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mucho_appearance_account FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_clan_appearance (
    clan_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    prefix_color CHAR(6) NOT NULL DEFAULT 'FFD166',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mucho_appearance_clan FOREIGN KEY (clan_id)
        REFERENCES mucho_clans(clan_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
