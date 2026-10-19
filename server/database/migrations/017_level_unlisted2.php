<?php

declare(strict_types=1);

return [
    <<<'SQL'
ALTER TABLE levels
    ADD COLUMN IF NOT EXISTS unlisted2 TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
    AFTER is_unlisted
SQL
];
