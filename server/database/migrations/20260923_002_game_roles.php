<?php

declare(strict_types=1);

/*
 * Normalize MuchoCore game roles to the Geometry Dash moderator model:
 * user, moderator, elder_moderator, owner.
 *
 * Existing "admin" game roles are migrated to elder_moderator.
 * Existing "helper" game roles are migrated to moderator.
 */
return static function(PDO $db): void {
    $db->exec("
        INSERT INTO roles (code, name, priority)
        VALUES
            ('elder_moderator', 'Elder Moderator', 75)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            priority = VALUES(priority)
    ");

    $db->exec("
        UPDATE accounts a
        LEFT JOIN roles existing_role
            ON existing_role.id = a.role_id
        INNER JOIN roles canonical_role
            ON canonical_role.code = CASE
                WHEN LOWER(TRIM(COALESCE(a.role, ''))) IN
                    ('admin', 'elder', 'developer')
                    THEN 'elder_moderator'
                WHEN LOWER(TRIM(COALESCE(a.role, ''))) IN
                    ('helper', 'mod')
                    THEN 'moderator'
                WHEN LOWER(TRIM(COALESCE(a.role, ''))) = 'player'
                    THEN 'user'
                WHEN LOWER(TRIM(COALESCE(a.role, ''))) = 'user'
                    THEN 'user'
                ELSE existing_role.code
            END
        SET a.role_id = canonical_role.id
        WHERE a.role IS NOT NULL
          AND (
              existing_role.id IS NULL
              OR existing_role.code IS NULL
              OR existing_role.code = 'user'
          )
    ");

    $db->exec("
        UPDATE accounts a
        INNER JOIN roles old_role
            ON old_role.id = a.role_id
        INNER JOIN roles new_role
            ON new_role.code = CASE old_role.code
                WHEN 'admin' THEN 'elder_moderator'
                WHEN 'helper' THEN 'moderator'
                ELSE old_role.code
            END
        SET a.role_id = new_role.id
        WHERE old_role.code IN ('admin', 'helper')
    ");

    $db->exec("
        UPDATE roles
        SET
            code = 'elder_moderator',
            name = 'Elder Moderator',
            priority = 75
        WHERE code = 'admin'
          AND NOT EXISTS (
              SELECT 1
              FROM roles r2
              WHERE r2.code = 'elder_moderator'
          )
    ");

    $db->exec("
        DELETE FROM roles
        WHERE code = 'helper'
    ");

    $db->exec("
        DELETE old_role
        FROM roles old_role
        INNER JOIN roles canonical
            ON canonical.code = 'elder_moderator'
            AND canonical.id <> old_role.id
        WHERE old_role.code = 'admin'
    ");
};
