<?php

declare(strict_types=1);

return static function (PDO $db): void {
    $permission = 'plugins.view';

    $roles = $db->query(
        "SELECT id, code
         FROM admin_roles
         WHERE code IN ('owner','admin')"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$roles) {
        return;
    }

    $insert = $db->prepare(
        'INSERT IGNORE INTO admin_role_permissions (role_id, permission)
         VALUES (:role_id, :permission)'
    );

    foreach ($roles as $role) {
        $insert->execute([
            'role_id' => (int)$role['id'],
            'permission' => $permission,
        ]);
    }
};
