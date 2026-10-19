<?php

declare(strict_types=1);

use MuchoCore\Database\Migration;
use PDO;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS mucho_score_integrity_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                score_type VARCHAR(32) NOT NULL,
                score_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                level_id BIGINT UNSIGNED NOT NULL,
                risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT "trusted",
                reasons TEXT NULL,
                metrics TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_msie_score (score_type, score_id),
                KEY idx_msie_risk (status, risk_score),
                KEY idx_msie_account (account_id, created_at),
                KEY idx_msie_level (level_id, created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mucho_score_integrity_events;');
    }
};
