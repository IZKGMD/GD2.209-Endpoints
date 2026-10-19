<?php

declare(strict_types=1);

use MuchoCore\Database\Migration;
use PDO;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS admin_recovery_codes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_user_id BIGINT UNSIGNED NOT NULL,
                code_hash VARCHAR(255) NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_arc_admin (admin_user_id),
                KEY idx_arc_used (used_at),
                CONSTRAINT fk_arc_admin
                    FOREIGN KEY (admin_user_id)
                    REFERENCES admin_users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_recovery_codes;');
    }
};
