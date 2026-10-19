<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_clans (
    clan_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(24) NOT NULL,
    tag VARCHAR(6) NOT NULL,
    description VARCHAR(160) NOT NULL DEFAULT '',
    owner_account_id BIGINT UNSIGNED NOT NULL,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    max_members SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (clan_id),
    UNIQUE KEY uq_mucho_clans_name (name),
    UNIQUE KEY uq_mucho_clans_tag (tag),
    KEY idx_mucho_clans_owner (owner_account_id),

    CONSTRAINT fk_mucho_clans_owner
        FOREIGN KEY (owner_account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_clan_members (
    clan_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'officer', 'member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (clan_id, account_id),
    UNIQUE KEY uq_mucho_clan_member_account (account_id),
    KEY idx_mucho_clan_members_clan_role (clan_id, role),

    CONSTRAINT fk_mucho_clan_members_clan
        FOREIGN KEY (clan_id)
        REFERENCES mucho_clans(clan_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_mucho_clan_members_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_clan_invites (
    invite_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clan_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    invited_by_account_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,

    PRIMARY KEY (invite_id),
    UNIQUE KEY uq_mucho_clan_invite (clan_id, account_id),
    KEY idx_mucho_clan_invites_account (account_id, expires_at),

    CONSTRAINT fk_mucho_clan_invites_clan
        FOREIGN KEY (clan_id)
        REFERENCES mucho_clans(clan_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_mucho_clan_invites_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_mucho_clan_invites_inviter
        FOREIGN KEY (invited_by_account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
