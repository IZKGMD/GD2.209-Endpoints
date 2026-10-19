<?php

declare(strict_types=1);

return static function(PDO $db): void {
    $db->exec('
        CREATE TABLE IF NOT EXISTS mucho_cvolton_account_map (
            source_id BIGINT NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            source_username VARCHAR(69) NOT NULL DEFAULT "",
            needs_password_reset TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (source_id),
            UNIQUE KEY uq_mcam_target (target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $db->exec('
        CREATE TABLE IF NOT EXISTS mucho_cvolton_level_map (
            source_id BIGINT NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            source_name VARCHAR(255) NOT NULL DEFAULT "",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (source_id),
            UNIQUE KEY uq_mclm_target (target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
};
