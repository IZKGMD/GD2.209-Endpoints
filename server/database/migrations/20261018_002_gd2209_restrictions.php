<?php

declare(strict_types=1);

/**
 * GD 2.209 restriction state, distinct from permanent account banning.
 * All changes are recorded in mucho_gd2209_case_actions.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_gd2209_restrictions (
    account_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(24) NOT NULL,
    expires_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    prior_is_active TINYINT(1) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id, kind),
    KEY idx_2209_restrictions_expiration (kind, expires_at),
    CONSTRAINT fk_2209_restriction_target FOREIGN KEY (account_id)
      REFERENCES accounts(account_id) ON DELETE CASCADE,
    CONSTRAINT fk_2209_restriction_actor FOREIGN KEY (created_by)
      REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
