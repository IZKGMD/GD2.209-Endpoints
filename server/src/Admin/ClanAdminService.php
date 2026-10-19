<?php
declare(strict_types=1);

namespace MuchoCore\Admin;

use InvalidArgumentException;
use MuchoCore\Clan\ClanRepository;
use PDO;
use RuntimeException;

/** Called only after the admin handler authorizes social.manage and checks CSRF. */
final class ClanAdminService
{
    public static function apply(PDO $db, string $action, array $input, string $adminUsername): void
    {
        $repository = new ClanRepository($db, 'Admin '.mb_substr($adminUsername, 0, 64, 'UTF-8'));
        $id = (int)($input['id'] ?? 0);
        $clan = $repository->getById($id);
        if (!$clan) throw new InvalidArgumentException('Clan not found.');
        // Use existing transactional membership rules, including owner protection,
        // quest settlement and cascading cleanup. A concurrent transfer fails closed.
        $owner = (int)$clan['owner_account_id'];
        $target = (int)($input['account_id'] ?? 0);
        switch ($action) {
            case 'clan-save':
                $name = trim((string)($input['name'] ?? ''));
                $tag = strtoupper(trim((string)($input['tag'] ?? '')));
                $description = trim((string)($input['description'] ?? ''));
                $max = (int)($input['max_members'] ?? 0);
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{1,23}$/D', $name)
                    || !preg_match('/^[A-Z0-9]{2,6}$/D', $tag)
                    || mb_strlen($description, 'UTF-8') > 160 || $max < 2 || $max > 500) {
                    throw new InvalidArgumentException('Name: 2–24 letters/numbers, spaces, _, . or -. Tag: 2–6 letters/numbers. Description: up to 160 characters. Members: 2–500.');
                }
                if ($max < (int)$clan['member_count']) throw new InvalidArgumentException('The member limit cannot be below the current membership.');
                $repository->updateSettings($id, $owner, $name, $tag, $description, isset($input['is_open']), $max);
                break;
            case 'clan-role':
                $role = (string)($input['role'] ?? '');
                if (!in_array($role, ['officer','member'], true)) throw new InvalidArgumentException('Choose member or officer. Use Transfer ownership to change the owner.');
                $repository->setRole($id, $owner, $target, $role);
                break;
            case 'clan-kick':
                $repository->kick($id, $owner, $target);
                break;
            case 'clan-transfer':
                if ((string)($input['confirm_tag'] ?? '') !== (string)$clan['tag']) throw new InvalidArgumentException('Type the current clan tag to confirm the ownership transfer.');
                $repository->transferOwnership($id, $owner, $target);
                break;
            case 'clan-delete':
                if ((string)($input['confirm_tag'] ?? '') !== (string)$clan['tag']) throw new InvalidArgumentException('Type the current clan tag to confirm deletion.');
                $repository->disband($id, $owner);
                break;
            default:
                throw new RuntimeException('Unknown clan action.');
        }
    }
}
