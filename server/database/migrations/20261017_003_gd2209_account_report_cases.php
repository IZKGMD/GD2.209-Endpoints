<?php

declare(strict_types=1);

/**
 * The 2.209 Moderation Queue groups abuse cases by the reported account,
 * not by a level ID. Preserve reports created before this migration.
 *
 * Unresolved content types remain NULL and will not be misattributed.
 */
return [
    <<<'SQL'
ALTER TABLE mucho_content_reports
ADD COLUMN target_account_id BIGINT UNSIGNED NULL AFTER content_id,
ADD KEY idx_mucho_reports_account_pending (target_account_id, status, id),
ADD CONSTRAINT fk_mucho_reports_target
    FOREIGN KEY (target_account_id)
    REFERENCES accounts(account_id) ON DELETE CASCADE
SQL,
    <<<'SQL'
UPDATE mucho_content_reports r
INNER JOIN levels l ON l.level_id = r.content_id
SET r.target_account_id = l.account_id
WHERE r.content_type = 0 AND r.target_account_id IS NULL
SQL,
];
