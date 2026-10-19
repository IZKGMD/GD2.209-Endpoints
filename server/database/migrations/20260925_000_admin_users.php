<?php

declare(strict_types=1);

/*
 * Canonical administrator table bootstrap.
 *
 * This migration must run before the later administrator migrations. Older
 * installations created admin_users lazily from public/admin/index.php, which
 * meant a clean installation could reach a later migration before the table
 * existed.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'admin',
    totp_secret VARCHAR(64) NULL,
    access_key_hash VARCHAR(255) NULL,
    access_key_created_at TIMESTAMP NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
