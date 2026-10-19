<?php
declare(strict_types=1);

/*
 * MuchoCore v3.0
 * Copyright (C) 2026 IZK
 */

return static function(PDO $db): void {

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_api_rate_limits (
            bucket_key CHAR(64) PRIMARY KEY,
            window_start BIGINT NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_api_metrics_minute (
            minute_start DATETIME NOT NULL,
            route VARCHAR(160) NOT NULL,
            method VARCHAR(12) NOT NULL,
            status SMALLINT UNSIGNED NOT NULL,
            requests BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,

            PRIMARY KEY(
                minute_start,
                route,
                method,
                status
            )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_security_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            event_type VARCHAR(64) NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            route VARCHAR(255) NOT NULL DEFAULT '',
            request_id VARCHAR(64) NOT NULL DEFAULT '',
            metadata TEXT NULL,

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            KEY idx_security_time(created_at),
            KEY idx_security_type(event_type),
            KEY idx_security_ip(ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mucho_system_alerts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

            severity ENUM(
                'info',
                'warning',
                'critical'
            ) NOT NULL DEFAULT 'warning',

            source VARCHAR(64) NOT NULL,
            message VARCHAR(500) NOT NULL,

            resolved TINYINT(1) NOT NULL DEFAULT 0,

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL DEFAULT NULL,

            KEY idx_alert_resolved(resolved),
            KEY idx_alert_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
};
