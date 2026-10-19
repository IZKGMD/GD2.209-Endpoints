<?php

declare(strict_types=1);

/**
 * Native GD 2.209 message-thread persistence. All message reads go through
 * mucho_thread_members, not through a caller-provided account ID alone.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_threads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_account_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    is_closed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_threads_owner (owner_account_id, updated_at),
    CONSTRAINT fk_mucho_threads_owner FOREIGN KEY (owner_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_thread_members (
    thread_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (thread_id, account_id),
    KEY idx_thread_members_user (account_id, thread_id),
    CONSTRAINT fk_thread_members_thread FOREIGN KEY (thread_id)
        REFERENCES mucho_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_members_account FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_thread_invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    thread_id BIGINT UNSIGNED NOT NULL,
    inviter_account_id BIGINT UNSIGNED NOT NULL,
    invitee_account_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_thread_invite_peer (thread_id, invitee_account_id),
    KEY idx_thread_invites_recipient (invitee_account_id, status, id),
    CONSTRAINT fk_thread_invite_thread FOREIGN KEY (thread_id)
        REFERENCES mucho_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_invite_from FOREIGN KEY (inviter_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_invite_to FOREIGN KEY (invitee_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_thread_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    thread_id BIGINT UNSIGNED NOT NULL,
    sender_account_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_thread_message_page (thread_id, id),
    KEY idx_thread_message_sender (sender_account_id),
    CONSTRAINT fk_thread_message_thread FOREIGN KEY (thread_id)
        REFERENCES mucho_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_thread_message_sender FOREIGN KEY (sender_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
