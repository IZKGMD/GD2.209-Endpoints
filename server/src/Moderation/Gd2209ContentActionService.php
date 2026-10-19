<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Bounded, transactional GD 2.209 content moderation operations.
 * Native HTTP response formats and action enum are provisional.
 */
final readonly class Gd2209ContentActionService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
        private ModerationRepository $roles
    ) {}

    private function authorize(int $actor, string $password): string
    {
        $this->auth->authenticate($actor, $password);
        $role = $this->roles->getAccountRole($actor);
        if (!in_array($role, ['elder_moderator','owner'], true)) {
            throw new RuntimeException('Moderator permission required');
        }
        return $role;
    }

    private function targetAllowed(int $actor, string $role, int $target): void
    {
        if ($target <= 0 || $actor === $target) {
            throw new RuntimeException('Invalid target');
        }
        $q = $this->db->prepare(
            'SELECT COALESCE(r.code, "user")
             FROM accounts a LEFT JOIN roles r ON r.id=a.role_id
             WHERE a.account_id=:target LIMIT 1'
        );
        $q->execute(['target'=>$target]);
        $targetRole = $q->fetchColumn();
        $rank = ['user'=>0,'moderator'=>50,'elder_moderator'=>75,'owner'=>100];
        if ($targetRole === false ||
            ($rank[$role] ?? 0) <= ($rank[(string)$targetRole] ?? 100)) {
            throw new RuntimeException('Protected or missing target account');
        }
    }

    /** Player: delete at most 50 owned level comments by explicit IDs. */
    public function deleteOwnComments(
        int $actor, string $password, string $ids
    ): bool {
        $this->auth->authenticate($actor, $password);
        if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,49}$/D', $ids)) {
            return false;
        }
        $numbers = array_values(array_unique(array_map('intval', explode(',', $ids))));
        if (in_array(0, $numbers, true)) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $marks = implode(',', array_fill(0, count($numbers), '?'));
            $select = $this->db->prepare(
                'SELECT id FROM comments
                 WHERE account_id=? AND id IN (' . $marks . ')
                 FOR UPDATE'
            );
            $select->execute(array_merge([$actor], $numbers));
            $owned = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
            if (count($owned) !== count($numbers)) {
                $this->db->rollBack();
                return false;
            }
            $selected = implode(',', array_fill(0, count($owned), '?'));
            $likes = $this->db->prepare(
                'DELETE FROM likes WHERE type=2 AND item_id IN (' . $selected . ')'
            );
            $likes->execute($owned);
            $delete = $this->db->prepare(
                'DELETE FROM comments WHERE account_id=? AND id IN (' . $selected . ')'
            );
            $delete->execute(array_merge([$actor], $owned));
            $ok = $delete->rowCount() === count($owned);
            if (!$ok) {
                $this->db->rollBack();
                return false;
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

    /** Elder moderator: delete an account's persisted level comments. */
    public function deleteUserComments(
        int $actor, string $password, int $target
    ): bool {
        $role = $this->authorize($actor, $password);
        $this->targetAllowed($actor, $role, $target);
        $this->db->beginTransaction();
        try {
            // Bound a destructive operation; do not silently delete partial data.
            $q = $this->db->prepare(
                'SELECT id FROM comments WHERE account_id=:id
                 ORDER BY id ASC LIMIT 5001 FOR UPDATE'
            );
            $q->execute(['id'=>$target]);
            $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === [] || count($ids) > 5000) {
                $this->db->rollBack();
                return false;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $delLikes = $this->db->prepare(
                    'DELETE FROM likes WHERE type=2 AND item_id IN (' . $marks . ')'
                );
                $delLikes->execute($chunk);
                $delComments = $this->db->prepare(
                    'DELETE FROM comments WHERE account_id=? AND id IN (' . $marks . ')'
                );
                $delComments->execute(array_merge([$target], $chunk));
                if ($delComments->rowCount() !== count($chunk)) {
                    throw new RuntimeException('Concurrent comment changes');
                }
            }
            $this->audit($actor, $target, 'DELETE_COMMENTS', count($ids));
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** A single verified message in a known thread; other enum values denied. */
    public function threadAction(
        int $actor, string $password,
        int $threadId, string $action, int $messageId
    ): bool {
        $role = $this->authorize($actor, $password);
        if ($threadId <= 0 || $messageId <= 0 || $action !== 'delete') {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare(
                'SELECT sender_account_id FROM mucho_thread_messages
                 WHERE id=:msg AND thread_id=:thread FOR UPDATE'
            );
            $q->execute(['msg'=>$messageId,'thread'=>$threadId]);
            $target = $q->fetchColumn();
            if ($target === false) {
                $this->db->rollBack();
                return false;
            }
            $this->targetAllowed($actor, $role, (int)$target);
            $delete = $this->db->prepare(
                'DELETE FROM mucho_thread_messages
                 WHERE id=:msg AND thread_id=:thread'
            );
            $delete->execute(['msg'=>$messageId,'thread'=>$threadId]);
            if ($delete->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }
            $this->audit($actor, (int)$target, 'DELETE_THREAD_MSG', 0);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function audit(int $actor, int $target, string $action, int $detail): void
    {
        $q = $this->db->prepare(
            'INSERT INTO mucho_gd2209_case_actions
             (actor_account_id,target_account_id,action,detail)
             VALUES (:actor,:target,:action,:detail)'
        );
        $q->execute([
            'actor'=>$actor,'target'=>$target,
            'action'=>$action,'detail'=>$detail
        ]);
    }
}
