<?php

declare(strict_types=1);

/**
 * True 2.209 DM/group state. DM pairs are canonicalized so two concurrent
 * starts cannot create duplicate conversations.
 *
 * 1:1 DM participants are both full members. Group invitees remain pending
 * until they explicitly accept.
 */
return [
    <<<'SQL'
ALTER TABLE mucho_threads
    ADD COLUMN is_dm TINYINT(1) NOT NULL DEFAULT 0 AFTER title,
    ADD COLUMN dm_low BIGINT UNSIGNED NULL AFTER is_dm,
    ADD COLUMN dm_high BIGINT UNSIGNED NULL AFTER dm_low,
    ADD COLUMN description VARCHAR(1024) NOT NULL DEFAULT '' AFTER dm_high,
    ADD COLUMN emote VARCHAR(60) NOT NULL DEFAULT '' AFTER description,
    ADD COLUMN invite_owner_only TINYINT(1) NOT NULL DEFAULT 1 AFTER emote,
    ADD UNIQUE KEY uq_mucho_thread_dm_pair (dm_low, dm_high)
SQL,
    <<<'SQL'
ALTER TABLE mucho_thread_members
    ADD COLUMN is_muted TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN last_cleared_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_thread_former_members (
    thread_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(12) NOT NULL,
    left_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_visible_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (thread_id,account_id),
    CONSTRAINT fk_thread_former_thread FOREIGN KEY (thread_id)
        REFERENCES mucho_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_former_account FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
