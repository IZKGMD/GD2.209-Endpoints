<?php
declare(strict_types=1);

/*
 * Endpoint hardening migration.
 * Creates runtime tables used by API v2/admin-client before the
 * administration UI has been opened for the first time.
 */

return static function (PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'admin',
            totp_secret VARCHAR(64) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_admin_client_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            client_version VARCHAR(32) NOT NULL DEFAULT '',
            created_ip_hash CHAR(64) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL DEFAULT NULL,
            KEY idx_mact_admin(admin_user_id),
            KEY idx_mact_expiry(expires_at),
            KEY idx_mact_revoked(revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_admin_client_login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_macl_ip_created(ip_hash, created_at),
            KEY idx_macl_username_created(username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_admin_client_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NULL,
            admin_username VARCHAR(64) NOT NULL,
            action VARCHAR(128) NOT NULL,
            target_type VARCHAR(64) NOT NULL DEFAULT '',
            target_id VARCHAR(128) NOT NULL DEFAULT '',
            details TEXT NULL,
            ip_hash CHAR(64) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_maca_created(created_at),
            KEY idx_maca_action(action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_feature_flags (
            flag_key VARCHAR(64) PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            description VARCHAR(255) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $defaults = [
        ['mucho_profiles', 1, 'Mucho Profiles'],
        ['custom_music', 1, 'Custom music system'],
        ['online_presence', 1, 'Player online presence'],
        ['android_host', 1, 'Mucho Android native host'],
        ['creator_tools', 0, 'Mucho creator tools'],
        ['experimental_features', 0, 'Experimental features'],
    ];

    $stmt = $db->prepare("
        INSERT IGNORE INTO mucho_feature_flags
            (flag_key, enabled, description)
        VALUES (?, ?, ?)
    ");

    foreach ($defaults as $row) {
        $stmt->execute($row);
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_client_releases (
            platform VARCHAR(32) PRIMARY KEY,
            current_version VARCHAR(32) NULL,
            minimum_version VARCHAR(32) NULL,
            download_url VARCHAR(500) NULL,
            sha256 VARCHAR(64) NULL,
            release_notes TEXT NULL,
            maintenance TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_profile_customization (
            account_id BIGINT UNSIGNED PRIMARY KEY,
            display_name VARCHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(48) NOT NULL DEFAULT '',
            bio VARCHAR(240) NOT NULL DEFAULT '',
            theme_primary VARCHAR(7) NOT NULL DEFAULT '#42D9CF',
            theme_secondary VARCHAR(7) NOT NULL DEFAULT '#806EFF',
            banner VARCHAR(64) NOT NULL DEFAULT 'gradient_01',
            title VARCHAR(48) NOT NULL DEFAULT '',
            badges TEXT NOT NULL,
            pinned_levels VARCHAR(96) NOT NULL DEFAULT '',
            showcase VARCHAR(200) NOT NULL DEFAULT 'stars,demons,creator_points',
            favorite_difficulty VARCHAR(32) NOT NULL DEFAULT '',
            online_visible TINYINT NOT NULL DEFAULT 1,
            edit_token_hash VARCHAR(64) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_profile_presence (
            account_id BIGINT UNSIGNED PRIMARY KEY,
            client_token_hash VARCHAR(64) NOT NULL DEFAULT '',
            last_seen TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_music_rate_limits (
            ip VARCHAR(45) PRIMARY KEY,
            last_upload_at DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
