<?php

declare(strict_types=1);

/*
 * MuchoCore content collections: Gauntlets + Map Packs.
 *
 * These tables back the existing Geometry Dash discovery endpoints:
 * getGJGauntlets* and getGJMapPacks*.
 *
 * Copyright (C) 2026 IZK
 */
return static function(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_gauntlets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(96) NOT NULL DEFAULT 'Gauntlet',
            level1 BIGINT UNSIGNED NOT NULL,
            level2 BIGINT UNSIGNED NOT NULL,
            level3 BIGINT UNSIGNED NOT NULL,
            level4 BIGINT UNSIGNED NOT NULL,
            level5 BIGINT UNSIGNED NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mucho_gauntlets_order (enabled, sort_order, id)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        ALTER TABLE mucho_gauntlets
            ADD COLUMN IF NOT EXISTS name VARCHAR(96) NOT NULL DEFAULT 'Gauntlet',
            ADD COLUMN IF NOT EXISTS enabled TINYINT(1) NOT NULL DEFAULT 1,
            ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_map_packs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            levels TEXT NOT NULL,
            stars SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            coins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            difficulty TINYINT NOT NULL DEFAULT 0,
            color1 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            color2 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mucho_map_packs_order (enabled, sort_order, id)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        ALTER TABLE mucho_map_packs
            ADD COLUMN IF NOT EXISTS name VARCHAR(64) NOT NULL DEFAULT 'Map Pack',
            ADD COLUMN IF NOT EXISTS levels VARCHAR(96) NOT NULL DEFAULT '',
            ADD COLUMN IF NOT EXISTS stars SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS coins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS difficulty TINYINT NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS color1 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS color2 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS enabled TINYINT(1) NOT NULL DEFAULT 1,
            ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
    ");
};
