<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    actor_account_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(32) NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reward_claimed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mucho_notification_event
        (account_id, actor_account_id, notification_type, source_id),
    KEY idx_mucho_notifications_inbox (account_id, created_at, id),
    CONSTRAINT fk_mucho_notification_account FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_mucho_notification_actor FOREIGN KEY (actor_account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
