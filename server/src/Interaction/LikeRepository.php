<?php
declare(strict_types=1);

namespace MuchoCore\Interaction;

use PDO;
use RuntimeException;
use Throwable;

final readonly class LikeRepository
{
    public function __construct(private PDO $db) {}

    public function addLike(
        int $itemId,
        int $type,
        int $accountId,
        bool $isLike,
        string $ip = ''
    ): bool {
        [$table, $pk] = match ($type) {
            1 => ['levels', 'level_id'],
            2 => ['comments', 'id'],
            3 => ['account_comments', 'id'],
            4 => ['mucho_level_lists', 'list_id'],
            default => throw new RuntimeException('Invalid like type'),
        };

        if ($itemId <= 0) {
            return false;
        }

        $this->db->beginTransaction();

        try {
            if ($accountId > 0) {
                // Authenticated votes are keyed by account, not source IP.
                $q = $this->db->prepare(
                    'SELECT id, is_like
                     FROM likes
                     WHERE item_id=:item
                       AND type=:type
                       AND account_id=:account
                     LIMIT 1
                     FOR UPDATE'
                );
                $q->execute([
                    'item' => $itemId,
                    'type' => $type,
                    'account' => $accountId,
                ]);
            } else {
                $ip = trim($ip);
                if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    $this->db->rollBack();
                    return false;
                }

                $q = $this->db->prepare(
                    'SELECT id, is_like
                     FROM likes
                     WHERE item_id=:item
                       AND type=:type
                       AND account_id=0
                       AND ip=:ip
                     LIMIT 1
                     FOR UPDATE'
                );
                $q->execute([
                    'item' => $itemId,
                    'type' => $type,
                    'ip' => $ip,
                ]);
            }

            $existing = $q->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $oldLike = (int)$existing['is_like'] === 1;
                if ($oldLike === $isLike) {
                    $this->db->rollBack();
                    return false;
                }

                $q = $this->db->prepare(
                    'UPDATE likes SET is_like=:is_like WHERE id=:id'
                );
                $q->execute([
                    'is_like' => $isLike ? 1 : 0,
                    'id' => (int)$existing['id'],
                ]);
                $delta = $isLike ? 1 : -1;
            } else {
                $q = $this->db->prepare(
                    'INSERT INTO likes
                     (item_id,type,account_id,ip,is_like)
                     VALUES (:item,:type,:account,:ip,:like)'
                );
                $q->execute([
                    'item' => $itemId,
                    'type' => $type,
                    'account' => $accountId,
                    'ip' => $accountId > 0 ? '' : $ip,
                    'like' => $isLike ? 1 : 0,
                ]);
                $delta = $isLike ? 1 : 0;
            }

            $q = $this->db->prepare(
                'UPDATE `'.$table.'`
                 SET likes = GREATEST(0, likes + :amount)
                 WHERE `'.$pk.'` = :id'
            );
            $q->execute([
                'amount' => $delta,
                'id' => $itemId,
            ]);

            if ($q->rowCount() !== 1) {
                throw new RuntimeException('Like target not found');
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
