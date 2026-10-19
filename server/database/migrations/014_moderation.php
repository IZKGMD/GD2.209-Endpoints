<?php

declare(strict_types=1);

use MuchoCore\Database\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $checkCol = $pdo->query("SHOW COLUMNS FROM accounts LIKE 'role'")->fetch();
        if (!$checkCol) {
            $pdo->exec("ALTER TABLE accounts ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'user' AFTER email");
        }

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS moderation_suggestions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                stars TINYINT UNSIGNED NOT NULL,
                feature_tier TINYINT UNSIGNED NOT NULL DEFAULT 0,
                coins_verified TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_level_id (level_id),
                INDEX idx_account_id (account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS moderation_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                moderator_account_id BIGINT UNSIGNED NOT NULL,
                level_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(64) NOT NULL,
                details JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_mod_level (moderator_account_id, level_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS moderation_logs;');
        $pdo->exec('DROP TABLE IF EXISTS moderation_suggestions;');
    }
};
