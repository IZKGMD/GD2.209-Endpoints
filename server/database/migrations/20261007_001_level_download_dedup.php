<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_downloads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_id BIGINT NOT NULL,
    client_hash CHAR(64) NOT NULL,
    slot TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mucho_level_download_client_slot
        (level_id, client_hash, slot),
    KEY idx_mucho_level_download_level (level_id),
    KEY idx_mucho_level_download_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL
    );
};
