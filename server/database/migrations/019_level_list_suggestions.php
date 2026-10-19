<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_list_suggestions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_level_list_suggestion (list_id, account_id),
    KEY idx_level_list_suggestion_list (list_id),
    KEY idx_level_list_suggestion_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
];
