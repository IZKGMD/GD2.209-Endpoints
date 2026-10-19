<?php

declare(strict_types=1);

return [
    <<<'SQL'
ALTER TABLE mucho_branding
    ADD COLUMN IF NOT EXISTS server_by_name VARCHAR(64) NULL AFTER server_name
SQL,

    <<<'SQL'
ALTER TABLE mucho_branding
    ADD COLUMN IF NOT EXISTS social_url VARCHAR(512) NULL AFTER server_by_name
SQL,
];
