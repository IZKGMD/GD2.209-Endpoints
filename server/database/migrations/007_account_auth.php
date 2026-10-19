<?php

declare(strict_types=1);

return [
    <<<'SQL'
ALTER TABLE accounts
    ADD COLUMN gjp2_hash VARCHAR(255) NULL AFTER password_hash,
    ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER is_banned
SQL,
];
