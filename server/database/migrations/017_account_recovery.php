<?php

declare(strict_types=1);

use MuchoCore\Database\Migration;
use PDO;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS account_recovery_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_ip_hash CHAR(64) NOT NULL DEFAULT "",
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

                UNIQUE KEY uq_art_token_hash (token_hash),
                KEY idx_art_account (account_id),
                KEY idx_art_expiry (expires_at),
                KEY idx_art_used (used_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec(
            'DROP TABLE IF EXISTS account_recovery_tokens;'
        );
    }
};
