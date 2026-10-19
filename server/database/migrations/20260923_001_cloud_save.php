<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_cloud_saves (
    account_id BIGINT UNSIGNED NOT NULL,

    ciphertext LONGBLOB NOT NULL,
    nonce BINARY(12) NOT NULL,
    auth_tag BINARY(16) NOT NULL,

    sha256 CHAR(64) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    revision INT UNSIGNED NOT NULL DEFAULT 1,

    backed_up_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (account_id),

    KEY idx_mcs_backed_up_at (backed_up_at),

    CONSTRAINT fk_mcs_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_cloud_save_revisions (
    history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,

    revision INT UNSIGNED NOT NULL,

    ciphertext LONGBLOB NOT NULL,
    nonce BINARY(12) NOT NULL,
    auth_tag BINARY(16) NOT NULL,

    sha256 CHAR(64) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,

    original_backed_up_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (history_id),

    UNIQUE KEY uq_mcshr_account_revision (account_id, revision),
    KEY idx_mcshr_account_revision (account_id, revision DESC),
    KEY idx_mcshr_created_at (created_at),

    CONSTRAINT fk_mcshr_account
        FOREIGN KEY (account_id)
        REFERENCES accounts(account_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
