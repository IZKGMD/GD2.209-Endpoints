<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE server_settings (
    setting_key VARCHAR(128) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (setting_key)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
INSERT INTO server_settings
(setting_key, setting_value)
VALUES
('server.name', 'MuchoGDPS'),
('server.version', '0.5.0-dev'),
('registration.enabled', '1'),
('levels.upload.enabled', '1'),
('maintenance.enabled', '0')
SQL,

    <<<'SQL'
CREATE TABLE ip_rate_limits (
    bucket_key VARCHAR(191) NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (bucket_key)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL
];
