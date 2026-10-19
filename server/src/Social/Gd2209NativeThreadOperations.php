<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Moderation\Gd2209Restrictions;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Native GD 2.209 start-new-thread transaction.
 *
 * Reuses one DM per unordered account pair and never adds players to groups
 * without sending their explicitly pending invitations.
 */
final readonly class Gd2209NativeThreadOperations
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
        private RelationshipRepository $relationships,
    ) {}

    /** @return list<int> */
    public static function targetIds(string $value): array
    {
        if ($value === '') {
            return [];
        }
        if (strlen($value) > 320 ||
            preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,30}$/D', $value) !== 1) {
            throw new RuntimeException('Invalid group invite target list');
        }
        $ids = array_map('intval', explode(',', $value));
        if (in_array(0, $ids, true) || count(array_unique($ids)) !== count($ids)) {
            throw new RuntimeException('Duplicate or zero group invite target');
        }
        return $ids;
    }

    /** @return array{thread_id:int,message_id:int,invites:int,is_dm:bool} */
    public function start(
        int $actor, string $credential, string $body,
        bool $isDm, int $peer, string $title,
        string $description, string $emote, array $targets
    ): array {
        $this->auth->authenticate($actor, $credential);
        if (Gd2209Restrictions::active($this->db, $actor, 'message') ||
            trim($body) === '' || strlen($body) > 16000 ||
            mb_strlen($body, 'UTF-8') > 4000) {
            throw new RuntimeException('Cannot send thread message');
        }

        // Authenticated send-rate check; no client-supplied timestamp is trusted.
        $recent = $this->db->prepare(
            'SELECT COUNT(*) FROM mucho_thread_messages
             WHERE sender_account_id=:actor AND created_at>UTC_TIMESTAMP()-INTERVAL 60 SECOND'
        );
        $recent->execute(['actor'=>$actor]);
        if ((int)$recent->fetchColumn() >= 20) {
            throw new RuntimeException('Thread message limit reached');
        }

        if ($isDm) {
            if ($peer <= 0 || $peer === $actor || $targets !== [] ||
                !$this->relationships->canMessage($actor, $peer)) {
                throw new RuntimeException('DM target denied');
            }
            $low = min($actor, $peer);
            $high = max($actor, $peer);
        } else {
            if ($peer !== 0 || count($targets) > 31 ||
                $title === '' || mb_strlen($title, 'UTF-8') > 120 ||
                strlen($title) > 480 || strlen($description) > 1024 ||
                mb_strlen($description, 'UTF-8') > 512 ||
                strlen($emote) > 60 || mb_strlen($emote, 'UTF-8') > 30 ||
                preg_match('/[\x00-\x1f\x7f]/', $title . $description . $emote)) {
                throw new RuntimeException('Invalid group metadata');
            }
            foreach ($targets as $target) {
                if (!is_int($target) || $target <= 0 || $target === $actor) {
                    throw new RuntimeException('Invalid group invitee');
                }
            }
            if (count(array_unique($targets)) !== count($targets)) {
                throw new RuntimeException('Duplicate group invitee');
            }
            $low = null;
            $high = null;
        }

        $this->db->beginTransaction();
        try {
            // Serialize a player's concurrent new-chat requests to enforce
            // the same per-minute limit even across different new threads.
            $lock = $this->db->prepare(
                'SELECT account_id FROM accounts
                 WHERE account_id=:actor AND is_active=1 AND is_banned=0
                 FOR UPDATE'
            );
            $lock->execute(['actor'=>$actor]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Inactive thread sender');
            }
            $fresh = $this->db->prepare(
                'SELECT COUNT(*) FROM mucho_thread_messages
                 WHERE sender_account_id=:actor
                   AND created_at>UTC_TIMESTAMP()-INTERVAL 60 SECOND'
            );
            $fresh->execute(['actor'=>$actor]);
            if ((int)$fresh->fetchColumn() >= 20) {
                throw new RuntimeException('Message sending rate limited');
            }
            if (!$isDm) {
                foreach ($targets as $target) {
                    $check = $this->db->prepare(
                        'SELECT 1 FROM accounts
                         WHERE account_id=:id AND is_active=1 AND is_banned=0'
                    );
                    $check->execute(['id'=>$target]);
                    if ($check->fetchColumn() === false) {
                        throw new RuntimeException('Invalid invite target');
                    }
                    $blocks = $this->db->prepare(
                        'SELECT 1 FROM blocks
                         WHERE (account_id=:a AND blocked_account_id=:b)
                            OR (account_id=:b2 AND blocked_account_id=:a2)
                         LIMIT 1'
                    );
                    $blocks->execute([
                        'a'=>$actor, 'b'=>$target, 'b2'=>$target, 'a2'=>$actor
                    ]);
                    if ($blocks->fetchColumn() !== false) {
                        throw new RuntimeException('Invitation blocked');
                    }
                }
            }

            $insert = $this->db->prepare(
                'INSERT INTO mucho_threads
                 (owner_account_id,title,is_dm,dm_low,dm_high,description,emote)
                 VALUES (:owner,:title,:is_dm,:low,:high,:description,:emote)
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
            );
            $insert->execute([
                'owner'=>$actor,
                'title'=>$isDm ? '' : $title,
                'is_dm'=>$isDm ? 1 : 0,
                'low'=>$low, 'high'=>$high,
                'description'=>$isDm ? '' : $description,
                'emote'=>$isDm ? '' : $emote,
            ]);
            $thread = (int)$this->db->lastInsertId();
            if ($thread <= 0) {
                throw new RuntimeException('Failed creating native thread');
            }
            $state = $this->db->prepare(
                'SELECT is_closed,is_dm FROM mucho_threads WHERE id=:thread FOR UPDATE'
            );
            $state->execute(['thread'=>$thread]);
            $row = $state->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['is_closed'] !== 0 ||
                (int)$row['is_dm'] !== ($isDm ? 1 : 0)) {
                throw new RuntimeException('Closed or invalid DM/group thread');
            }

            $join = $this->db->prepare(
                'INSERT IGNORE INTO mucho_thread_members (thread_id,account_id)
                 VALUES (:thread,:account)'
            );
            $join->execute(['thread'=>$thread,'account'=>$actor]);
            if ($isDm) {
                $join->execute(['thread'=>$thread,'account'=>$peer]);
            }

            $invited = 0;
            if (!$isDm) {
                $invite = $this->db->prepare(
                    "INSERT INTO mucho_thread_invites
                     (thread_id,inviter_account_id,invitee_account_id,status)
                     VALUES (:thread,:actor,:target,'pending')"
                );
                foreach ($targets as $target) {
                    $invite->execute([
                        'thread'=>$thread,'actor'=>$actor,'target'=>$target
                    ]);
                    $invited++;
                }
            }

            $post = $this->db->prepare(
                'INSERT INTO mucho_thread_messages
                 (thread_id,sender_account_id,body)
                 VALUES (:thread,:sender,:body)'
            );
            $post->execute([
                'thread'=>$thread, 'sender'=>$actor, 'body'=>$body
            ]);
            $message = (int)$this->db->lastInsertId();
            if ($message <= 0) {
                throw new RuntimeException('Message not persisted');
            }
            $this->db->commit();
            return [
                'thread_id'=>$thread, 'message_id'=>$message,
                'invites'=>$invited, 'is_dm'=>$isDm
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
