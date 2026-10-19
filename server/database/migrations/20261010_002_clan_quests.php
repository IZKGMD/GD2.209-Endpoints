<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_clan_quests (
    quest_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clan_id BIGINT UNSIGNED NOT NULL,
    metric ENUM('stars','demons') NOT NULL,
    target INT UNSIGNED NOT NULL,
    progress BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_clan_quests_status (clan_id,status,expires_at),
    CONSTRAINT fk_clan_quests_clan FOREIGN KEY (clan_id) REFERENCES mucho_clans(clan_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_clan_quest_members (
    quest_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    last_score BIGINT UNSIGNED NOT NULL,
    contribution BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (quest_id,account_id),
    CONSTRAINT fk_clan_quest_members_quest FOREIGN KEY (quest_id) REFERENCES mucho_clan_quests(quest_id) ON DELETE CASCADE,
    CONSTRAINT fk_clan_quest_members_account FOREIGN KEY (account_id) REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_clan_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clan_id BIGINT UNSIGNED NOT NULL,
    message VARCHAR(200) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_clan_events_page (clan_id,event_id),
    CONSTRAINT fk_clan_events_clan FOREIGN KEY (clan_id) REFERENCES mucho_clans(clan_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
