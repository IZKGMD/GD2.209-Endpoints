<?php

declare(strict_types=1);

/**
 * Auditable 2.209 moderation case lifecycle.
 * No shadow account-role/ban tables; existing account roles stay authoritative.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_gd2209_case_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_account_id BIGINT UNSIGNED NOT NULL,
    target_account_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(32) NOT NULL,
    detail INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_2209_case_target_time (target_account_id, id),
    INDEX idx_2209_case_actor (actor_account_id, id),
    CONSTRAINT fk_2209_case_actor FOREIGN KEY (actor_account_id)
      REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_2209_case_target FOREIGN KEY (target_account_id)
      REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
