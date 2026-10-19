<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
use Throwable;

/** Native 2.209 mode-based thread actions, with database authorization. */
final readonly class Gd2209ThreadActionService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
    ) {}

    /** Validate numeric IDs before opening a write transaction. */
    private static function positiveIds(string $value): array
    {
        if ($value === '' || strlen($value) > 360 ||
            !preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,31}$/D', $value)) {
            throw new RuntimeException('Invalid native IDs');
        }
        $ids = array_map('intval', explode(',', $value));
        if (count(array_unique($ids)) !== count($ids)
            || in_array(0, $ids, true)) {
            throw new RuntimeException('Duplicate or invalid ID');
        }
        return $ids;
    }

    private function messageMax(int $thread): int
    {
        $q = $this->db->prepare(
            'SELECT COALESCE(MAX(id),0) FROM mucho_thread_messages
             WHERE thread_id=:thread'
        );
        $q->execute(['thread'=>$thread]);
        return (int)$q->fetchColumn();
    }

    /** Read acknowledgement uses thread IDs encoded in "value", threadID=0. */
    private function markRead(int $actor, string $value): bool
    {
        $ids = self::positiveIds($value);
        $this->db->beginTransaction();
        try {
            foreach ($ids as $thread) {
                $q = $this->db->prepare(
                    'SELECT 1 FROM mucho_thread_members
                     WHERE thread_id=:thread AND account_id=:actor FOR UPDATE'
                );
                $q->execute(['thread'=>$thread,'actor'=>$actor]);
                if ($q->fetchColumn() === false) {
                    $this->db->rollBack();
                    return false;
                }
                $update = $this->db->prepare(
                    'UPDATE mucho_thread_members
                     SET last_read_message_id=GREATEST(last_read_message_id,:latest)
                     WHERE account_id=:actor AND thread_id=:thread'
                );
                $update->execute([
                    'latest'=>$this->messageMax($thread),
                    'actor'=>$actor,'thread'=>$thread
                ]);
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

    /** Returns success only if the actor is allowed to perform this mode. */
    public function change(
        int $actor, string $credential,
        int $thread, int $mode, string $value
    ): bool {
        $this->auth->authenticate($actor, $credential);
        if ($mode === 3) {
            return $thread === 0 && $this->markRead($actor, $value);
        }
        if ($thread <= 0) {
            return false;
        }
        if ($mode === 18) {
            $remove = $this->db->prepare(
                'DELETE FROM mucho_thread_former_members
                 WHERE thread_id=:thread AND account_id=:actor AND reason="kicked"'
            );
            $remove->execute(['thread'=>$thread,'actor'=>$actor]);
            return $remove->rowCount() === 1;
        }

        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare(
                'SELECT t.owner_account_id,t.is_dm,t.is_closed,
                        t.invite_owner_only,m.account_id AS member
                 FROM mucho_threads t
                 LEFT JOIN mucho_thread_members m
                   ON m.thread_id=t.id AND m.account_id=:actor
                 WHERE t.id=:thread FOR UPDATE'
            );
            $lock->execute(['actor'=>$actor,'thread'=>$thread]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['member'] === null ||
                (int)$row['is_closed'] === 1) {
                $this->db->rollBack();
                return false;
            }

            $owner = (int)$row['owner_account_id'] === $actor;
            $isGroup = (int)$row['is_dm'] === 0;
            $needsOwner = in_array($mode, [6,7,11,13,14,16], true);
            if (($needsOwner && (!$owner || !$isGroup)) ||
                ($mode === 4 && (!$isGroup ||
                 ((int)$row['invite_owner_only'] === 1 && !$owner)))) {
                $this->db->rollBack();
                return false;
            }

            $ok = match ($mode) {
                1 => $this->clearHistory($actor, $thread),
                4 => $this->inviteMany($actor, $thread, $value),
                6 => $this->kick($actor, $thread, $value),
                7 => $this->transfer($actor, $thread, $value),
                10 => $this->memberToggle($actor, $thread, 'is_muted', $value),
                11 => $this->revokeInvite($thread, $value),
                13 => $this->threadToggle($thread, 'invite_owner_only', $value),
                14 => $this->changeText($thread, 'description', $value, 1024),
                15 => $this->memberToggle($actor, $thread, 'is_pinned', $value),
                16 => $this->changeText($thread, 'emote', $value, 60),
                default => false,
            };
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

    private function clearHistory(int $actor, int $thread): bool
    {
        $latest = $this->messageMax($thread);
        $q = $this->db->prepare(
            'UPDATE mucho_thread_members
             SET last_cleared_message_id=GREATEST(last_cleared_message_id,:last),
                 last_read_message_id=GREATEST(last_read_message_id,:latest)
             WHERE thread_id=:thread AND account_id=:actor'
        );
        $q->execute([
            'last'=>$latest,'latest'=>$latest,
            'thread'=>$thread,'actor'=>$actor
        ]);
        return true;
    }

    private function memberToggle(
        int $actor, int $thread, string $field, string $value
    ): bool {
        if (!in_array($field, ['is_muted','is_pinned'], true) ||
            !in_array($value, ['0','1'], true)) {
            return false;
        }
        $q = $this->db->prepare(
            'UPDATE mucho_thread_members SET ' . $field . '=:state
             WHERE account_id=:actor AND thread_id=:thread'
        );
        $q->execute(['state'=>(int)$value,'actor'=>$actor,'thread'=>$thread]);
        return true;
    }

    private function threadToggle(int $thread, string $field, string $value): bool
    {
        if ($field !== 'invite_owner_only' || !in_array($value, ['0','1'], true)) {
            return false;
        }
        $q = $this->db->prepare(
            'UPDATE mucho_threads SET invite_owner_only=:state WHERE id=:thread'
        );
        $q->execute(['state'=>(int)$value,'thread'=>$thread]);
        return true;
    }

    private function changeText(
        int $thread, string $field, string $value, int $max
    ): bool {
        if (!in_array($field, ['description','emote'], true) ||
            strlen($value) > $max || !mb_check_encoding($value,'UTF-8') ||
            preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }
        $q = $this->db->prepare(
            'UPDATE mucho_threads SET ' . $field . '=:value WHERE id=:thread'
        );
        $q->execute(['value'=>$value,'thread'=>$thread]);
        return true;
    }

    private function kick(int $actor, int $thread, string $value): bool
    {
        $ids = self::positiveIds($value);
        if (count($ids) !== 1 || $ids[0] === $actor) {
            return false;
        }
        $target = $ids[0];
        $q = $this->db->prepare(
            'SELECT 1 FROM mucho_thread_members
             WHERE thread_id=:thread AND account_id=:target FOR UPDATE'
        );
        $q->execute(['thread'=>$thread,'target'=>$target]);
        if ($q->fetchColumn() === false) {
            return false;
        }
        $former = $this->db->prepare(
            "INSERT INTO mucho_thread_former_members
             (thread_id,account_id,reason,last_visible_message_id)
             VALUES (:thread,:target,'kicked',:latest)
             ON DUPLICATE KEY UPDATE
               reason='kicked',last_visible_message_id=VALUES(last_visible_message_id),
               left_at=UTC_TIMESTAMP()"
        );
        $former->execute([
            'thread'=>$thread,'target'=>$target,'latest'=>$this->messageMax($thread)
        ]);
        $delete = $this->db->prepare(
            'DELETE FROM mucho_thread_members WHERE thread_id=:thread AND account_id=:target'
        );
        $delete->execute(['thread'=>$thread,'target'=>$target]);
        return $delete->rowCount() === 1;
    }

    private function transfer(int $actor, int $thread, string $value): bool
    {
        $ids = self::positiveIds($value);
        if (count($ids) !== 1 || $ids[0] === $actor) {
            return false;
        }
        $newOwner = $ids[0];
        $q = $this->db->prepare(
            'SELECT 1 FROM mucho_thread_members
             WHERE thread_id=:thread AND account_id=:target'
        );
        $q->execute(['thread'=>$thread,'target'=>$newOwner]);
        if ($q->fetchColumn() === false) {
            return false;
        }
        $update = $this->db->prepare(
            'UPDATE mucho_threads SET owner_account_id=:owner WHERE id=:thread'
        );
        $update->execute(['owner'=>$newOwner,'thread'=>$thread]);
        return true;
    }

    private function revokeInvite(int $thread, string $value): bool
    {
        $ids = self::positiveIds($value);
        if (count($ids) !== 1) {
            return false;
        }
        $q = $this->db->prepare(
            'DELETE FROM mucho_thread_invites
             WHERE thread_id=:thread AND invitee_account_id=:target AND status="pending"'
        );
        $q->execute(['thread'=>$thread,'target'=>$ids[0]]);
        return $q->rowCount() === 1;
    }

    private function inviteMany(int $actor, int $thread, string $value): bool
    {
        $ids = self::positiveIds($value);
        $count = $this->db->prepare(
            'SELECT (SELECT COUNT(*) FROM mucho_thread_members WHERE thread_id=:m)
              + (SELECT COUNT(*) FROM mucho_thread_invites
                 WHERE thread_id=:i AND status="pending")'
        );
        $count->execute(['m'=>$thread,'i'=>$thread]);
        if ((int)$count->fetchColumn() + count($ids) > 32) {
            return false;
        }
        foreach ($ids as $target) {
            if ($target === $actor) {
                return false;
            }
            $account = $this->db->prepare(
                'SELECT 1 FROM accounts
                 WHERE account_id=:id AND is_active=1 AND is_banned=0 LIMIT 1'
            );
            $account->execute(['id'=>$target]);
            if ($account->fetchColumn() === false) {
                return false;
            }
            $block = $this->db->prepare(
                'SELECT 1 FROM blocks WHERE
                 (account_id=:a AND blocked_account_id=:b) OR
                 (account_id=:b2 AND blocked_account_id=:a2) LIMIT 1'
            );
            $block->execute([
                'a'=>$actor,'b'=>$target,'b2'=>$target,'a2'=>$actor
            ]);
            if ($block->fetchColumn() !== false) {
                return false;
            }
            $member = $this->db->prepare(
                'SELECT 1 FROM mucho_thread_members
                 WHERE thread_id=:thread AND account_id=:target LIMIT 1'
            );
            $member->execute(['thread'=>$thread,'target'=>$target]);
            if ($member->fetchColumn() !== false) {
                return false;
            }
        }
        $insert = $this->db->prepare(
            "INSERT INTO mucho_thread_invites
             (thread_id,inviter_account_id,invitee_account_id,status)
             VALUES (:thread,:actor,:target,'pending')
             ON DUPLICATE KEY UPDATE
                inviter_account_id=VALUES(inviter_account_id),status='pending'"
        );
        foreach ($ids as $target) {
            $insert->execute(['thread'=>$thread,'actor'=>$actor,'target'=>$target]);
        }
        return true;
    }
}
