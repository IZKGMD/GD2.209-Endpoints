<?php

declare(strict_types=1);

/*
 * MuchoCore administrator access-key credentials.
 *
 * Access keys are password-equivalent credentials intended for fast admin
 * sign-in. Only a one-way hash is persisted; the raw key is shown once when
 * generated. TOTP, when enabled, is still required.
 */
return static function(PDO $db): void {
    $exists=(int)$db->query(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name='admin_users'"
    )->fetchColumn();

    if ($exists===0) {
        return;
    }

    $columns = $db->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
    $hasHash = false;
    $hasCreated = false;

    foreach ($columns as $column) {
        $name = (string)($column['Field'] ?? '');
        if ($name === 'access_key_hash') $hasHash = true;
        if ($name === 'access_key_created_at') $hasCreated = true;
    }

    if (!$hasHash) {
        $db->exec(
            "ALTER TABLE admin_users
             ADD COLUMN access_key_hash VARCHAR(255) NULL AFTER totp_secret"
        );
    }

    if (!$hasCreated) {
        $db->exec(
            "ALTER TABLE admin_users
             ADD COLUMN access_key_created_at TIMESTAMP NULL
             AFTER access_key_hash"
        );
    }
};
