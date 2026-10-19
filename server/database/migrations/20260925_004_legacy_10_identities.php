<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE legacy_device_identities (
    udid_hash CHAR(64) NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    last_ip VARBINARY(16) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (udid_hash),
    UNIQUE KEY uq_legacy_device_account (account_id),

    CONSTRAINT fk_legacy_device_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];