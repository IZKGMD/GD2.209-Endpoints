<?php

declare(strict_types=1);

/*
 * Cvolton-compatible post-login authentication grants.
 *
 * Cvolton's sessionGrants mode remembers a successful account login for one
 * hour, bound to account ID and client IP, so subsequent legacy endpoints can
 * authenticate without requiring the client to resend a valid GJP every time.
 */
return static function(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_auth_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            account_id BIGINT UNSIGNED NOT NULL,

            ip_address VARCHAR(45) NOT NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            last_used_at TIMESTAMP NULL DEFAULT NULL,

            expires_at DATETIME NOT NULL,

            UNIQUE KEY uq_mucho_auth_session (account_id, ip_address),
            KEY idx_mucho_auth_session_expiry (expires_at),

            CONSTRAINT fk_mucho_auth_session_account
                FOREIGN KEY (account_id)
                REFERENCES accounts(account_id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");
};
