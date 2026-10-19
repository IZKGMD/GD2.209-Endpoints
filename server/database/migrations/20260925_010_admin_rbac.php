<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS admin_roles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL UNIQUE,
            name VARCHAR(64) NOT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS admin_role_permissions (
            role_id BIGINT UNSIGNED NOT NULL,
            permission VARCHAR(128) NOT NULL,
            PRIMARY KEY (role_id, permission),
            CONSTRAINT fk_admin_role_permissions_role
                FOREIGN KEY (role_id) REFERENCES admin_roles(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $roles = [
        'owner' => 'Owner',
        'admin' => 'Administrator',
        'moderator' => 'Moderator',
        'viewer' => 'Viewer',
    ];

    $stmt = $db->prepare(
        'INSERT INTO admin_roles (code, name, is_system)
         VALUES (:code, :name, 1)
         ON DUPLICATE KEY UPDATE
            name=VALUES(name),
            is_system=1'
    );

    foreach ($roles as $code => $name) {
        $stmt->execute([
            'code' => $code,
            'name' => $name,
        ]);
    }

    $permissions = [
        'dashboard.view',
        'players.view',
        'players.manage',
        'players.password_reset',
        'players.delete',
        'levels.view',
        'levels.manage',
        'levels.rate',
        'moderation.manage',
        'comments.manage',
        'messages.manage',
        'social.manage',
        'music.manage',
        'analytics.view',
        'monitoring.view',
        'security.view',
        'database.view',
        'tools.endpoint_test',
        'client.manage',
        'backups.view',
        'backups.download',
        'settings.manage',
        'system.manage',
        'admins.manage',
        'roles.manage',
        'audit.view',
        'self.security',
    ];

    $permissionMap = [
        'owner' => $permissions,
        'admin' => [
            'dashboard.view','players.view','players.manage','players.password_reset',
            'levels.view','levels.manage','levels.rate','moderation.manage',
            'comments.manage','messages.manage','social.manage','music.manage',
            'analytics.view','monitoring.view','security.view',
            'tools.endpoint_test','client.manage','backups.view','audit.view',
            'self.security'
        ],
        'moderator' => [
            'dashboard.view','players.view','levels.view','levels.manage','levels.rate',
            'moderation.manage','comments.manage','social.manage','audit.view',
            'self.security'
        ],
        'viewer' => [
            'dashboard.view','players.view','levels.view','audit.view','self.security'
        ],
    ];

    $roleIdStmt = $db->prepare(
        'SELECT id FROM admin_roles WHERE code=:code LIMIT 1'
    );

    $insertPermission = $db->prepare(
        'INSERT IGNORE INTO admin_role_permissions (role_id, permission)
         VALUES (:role_id, :permission)'
    );

    foreach ($permissionMap as $code => $rolePermissions) {
        $roleIdStmt->execute(['code' => $code]);
        $roleId = $roleIdStmt->fetchColumn();
        if ($roleId === false) {
            continue;
        }

        foreach ($rolePermissions as $permission) {
            $insertPermission->execute([
                'role_id' => (int)$roleId,
                'permission' => $permission,
            ]);
        }
    }
};
