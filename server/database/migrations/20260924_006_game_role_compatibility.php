<?php

declare(strict_types=1);

/*
 * MuchoCore 1.0 game-role compatibility repair.
 *
 * The canonical source is accounts.role_id -> roles.code. Older installs may
 * also have accounts.role or obsolete admin/helper role rows.
 */
return static function (PDO $db): void {
    $columnExists = static function (
        PDO $db,
        string $table,
        string $column
    ): bool {
        $q = $db->prepare(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column"
        );
        $q->execute([
            'table' => $table,
            'column' => $column,
        ]);

        return (int)$q->fetchColumn() > 0;
    };

    $indexOrTableExists = static function (
        PDO $db,
        string $name
    ): bool {
        $q = $db->prepare(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :name"
        );
        $q->execute(['name' => $name]);
        return (int)$q->fetchColumn() > 0;
    };

    $roles = [
        ['user', 'User', 0],
        ['moderator', 'Moderator', 50],
        ['elder_moderator', 'Elder Moderator', 75],
        ['owner', 'Owner', 100],
    ];

    foreach ($roles as [$code, $name, $priority]) {
        $stmt = $db->prepare(
            'INSERT INTO roles (code, name, priority)
             VALUES (:code, :name, :priority)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                priority = VALUES(priority)'
        );

        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'priority' => $priority,
        ]);
    }

    if (
        $indexOrTableExists($db, 'accounts') &&
        $columnExists($db, 'accounts', 'role')
    ) {
        $db->exec(<<<'SQL'
UPDATE accounts a
INNER JOIN roles r
    ON r.code = CASE LOWER(TRIM(COALESCE(a.role, '')))
        WHEN 'admin' THEN 'elder_moderator'
        WHEN 'elder' THEN 'elder_moderator'
        WHEN 'developer' THEN 'elder_moderator'
        WHEN 'helper' THEN 'moderator'
        WHEN 'mod' THEN 'moderator'
        WHEN 'moderator' THEN 'moderator'
        WHEN 'player' THEN 'user'
        WHEN 'user' THEN 'user'
        ELSE NULL
    END
SET a.role_id = r.id
WHERE LOWER(TRIM(COALESCE(a.role, ''))) IN (
    'admin',
    'elder',
    'developer',
    'helper',
    'mod',
    'moderator',
    'player',
    'user'
)
AND (
    a.role_id IS NULL
    OR a.role_id = (
        SELECT id
        FROM roles
        WHERE code = 'user'
        LIMIT 1
    )
)
SQL);

        /*
         * Keep the legacy column internally consistent for older tools that
         * still read it, without replacing the canonical role_id relation.
         */
        $db->exec(<<<'SQL'
UPDATE accounts a
INNER JOIN roles r
    ON r.id = a.role_id
SET a.role = r.code
WHERE LOWER(TRIM(COALESCE(a.role, ''))) IN (
    '',
    'admin',
    'elder',
    'developer',
    'helper',
    'mod',
    'moderator',
    'player',
    'user'
)
SQL);
    }

    /*
     * Normalize accounts that still point at obsolete role rows.
     */
    $db->exec(<<<'SQL'
UPDATE accounts a
INNER JOIN roles old_role
    ON old_role.id = a.role_id
INNER JOIN roles new_role
    ON new_role.code = CASE old_role.code
        WHEN 'admin' THEN 'elder_moderator'
        WHEN 'helper' THEN 'moderator'
        WHEN 'mod' THEN 'moderator'
        WHEN 'elder' THEN 'elder_moderator'
        WHEN 'developer' THEN 'elder_moderator'
        WHEN 'player' THEN 'user'
        ELSE old_role.code
    END
SET a.role_id = new_role.id
WHERE old_role.code IN (
    'admin',
    'helper',
    'mod',
    'elder',
    'developer',
    'player'
)
SQL);

};
