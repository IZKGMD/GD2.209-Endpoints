<?php

declare(strict_types=1);

/*
 * MuchoCore 1.0 V7.1 runtime schema.
 *
 * These tables are consumed by the protocol compatibility layer and must exist
 * on a clean installation; previously they were only created/checked by the
 * optional V7.1 migration helper.
 */
return static function(PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_lists (
    list_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    list_name VARCHAR(64) NOT NULL DEFAULT 'Unnamed list',
    list_desc VARCHAR(500) NOT NULL DEFAULT '',
    list_version INT UNSIGNED NOT NULL DEFAULT 1,
    level_ids TEXT NOT NULL,
    difficulty INT NOT NULL DEFAULT 0,
    original_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unlisted TINYINT(1) NOT NULL DEFAULT 0,
    downloads BIGINT UNSIGNED NOT NULL DEFAULT 0,
    likes BIGINT NOT NULL DEFAULT 0,
    featured INT NOT NULL DEFAULT 0,
    stars INT NOT NULL DEFAULT 0,
    count_for_reward INT NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (list_id),
    KEY idx_mucho_lists_account (account_id),
    KEY idx_mucho_lists_public_recent (unlisted, created_at),
    KEY idx_mucho_lists_downloads (downloads),
    KEY idx_mucho_lists_likes (likes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_scores (
    score_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    level_id BIGINT UNSIGNED NOT NULL,
    is_daily TINYINT(1) NOT NULL DEFAULT 0,
    daily_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    coins TINYINT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    play_time INT UNSIGNED NOT NULL DEFAULT 0,
    progresses LONGTEXT NOT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (score_id),
    UNIQUE KEY uq_mucho_level_score_account_level_daily
        (account_id, level_id, is_daily),
    KEY idx_mucho_level_scores_level (level_id, is_daily, percent),
    KEY idx_mucho_level_scores_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_platformer_scores (
    score_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    level_id BIGINT UNSIGNED NOT NULL,
    time_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    points BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    PRIMARY KEY (score_id),
    UNIQUE KEY uq_mucho_platformer_score_account_level
        (account_id, level_id),
    KEY idx_mucho_platformer_scores_level (level_id, time_ms, points),
    KEY idx_mucho_platformer_scores_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
