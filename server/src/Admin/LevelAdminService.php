<?php
declare(strict_types=1);

namespace MuchoCore\Admin;

use PDO;
use RuntimeException;
use Throwable;

/** Metadata editing deliberately leaves the rating and counters untouched. */
final class LevelAdminService
{
    public static function saveDetails(PDO $db, int $id, string $name, bool $deleted): void
    {
        $name=trim($name);
        if ($id<=0 || $name==='' || mb_strlen($name,'UTF-8')>64) {
            throw new RuntimeException('Enter a level name between 1 and 64 characters.');
        }
        $db->beginTransaction();
        try {
            $select=$db->prepare('SELECT account_id FROM levels WHERE level_id=:id');
            $select->execute(['id'=>$id]);
            $creator=$select->fetchColumn();
            if ($creator===false) throw new RuntimeException('Level not found.');
            $db->prepare('UPDATE levels SET name=:name,is_deleted=:deleted WHERE level_id=:id')
                ->execute(['name'=>$name,'deleted'=>$deleted?1:0,'id'=>$id]);
            $points=$db->prepare('SELECT COALESCE(SUM(
                CASE WHEN stars>0 THEN 1 ELSE 0 END + CASE WHEN featured>0 THEN 1 ELSE 0 END + epic
                ),0) FROM levels WHERE account_id=:account_id AND is_deleted=0');
            $points->execute(['account_id'=>(int)$creator]);
            $db->prepare('UPDATE profiles SET creator_points=:points WHERE account_id=:account_id')
                ->execute(['points'=>(int)$points->fetchColumn(),'account_id'=>(int)$creator]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
