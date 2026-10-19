<?php

declare(strict_types=1);

/*
 * Retire the obsolete duplicate game-role storage.
 *
 * The canonical role source is:
 * accounts.role_id -> roles.code
 *
 * Older installations may still contain mucho_account_roles. Rename it
 * instead of deleting it so its legacy verification data remains recoverable.
 */
return static function (PDO $db): void {
    $legacy = 'mucho_account_roles';
    $archive = 'mucho_account_roles_legacy_20260923';

    $exists = (int)$db->query(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = '" . $legacy . "'"
    )->fetchColumn();

    if ($exists === 0) {
        return;
    }

    $archiveExists = (int)$db->query(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = '" . $archive . "'"
    )->fetchColumn();

    if ($archiveExists === 0) {
        $db->exec(
            'RENAME TABLE `' . $legacy . '` TO `' . $archive . '`'
        );
    }
};
