<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    account_id BIGINT UNSIGNED NULL,

    action VARCHAR(128) NOT NULL,

    target_type VARCHAR(64) NULL,
    target_id BIGINT UNSIGNED NULL,

    ip_address VARCHAR(45) NULL,

    metadata JSON NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    KEY idx_audit_account (account_id),
    KEY idx_audit_action (action),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
