<?php

declare(strict_types=1);

namespace MuchoCore\Admin;

use PDO;
use RuntimeException;

final class AdminRbac
{
    private static ?bool $tablesReady = null;

    /** @var array<string,list<string>> */
    private static array $permissionCache = [];

    /** @var array<string,string> */
    public const PERMISSIONS = [
        'dashboard.view' => 'View dashboard',
        'players.view' => 'View players',
        'players.manage' => 'Edit players',
        'players.password_reset' => 'Reset player passwords',
        'players.delete' => 'Delete players',
        'levels.view' => 'View levels',
        'levels.manage' => 'Edit levels',
        'levels.rate' => 'Rate / feature levels',
        'contentpacks.manage' => 'Create and publish Gauntlets and Map Packs',
        'moderation.manage' => 'Moderate content and players',
        'comments.manage' => 'Moderate comments',
        'messages.manage' => 'Moderate messages',
        'social.manage' => 'Manage social relationships',
        'music.manage' => 'Manage music',
        'analytics.view' => 'View analytics',
        'monitoring.view' => 'View monitoring',
        'security.view' => 'View security center',
        'database.view' => 'View database browser',
        'tools.endpoint_test' => 'Use API tester',
        'client.manage' => 'Manage client and feature tools',
        'backups.view' => 'View backups',
        'backups.download' => 'Download database backups',
        'settings.manage' => 'Change server settings',
        'system.manage' => 'Run server operations',
        'admins.manage' => 'Manage administrators',
        'roles.manage' => 'Manage custom roles',
        'audit.view' => 'View audit log',
        'self.security' => 'Manage own authentication security',
        'plugins.view' => 'View custom plugin diagnostics',
    ];

    /** @var array<string,string> */
    private const ALIASES = [
        'users.view' => 'players.view',
        'users.manage' => 'players.manage',
        'users.delete' => 'players.delete',
        'users.password_reset' => 'players.password_reset',
    ];

    public static function normalizePermission(string $permission): string
    {
        return self::ALIASES[$permission] ?? $permission;
    }

    public static function isKnownPermission(string $permission): bool
    {
        return isset(self::PERMISSIONS[self::normalizePermission($permission)]);
    }

    /** @return array<string,string> */
    public static function permissions(): array
    {
        return self::PERMISSIONS;
    }

    /** @return array<string,string> */
    public static function roles(PDO $db): array
    {
        if (!self::tablesReady($db)) {
            return [];
        }

        $rows = $db->query(
            'SELECT code,name
             FROM admin_roles
             ORDER BY is_system DESC, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['code']] = (string)$row['name'];
        }

        return $result;
    }

    public static function tablesReady(PDO $db): bool
    {
        if (self::$tablesReady !== null) {
            return self::$tablesReady;
        }

        try {
            $stmt = $db->query(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name IN ('admin_roles','admin_role_permissions')"
            );
            return self::$tablesReady = ((int)$stmt->fetchColumn() === 2);
        } catch (\Throwable) {
            return self::$tablesReady = false;
        }
    }

    public static function roleExists(PDO $db, string $code): bool
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return false;
        }

        if (!self::tablesReady($db)) {
            return in_array($code, ['owner','admin','moderator','viewer'], true);
        }

        $stmt = $db->prepare(
            'SELECT 1 FROM admin_roles WHERE code=:code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);

        return $stmt->fetchColumn() !== false;
    }

    public static function isBuiltInRole(string $code): bool
    {
        return in_array(
            strtolower(trim($code)),
            ['owner', 'admin', 'moderator', 'viewer'],
            true
        );
    }

    public static function canManagePermissionSet(
        PDO $db,
        ?array $admin,
        array $permissions
    ): bool {
        if (!$admin) {
            return false;
        }

        if ((string)($admin['role'] ?? '') === 'owner') {
            return true;
        }

        $own = self::rolePermissions($db, (string)($admin['role'] ?? ''));
        foreach (self::normalizePermissionList($permissions) as $permission) {
            if (!in_array($permission, $own, true)) {
                return false;
            }
        }

        return true;
    }

    public static function canAssignRole(
        PDO $db,
        ?array $admin,
        string $targetRole
    ): bool {
        if (!$admin) {
            return false;
        }

        $targetRole = strtolower(trim($targetRole));
        if (!self::roleExists($db, $targetRole)) {
            return false;
        }

        if ((string)($admin['role'] ?? '') === 'owner') {
            return true;
        }

        if (self::isBuiltInRole($targetRole)) {
            return self::can($db, $admin, self::normalizePermission('self.security'))
                && $targetRole === 'viewer';
        }

        return self::canManagePermissionSet(
            $db,
            $admin,
            self::rolePermissions($db, $targetRole)
        );
    }

    public static function roleName(PDO $db, string $code): string
    {
        $code = strtolower(trim($code));

        if (self::tablesReady($db)) {
            $stmt = $db->prepare(
                'SELECT name FROM admin_roles WHERE code=:code LIMIT 1'
            );
            $stmt->execute(['code' => $code]);
            $name = $stmt->fetchColumn();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return match ($code) {
            'owner' => 'Owner',
            'admin' => 'Administrator',
            'moderator' => 'Moderator',
            'viewer' => 'Viewer',
            default => $code,
        };
    }

    /** @return list<string> */
    public static function rolePermissions(PDO $db, string $code): array
    {
        $code = strtolower(trim($code));

        if (isset(self::$permissionCache[$code])) {
            return self::$permissionCache[$code];
        }

        if (!self::tablesReady($db)) {
            return self::$permissionCache[$code] = [];
        }

        $stmt = $db->prepare(
            'SELECT permission
             FROM admin_role_permissions p
             INNER JOIN admin_roles r ON r.id=p.role_id
             WHERE r.code=:code
             ORDER BY permission'
        );
        $stmt->execute(['code' => strtolower(trim($code))]);

        $permissions = array_values(
            array_filter(
                array_map(
                    static fn($value): string => self::normalizePermission((string)$value),
                    $stmt->fetchAll(PDO::FETCH_COLUMN)
                ),
                static fn(string $value): bool => isset(self::PERMISSIONS[$value])
            )
        );

        return self::$permissionCache[$code] = $permissions;
    }

    public static function can(PDO $db, ?array $admin, string $permission): bool
    {
        if (!$admin) {
            return false;
        }

        $permission = self::normalizePermission($permission);

        if (!isset(self::PERMISSIONS[$permission])) {
            return false;
        }

        $role = strtolower(trim((string)($admin['role'] ?? '')));
        if ($role === 'owner') {
            return true;
        }

        if (!self::tablesReady($db)) {
            return false;
        }

        return in_array(
            $permission,
            self::rolePermissions($db, $role),
            true
        );
    }

    public static function require(PDO $db, ?array $admin, string $permission): void
    {
        if (!self::can($db, $admin, $permission)) {
            throw new RuntimeException('Insufficient permissions.');
        }
    }

    /**
     * Map existing legacy rank checks to fine-grained permissions.
     * Built-in roles keep the historic rank behavior, while custom roles
     * resolve the current action/page to an explicit permission.
     */
    public static function permissionForContext(
        ?string $action,
        ?string $page,
        int $rank
    ): ?string {
        $action = strtolower(trim((string)$action));
        $page = strtolower(trim((string)$page));

        $actions = [
            'account-save' => 'players.manage',
            'music-upload' => 'music.manage',
            'music-verify' => 'music.manage',
            'music-unverify' => 'music.manage',
            'music-delete' => 'music.manage',
            'profile-save' => 'players.manage',
            'muchoprofile-save' => 'players.manage',
            'muchoprofile-token' => 'players.manage',
            'password-reset' => 'players.password_reset',
            'account-delete' => 'players.delete',
            'level-save' => 'levels.manage',
            'level-details-save' => 'levels.manage',
            'level-rate-save' => 'levels.rate',
            'gauntlet-save' => 'contentpacks.manage',
            'gauntlet-delete' => 'contentpacks.manage',
            'mappack-save' => 'contentpacks.manage',
            'mappack-delete' => 'contentpacks.manage',
            'comment-delete' => 'comments.manage',
            'message-delete' => 'messages.manage',
            'relation-delete' => 'social.manage',
            'clan-save' => 'social.manage',
            'clan-role' => 'social.manage',
            'clan-kick' => 'social.manage',
            'clan-transfer' => 'social.manage',
            'clan-delete' => 'social.manage',
            'settings-save' => 'settings.manage',
            'endpoint-test' => 'tools.endpoint_test',
            'system-op' => 'system.manage',
            'admin-create' => 'admins.manage',
            'admin-toggle' => 'admins.manage',
            'recovery-generate' => 'self.security',
            '2fa-generate' => 'self.security',
            '2fa-cancel' => 'self.security',
            '2fa-enable' => 'self.security',
            '2fa-disable' => 'self.security',
            'passkey-register' => 'self.security',
            'passkey-revoke' => 'self.security',
            'auth.login' => 'dashboard.view',
            'auth.logout' => 'self.security',
            'auth.me' => 'self.security',
            'server.health' => 'settings.manage',
            'players.search' => 'players.view',
            'players.get' => 'players.view',
            'players.set_ban' => 'players.manage',
            'players.set_role' => 'players.manage',
            'players.update_profile' => 'players.manage',
            'levels.search' => 'levels.view',
            'levels.update' => 'levels.manage',
            'audit.list' => 'audit.view',
            'mucho.konami' => 'settings.manage',
        ];

        if (isset($actions[$action])) {
            return $actions[$action];
        }

        $pages = [
            'dashboard' => 'dashboard.view',
            'players' => 'players.view',
            'muchoprofiles' => 'players.manage',
            'levels' => 'levels.view',
            'contentpacks' => 'contentpacks.manage',
            'moderation' => 'moderation.manage',
            'rating' => 'levels.rate',
            'comments' => 'comments.manage',
            'messages' => 'messages.manage',
            'social' => 'social.manage',
            'clans' => 'social.manage',
            'songs' => 'music.manage',
            'analytics' => 'analytics.view',
            'monitoring' => 'monitoring.view',
            'intelligence' => 'monitoring.view',
            'securitycenter' => 'security.view',
            'database' => 'database.view',
            'endpoints' => 'tools.endpoint_test',
            'clientfeatures' => 'client.manage',
            'clientpatcher' => 'client.manage',
            'clients' => 'client.manage',
            'updates' => 'system.manage',
            'plugins' => 'plugins.view',
            'dbbackups' => 'backups.view',
            'migration' => 'system.manage',
            'backups' => 'backups.view',
            'settings' => 'settings.manage',
            'system' => 'system.manage',
            'admins' => 'admins.manage',
            'roles' => 'roles.manage',
            'audit' => 'audit.view',
        ];

        if (isset($pages[$page])) {
            return $pages[$page];
        }

        if ($rank >= 40) {
            return 'settings.manage';
        }
        if ($rank >= 30) {
            return 'players.manage';
        }
        if ($rank >= 20) {
            return 'moderation.manage';
        }

        return 'dashboard.view';
    }

    /** @return list<string> */
    public static function normalizePermissionList(array $permissions): array
    {
        $result = [];

        foreach ($permissions as $permission) {
            $permission = self::normalizePermission((string)$permission);
            if (
                isset(self::PERMISSIONS[$permission]) &&
                !in_array($permission, $result, true)
            ) {
                $result[] = $permission;
            }
        }

        sort($result);
        return $result;
    }

    public static function createRole(
        PDO $db,
        string $code,
        string $name,
        array $permissions
    ): void {
        if (!self::tablesReady($db)) {
            throw new RuntimeException('RBAC database tables are not available.');
        }

        $code = strtolower(trim($code));
        $name = trim($name);
        $permissions = self::normalizePermissionList($permissions);

        if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $code)) {
            throw new RuntimeException('Role code must be 2-32 characters and contain only lowercase letters, numbers, _ or -.');
        }

        if ($name === '' || mb_strlen($name, 'UTF-8') > 64) {
            throw new RuntimeException('Role name must be between 1 and 64 characters.');
        }

        if (in_array($code, ['owner','admin','moderator','viewer'], true)) {
            throw new RuntimeException('Built-in roles cannot be recreated.');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO admin_roles (code,name,is_system)
                 VALUES (:code,:name,0)'
            );
            $stmt->execute(['code' => $code, 'name' => $name]);

            self::replacePermissions($db, $code, $permissions);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function updateRole(
        PDO $db,
        int $id,
        string $name,
        array $permissions
    ): void {
        if (!self::tablesReady($db)) {
            throw new RuntimeException('RBAC database tables are not available.');
        }

        $stmt = $db->prepare(
            'SELECT code,is_system FROM admin_roles WHERE id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            throw new RuntimeException('Role not found.');
        }

        if ((int)$role['is_system'] === 1) {
            throw new RuntimeException('Built-in roles cannot be edited here.');
        }

        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 64) {
            throw new RuntimeException('Role name must be between 1 and 64 characters.');
        }

        $db->beginTransaction();
        try {
            $update = $db->prepare(
                'UPDATE admin_roles SET name=:name WHERE id=:id'
            );
            $update->execute(['name' => $name, 'id' => $id]);

            self::replacePermissionsById(
                $db,
                $id,
                self::normalizePermissionList($permissions)
            );

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function deleteRole(PDO $db, int $id): void
    {
        if (!self::tablesReady($db)) {
            throw new RuntimeException('RBAC database tables are not available.');
        }

        $stmt = $db->prepare(
            'SELECT code,is_system FROM admin_roles WHERE id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            throw new RuntimeException('Role not found.');
        }

        if ((int)$role['is_system'] === 1) {
            throw new RuntimeException('Built-in roles cannot be deleted.');
        }

        $code = (string)$role['code'];
        $count = $db->prepare(
            'SELECT COUNT(*) FROM admin_users WHERE role=:role'
        );
        $count->execute(['role' => $code]);

        if ((int)$count->fetchColumn() > 0) {
            throw new RuntimeException('This role is still assigned to administrators. Reassign them first.');
        }

        $db->prepare('DELETE FROM admin_roles WHERE id=:id')
            ->execute(['id' => $id]);
    }

    private static function replacePermissions(PDO $db, string $code, array $permissions): void
    {
        $stmt = $db->prepare(
            'SELECT id FROM admin_roles WHERE code=:code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('Role not found.');
        }

        self::replacePermissionsById($db, (int)$id, $permissions);
    }

    private static function replacePermissionsById(PDO $db, int $id, array $permissions): void
    {
        $db->prepare(
            'DELETE FROM admin_role_permissions WHERE role_id=:id'
        )->execute(['id' => $id]);

        if ($permissions === []) {
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO admin_role_permissions (role_id,permission)
             VALUES (:id,:permission)'
        );

        foreach ($permissions as $permission) {
            $stmt->execute([
                'id' => $id,
                'permission' => $permission,
            ]);
        }
    }
}
