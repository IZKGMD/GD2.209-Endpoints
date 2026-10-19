<?php

declare(strict_types=1);

/*
 * Legacy Geometry Dash 1.9 upload sessions.
 *
 * Some 1.9 clients authenticate successfully but omit gjp on subsequent
 * level-upload requests. The session is bound to account, device UDID and
 * source IP, expires after one hour, and stores only a password hash.
 */
return static function(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_legacy_19_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            account_id BIGINT UNSIGNED NOT NULL,

            udid_hash VARCHAR(255) NOT NULL,

            ip_address VARCHAR(45) NOT NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            last_used_at TIMESTAMP NULL DEFAULT NULL,

            expires_at DATETIME NOT NULL,

            KEY idx_ml19_account_ip (account_id, ip_address),
            KEY idx_ml19_expiry (expires_at),

            CONSTRAINT fk_ml19_account
                FOREIGN KEY (account_id)
                REFERENCES accounts(account_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};
