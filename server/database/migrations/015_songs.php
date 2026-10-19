<?php

declare(strict_types=1);

use MuchoCore\Database\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS songs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(128) NOT NULL,
                author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                author_name VARCHAR(128) NOT NULL,
                size FLOAT NOT NULL DEFAULT 0.0,
                download_url VARCHAR(255) NOT NULL,
                youtube_video_id VARCHAR(32) DEFAULT "",
                youtube_channel_id VARCHAR(64) DEFAULT "",
                is_verified TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS songs;');
    }
};
