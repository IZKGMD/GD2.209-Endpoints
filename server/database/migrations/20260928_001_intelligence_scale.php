<?php

declare(strict_types=1);

use MuchoCore\Database\Migration;
use PDO;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_search_index (
    level_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(20) NOT NULL DEFAULT '',
    normalized_name VARCHAR(128) NOT NULL DEFAULT '',
    normalized_creator VARCHAR(64) NOT NULL DEFAULT '',
    search_text VARCHAR(255) NOT NULL DEFAULT '',
    game_version INT UNSIGNED NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    is_unlisted TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (level_id),
    KEY idx_mlsi_name (normalized_name),
    KEY idx_mlsi_creator (normalized_creator),
    KEY idx_mlsi_account (account_id),
    KEY idx_mlsi_visibility (is_deleted, is_unlisted, updated_at),
    FULLTEXT KEY ftx_mlsi_search (search_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_level_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level_id BIGINT UNSIGNED NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    payload_gzip LONGBLOB NULL,
    snapshot_json LONGTEXT NOT NULL,
    changed_by_account_id BIGINT UNSIGNED NULL,
    reason VARCHAR(64) NOT NULL DEFAULT 'save',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mlrev_level_revision (level_id, revision_no),
    KEY idx_mlrev_level_created (level_id, created_at),
    KEY idx_mlrev_hash (payload_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_type VARCHAR(96) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL DEFAULT NULL,
    worker_id VARCHAR(96) NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mjobs_queue (status, available_at, id),
    KEY idx_mjobs_worker (worker_id, status),
    KEY idx_mjobs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_cache (
    cache_key VARCHAR(191) NOT NULL,
    cache_value LONGTEXT NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cache_key),
    KEY idx_mcache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_backup_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_name VARCHAR(255) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NOT NULL DEFAULT '',
    gzip_valid TINYINT(1) NOT NULL DEFAULT 0,
    sql_valid TINYINT(1) NOT NULL DEFAULT 0,
    verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mbver_file (file_name),
    KEY idx_mbver_time (verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        try {
            $pdo->exec(<<<'SQL'
INSERT INTO mucho_level_search_index
    (
        level_id,
        account_id,
        username,
        normalized_name,
        normalized_creator,
        search_text,
        game_version,
        is_deleted,
        is_unlisted
    )
SELECT
    l.level_id,
    l.account_id,
    LEFT(COALESCE(a.username, ''), 20),
    LOWER(TRIM(REPLACE(REPLACE(l.name, CHAR(9), ' '), CHAR(10), ' '))),
    LOWER(TRIM(COALESCE(a.username, ''))),
    LEFT(LOWER(TRIM(CONCAT(COALESCE(l.name, ''), ' ', COALESCE(a.username, '')))), 255),
    l.game_version,
    l.is_deleted,
    l.is_unlisted
FROM levels l
LEFT JOIN accounts a ON a.account_id = l.account_id
ON DUPLICATE KEY UPDATE
    account_id = VALUES(account_id),
    username = VALUES(username),
    normalized_name = VALUES(normalized_name),
    normalized_creator = VALUES(normalized_creator),
    search_text = VALUES(search_text),
    game_version = VALUES(game_version),
    is_deleted = VALUES(is_deleted),
    is_unlisted = VALUES(is_unlisted)
SQL);
        } catch (Throwable) {
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mucho_backup_verifications');
        $pdo->exec('DROP TABLE IF EXISTS mucho_cache');
        $pdo->exec('DROP TABLE IF EXISTS mucho_jobs');
        $pdo->exec('DROP TABLE IF EXISTS mucho_level_revisions');
        $pdo->exec('DROP TABLE IF EXISTS mucho_level_search_index');
    }
};
