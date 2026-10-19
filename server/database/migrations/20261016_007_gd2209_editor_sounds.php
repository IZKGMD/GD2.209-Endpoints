<?php

declare(strict_types=1);

/**
 * GD 2.209 editor audio. Binary audio is kept inside DB, not webroot.
 * Global content size and per-account quotas enforced in SoundService.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_editor_sounds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    description VARCHAR(512) NOT NULL DEFAULT '',
    mime_type VARCHAR(32) NOT NULL,
    audio_bytes MEDIUMBLOB NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mucho_editor_sound_account_hash (account_id,sha256),
    KEY idx_mucho_editor_sounds_account (account_id, id),
    KEY idx_mucho_editor_sounds_recent (id),
    CONSTRAINT fk_mucho_editor_sound_owner FOREIGN KEY (account_id)
        REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
