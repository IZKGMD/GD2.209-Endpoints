<?php

declare(strict_types=1);

/**
 * Independent GD 2.209 content reports. Never assume that a moderator's
 * level-rating suggestions are user abuse reports.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_content_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reporter_account_id BIGINT UNSIGNED NOT NULL,
    content_type TINYINT UNSIGNED NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    reason TINYINT UNSIGNED NOT NULL DEFAULT 0,
    details VARCHAR(500) NOT NULL DEFAULT '',
    status ENUM('pending','closed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mucho_content_report_identity (
        reporter_account_id, content_type, content_id
    ),
    KEY idx_mucho_content_reports_queue (status, id),
    KEY idx_mucho_content_reports_content (content_type, content_id, status),
    CONSTRAINT fk_mucho_content_report_reporter
      FOREIGN KEY (reporter_account_id)
      REFERENCES accounts(account_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
