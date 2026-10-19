<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * GD 2.209 server-side thread semantics.
 *
 * Query/wire framing remains provisional until redacted official captures.
 * All membership and invitation decisions are enforced by the database.
 */
final readonly class ThreadService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
    ) {}

    private function authorize(int $accountId, string $credential): void
    {
        $this->auth->authenticate($accountId, $credential);
    }

    private function threadForMember(int $accountId, int $threadId): ?array
    {
        if ($threadId < 1) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT t.id,t.title,t.description,t.emote,t.invite_owner_only,
                    t.is_dm,t.owner_account_id,t.is_closed,t.updated_at,
                    m.last_cleared_message_id,m.last_read_message_id
             FROM mucho_threads t
             INNER JOIN mucho_thread_members m ON m.thread_id=t.id
             WHERE t.id=:thread AND m.account_id=:account
             LIMIT 1'
        );
        $stmt->execute(['thread' => $threadId, 'account' => $accountId]);
        $thread = $stmt->fetch(PDO::FETCH_ASSOC);
        return $thread ?: null;
    }

    private function validTitle(string $title): ?string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 120 ||
            preg_match('/[\x00-\x1F\x7F]/', $title)) {
            return null;
        }
        return $title;
    }

    public function create(int $accountId, string $credential, string $title): int
    {
        $this->authorize($accountId, $credential);
        $title = $this->validTitle($title);
        if ($title === null) {
            return 0;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO mucho_threads (owner_account_id,title)
                 VALUES (:owner,:title)'
            );
            $stmt->execute(['owner' => $accountId, 'title' => $title]);
            $id = (int)$this->db->lastInsertId();
            $stmt = $this->db->prepare(
                'INSERT INTO mucho_thread_members (thread_id,account_id)
                 VALUES (:thread,:owner)'
            );
            $stmt->execute(['thread' => $id, 'owner' => $accountId]);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function modify(
        int $accountId,
        string $credential,
        int $threadId,
        string $action,
        string $title = ''
    ): bool {
        $this->authorize($accountId, $credential);
        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || (int)$thread['owner_account_id'] !== $accountId) {
            return false;
        }

        if ($action === 'rename') {
            $title = $this->validTitle($title);
            if ($title === null || (int)$thread['is_closed'] === 1) {
                return false;
            }
            $stmt = $this->db->prepare(
                'UPDATE mucho_threads SET title=:title
                 WHERE id=:thread AND owner_account_id=:owner AND is_closed=0'
            );
            $stmt->execute([
                'title' => $title, 'thread' => $threadId, 'owner' => $accountId,
            ]);
            return true;
        }
        if ($action === 'close') {
            $stmt = $this->db->prepare(
                'UPDATE mucho_threads SET is_closed=1
                 WHERE id=:thread AND owner_account_id=:owner'
            );
            $stmt->execute(['thread' => $threadId, 'owner' => $accountId]);
            return true;
        }
        return false;
    }

    public function leave(int $accountId, string $credential, int $threadId): bool
    {
        $this->authorize($accountId, $credential);
        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || (int)$thread['owner_account_id'] === $accountId) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare(
                'SELECT COALESCE(MAX(id),0) FROM mucho_thread_messages
                 WHERE thread_id=:thread'
            );
            $q->execute(['thread'=>$threadId]);
            $latest = (int)$q->fetchColumn();
            $former = $this->db->prepare(
                "INSERT INTO mucho_thread_former_members
                 (thread_id,account_id,reason,last_visible_message_id)
                 VALUES (:thread,:account,'left',:latest)
                 ON DUPLICATE KEY UPDATE reason='left',
                   last_visible_message_id=VALUES(last_visible_message_id),
                   left_at=UTC_TIMESTAMP()"
            );
            $former->execute([
                'thread'=>$threadId,'account'=>$accountId,'latest'=>$latest
            ]);
            $stmt = $this->db->prepare(
                'DELETE FROM mucho_thread_members
                 WHERE thread_id=:thread AND account_id=:account'
            );
            $stmt->execute(['thread'=>$threadId,'account'=>$accountId]);
            if ($stmt->rowCount() !== 1) {
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

    public function invite(int $accountId, string $credential, int $threadId, int $target): bool
    {
        $this->authorize($accountId, $credential);
        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || (int)$thread['is_closed'] === 1 ||
            (int)$thread['owner_account_id'] !== $accountId ||
            $target <= 0 || $target === $accountId) {
            return false;
        }

        $valid = $this->db->prepare(
            'SELECT 1 FROM accounts WHERE account_id=:target AND is_active=1 AND is_banned=0'
        );
        $valid->execute(['target' => $target]);
        if ($valid->fetchColumn() === false) {
            return false;
        }

        $blocks = $this->db->prepare(
            'SELECT 1 FROM blocks WHERE
                (account_id=:a1 AND blocked_account_id=:b1)
                OR (account_id=:b2 AND blocked_account_id=:a2)
             LIMIT 1'
        );
        $blocks->execute(['a1'=>$accountId,'b1'=>$target,'b2'=>$target,'a2'=>$accountId]);
        if ($blocks->fetchColumn() !== false) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $members = $this->db->prepare(
                'SELECT COUNT(*) FROM mucho_thread_members WHERE thread_id=:thread'
            );
            $members->execute(['thread' => $threadId]);
            if ((int)$members->fetchColumn() >= 32) {
                $this->db->rollBack();
                return false;
            }

            $joined = $this->db->prepare(
                'SELECT 1 FROM mucho_thread_members WHERE thread_id=:thread AND account_id=:target'
            );
            $joined->execute(['thread' => $threadId,'target' => $target]);
            if ($joined->fetchColumn() !== false) {
                $this->db->rollBack();
                return false;
            }

            $stmt = $this->db->prepare(
                "INSERT INTO mucho_thread_invites
                    (thread_id,inviter_account_id,invitee_account_id,status)
                 VALUES (:thread,:sender,:target,'pending')
                 ON DUPLICATE KEY UPDATE
                    inviter_account_id=VALUES(inviter_account_id),
                    status='pending'"
            );
            $stmt->execute([
                'thread'=>$threadId,'sender'=>$accountId,'target'=>$target,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function respondInvite(
        int $accountId,
        string $credential,
        int $invitationId,
        bool $accept
    ): bool {
        $this->authorize($accountId, $credential);
        if ($invitationId <= 0) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT i.thread_id,t.is_closed
                 FROM mucho_thread_invites i
                 JOIN mucho_threads t ON t.id=i.thread_id
                 WHERE i.id=:invite AND i.invitee_account_id=:account
                   AND i.status='pending'
                 FOR UPDATE"
            );
            $stmt->execute(['invite'=>$invitationId,'account'=>$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();
                return false;
            }

            $threadId = (int)$row['thread_id'];
            if ($accept) {
                if ((int)$row['is_closed'] === 1) {
                    $this->db->rollBack();
                    return false;
                }

                $members = $this->db->prepare(
                    'SELECT COUNT(*) FROM mucho_thread_members WHERE thread_id=:thread'
                );
                $members->execute(['thread'=>$threadId]);
                if ((int)$members->fetchColumn() >= 32) {
                    $this->db->rollBack();
                    return false;
                }

                $join = $this->db->prepare(
                    'INSERT IGNORE INTO mucho_thread_members (thread_id,account_id)
                     VALUES (:thread,:account)'
                );
                $join->execute(['thread'=>$threadId,'account'=>$accountId]);
            }

            $update = $this->db->prepare(
                'UPDATE mucho_thread_invites SET status=:status
                 WHERE id=:invite AND invitee_account_id=:account'
            );
            $update->execute([
                'status'=>$accept?'accepted':'declined',
                'invite'=>$invitationId,
                'account'=>$accountId,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }


    /** Resolve invitation by native thread ID, not by internal invite row ID. */
    public function respondThreadInvite(
        int $accountId, string $credential,
        int $threadId, bool $accept
    ): bool {
        $this->authorize($accountId, $credential);
        if ($threadId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            "SELECT id FROM mucho_thread_invites
             WHERE thread_id=:thread AND invitee_account_id=:account
               AND status='pending' LIMIT 1"
        );
        $stmt->execute(['thread'=>$threadId,'account'=>$accountId]);
        $inviteId = $stmt->fetchColumn();
        return $inviteId !== false && $this->respondInvite(
            $accountId, $credential, (int)$inviteId, $accept
        );
    }

    /** Owner-only deletion of one's own message, scoped to an active group. */
    public function deleteOwnMessage(
        int $accountId, string $credential,
        int $threadId, int $messageId
    ): bool {
        $this->authorize($accountId, $credential);
        if ($threadId <= 0 || $messageId <= 0 ||
            $this->threadForMember($accountId, $threadId) === null) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare(
                'SELECT sender_account_id FROM mucho_thread_messages
                 WHERE id=:id AND thread_id=:thread FOR UPDATE'
            );
            $lock->execute(['id'=>$messageId,'thread'=>$threadId]);
            if ((int)$lock->fetchColumn() !== $accountId) {
                $this->db->rollBack();
                return false;
            }
            $del = $this->db->prepare(
                'DELETE FROM mucho_thread_messages
                 WHERE id=:id AND thread_id=:thread AND sender_account_id=:actor'
            );
            $del->execute(['id'=>$messageId,'thread'=>$threadId,'actor'=>$accountId]);
            $ok = $del->rowCount() === 1;
            if ($ok) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
            return $ok;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function send(
        int $accountId,
        string $credential,
        int $threadId,
        string $body
    ): bool {
        return $this->sendWithId($accountId, $credential, $threadId, $body) > 0;
    }

    /** Persist a message and return the actual ID required by GD 2.209. */
    public function sendWithId(
        int $accountId,
        string $credential,
        int $threadId,
        string $body
    ): int {
        $this->authorize($accountId, $credential);
        if (\MuchoCore\Moderation\Gd2209Restrictions::active(
            $this->db, $accountId, 'message'
        )) {
            return 0;
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body, 'UTF-8') > 4000 ||
            strlen($body) > 16000) {
            return 0;
        }

        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || (int)$thread['is_closed'] === 1) {
            return 0;
        }

        $this->db->beginTransaction();
        try {
            // Serialize against owner-side close/rename and member removals.
            $lock = $this->db->prepare(
                'SELECT t.is_closed FROM mucho_threads t
                 JOIN mucho_thread_members m ON m.thread_id=t.id
                 WHERE t.id=:thread AND m.account_id=:sender FOR UPDATE'
            );
            $lock->execute(['thread'=>$threadId,'sender'=>$accountId]);
            $closed = $lock->fetchColumn();
            if ($closed === false || (int)$closed !== 0) {
                $this->db->rollBack();
                return 0;
            }
            $rate = $this->db->prepare(
                'SELECT COUNT(*) FROM mucho_thread_messages
                 WHERE sender_account_id=:actor
                   AND created_at>UTC_TIMESTAMP()-INTERVAL 60 SECOND'
            );
            $rate->execute(['actor'=>$accountId]);
            if ((int)$rate->fetchColumn() >= 20) {
                $this->db->rollBack();
                return 0;
            }
            $stmt = $this->db->prepare(
                'INSERT INTO mucho_thread_messages (thread_id,sender_account_id,body)
                 VALUES (:thread,:sender,:body)'
            );
            $stmt->execute(['thread'=>$threadId,'sender'=>$accountId,'body'=>$body]);
            $messageId = (int)$this->db->lastInsertId();
            if ($messageId <= 0) {
                throw new RuntimeException('Message not persisted');
            }
            $touch = $this->db->prepare(
                'UPDATE mucho_threads SET updated_at=UTC_TIMESTAMP() WHERE id=:thread'
            );
            $touch->execute(['thread'=>$threadId]);
            // UPDATE rowCount can be zero when the timestamp has not changed.
            $this->db->commit();
            return $messageId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }


    /**
     * Private 2.209 thread view. The caller MUST be an active member.
     * Page-window cursor support: beforeID and afterID, not aroundID yet.
     * All rows are read only after server-side account authentication.
     *
     * @return array{thread:array<string,mixed>,messages:array,members:array,invites:array}
     */
    public function nativeSnapshot(
        int $accountId, string $credential, int $threadId,
        int $before = 0, int $after = 0, int $around = 0
    ): array {
        $this->authorize($accountId, $credential);
        $thread = $this->threadForMember($accountId, $threadId);
        if ($thread === null || $before < 0 || $after < 0 || $around < 0 ||
            (int)($before > 0) + (int)($after > 0) + (int)($around > 0) > 1) {
            throw new RuntimeException('Invalid thread cursor or access denied');
        }

        $args = ['thread'=>$threadId,
            'cleared'=>(int)$thread['last_cleared_message_id']];
        $select = 'SELECT m.id,m.thread_id,m.sender_account_id,m.body,
                          m.created_at,a.username
                   FROM mucho_thread_messages m
                   JOIN accounts a ON a.account_id=m.sender_account_id
                   WHERE m.thread_id=:thread AND m.id>:cleared';

        if ($around > 0) {
            $args['cursor'] = $around;
            // Center on the requested message and include newer context.
            // Clamp the window to at most 50 persisted messages.
            $older = $this->db->prepare(
                $select . ' AND m.id<=:cursor ORDER BY m.id DESC LIMIT 25'
            );
            $older->execute($args);
            $newer = $this->db->prepare(
                $select . ' AND m.id>:cursor ORDER BY m.id ASC LIMIT 25'
            );
            $newer->execute($args);
            $messages = array_merge(
                $older->fetchAll(PDO::FETCH_ASSOC),
                $newer->fetchAll(PDO::FETCH_ASSOC)
            );
            usort($messages, static fn(array $a,array $b): int =>
                (int)$b['id'] <=> (int)$a['id']
            );
        } else {
            $filter = '';
            if ($before > 0) {
                $filter = ' AND m.id<:cursor';
                $args['cursor'] = $before;
            } elseif ($after > 0) {
                $filter = ' AND m.id>:cursor';
                $args['cursor'] = $after;
            }
            $stmt = $this->db->prepare(
                $select . $filter . ' ORDER BY m.id DESC LIMIT 50'
            );
            $stmt->execute($args);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Native metadata field 6 tells the client when "Show Earlier"
        // should be available. Never hide history if older rows exist.
        $hasOlder = false;
        if ($messages !== []) {
            $lowest = min(array_map(
                static fn(array $message): int => (int)$message['id'],
                $messages
            ));
            $olderCheck = $this->db->prepare(
                'SELECT 1 FROM mucho_thread_messages
                 WHERE thread_id=:thread AND id>:cleared AND id<:lowest LIMIT 1'
            );
            $olderCheck->execute([
                'thread'=>$threadId,
                'cleared'=>(int)$thread['last_cleared_message_id'],
                'lowest'=>$lowest
            ]);
            $hasOlder = $olderCheck->fetchColumn() !== false;
        }
        $thread['has_older_messages'] = $hasOlder ? 1 : 0;

        $stmt = $this->db->prepare(
            'SELECT m.account_id,a.username,p.user_id,p.cube,p.color1,
                    p.color2,p.special
             FROM mucho_thread_members m
             JOIN accounts a ON a.account_id=m.account_id
             JOIN profiles p ON p.account_id=a.account_id
             WHERE m.thread_id=:thread
             ORDER BY m.joined_at ASC,m.account_id ASC LIMIT 32'
        );
        $stmt->execute(['thread'=>$threadId]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            "SELECT i.id,i.inviter_account_id,i.invitee_account_id,a.username,
                    p.user_id,p.cube,p.color1,p.color2,p.special
             FROM mucho_thread_invites i
             JOIN accounts a ON a.account_id=i.invitee_account_id
             JOIN profiles p ON p.account_id=i.invitee_account_id
             WHERE i.thread_id=:thread AND i.status='pending'
             ORDER BY i.id DESC LIMIT 32"
        );
        $stmt->execute(['thread'=>$threadId]);
        return [
            'thread'=>$thread, 'messages'=>$messages,
            'members'=>$members, 'invites'=>$stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }


    /**
     * Native 2.209 thread listing with its proper 10-per-page envelope.
     * MuchoCore currently stores group threads only; DM-only filter returns
     * zero results rather than pretending a group is a DM.
     */
    public function nativeThreads(
        int $accountId, string $credential, int $page, int $filter,
        string $userFilter = ''
    ): array {
        $this->authorize($accountId, $credential);
        if ($page < 0 || $page > 1000 || !in_array($filter, [0,1,2], true)) {
            throw new RuntimeException('Invalid thread filter');
        }
        $offset = $page * 10;
        $extra = [];
        $targetSql = '';
        if ($userFilter !== '') {
            if (strlen($userFilter) > 300 ||
                !preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,30}$/D', $userFilter)) {
                throw new RuntimeException('Invalid thread user filter');
            }
            $ids = array_values(array_unique(array_map('intval', explode(',', $userFilter))));
            $binds = [];
            foreach ($ids as $i => $id) {
                if ($id <= 0) {
                    throw new RuntimeException('Invalid user account');
                }
                $key = 'user_filter_' . $i;
                $binds[] = ':' . $key;
                $extra[$key] = $id;
            }
            $targetSql = ' AND EXISTS (
              SELECT 1 FROM mucho_thread_members fm
              WHERE fm.thread_id=t.id
                AND fm.account_id IN (' . implode(',', $binds) . ')
            )';
        }
        $invites = $this->db->prepare(
            "SELECT COUNT(*) FROM mucho_thread_invites i
             JOIN mucho_threads t ON t.id=i.thread_id
             WHERE i.invitee_account_id=:actor AND i.status='pending'
               AND t.is_closed=0"
        );
        $invites->execute(['actor'=>$accountId]);
        $inviteCount = (int)$invites->fetchColumn();
        $filterSql = $filter === 1 ? ' AND t.is_dm=1'
            : ($filter === 2 ? ' AND t.is_dm=0' : '');
        $where = 'm.account_id=:actor' . $filterSql . $targetSql;

        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM mucho_threads t
             JOIN mucho_thread_members m ON m.thread_id=t.id
             WHERE ' . $where
        );
        $count->execute(array_merge(['actor'=>$accountId], $extra));
        $total = (int)$count->fetchColumn();

        $sql = 'SELECT
                t.id,t.title,t.description,t.emote,t.is_dm,
                t.owner_account_id,t.is_closed,t.updated_at,
                m.is_muted,m.is_pinned,
                (SELECT COUNT(*) FROM mucho_thread_members mt
                 WHERE mt.thread_id=t.id) AS member_count,
                (SELECT COUNT(*) FROM mucho_thread_messages un
                 WHERE un.thread_id=t.id AND un.id>m.last_read_message_id) AS unread_count,
                (SELECT lm.sender_account_id
                 FROM mucho_thread_messages lm WHERE lm.thread_id=t.id
                 ORDER BY lm.id DESC LIMIT 1) AS last_sender,
                (SELECT UNIX_TIMESTAMP(lm.created_at)
                 FROM mucho_thread_messages lm WHERE lm.thread_id=t.id
                 ORDER BY lm.id DESC LIMIT 1) AS last_timestamp,
                (SELECT sa.username FROM mucho_thread_messages lm
                 JOIN accounts sa ON sa.account_id=lm.sender_account_id
                 WHERE lm.thread_id=t.id ORDER BY lm.id DESC LIMIT 1) AS last_username,
                peer.account_id AS peer_account_id,
                peer.username AS peer_username,
                pp.cube AS peer_cube,pp.color1 AS peer_color1,
                pp.color2 AS peer_color2,pp.special AS peer_special
            FROM mucho_threads t
            JOIN mucho_thread_members m ON m.thread_id=t.id
            LEFT JOIN accounts peer ON peer.account_id=(
              CASE WHEN t.dm_low=:actor_peer THEN t.dm_high ELSE t.dm_low END
            ) AND t.is_dm=1
            LEFT JOIN profiles pp ON pp.account_id=peer.account_id
            WHERE ' . $where .
            ' ORDER BY m.is_pinned DESC,t.updated_at DESC,t.id DESC LIMIT 10 OFFSET ' . $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge(
            ['actor'=>$accountId,'actor_peer'=>$accountId], $extra
        ));
        return [
            'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'=>$total, 'offset'=>$offset, 'invites'=>$inviteCount
        ];
    }

    /** A member or an invited player may view the member roster. */
    public function nativeMemberView(
        int $accountId, string $credential, int $threadId
    ): array {
        $this->authorize($accountId,$credential);
        $member = $this->threadForMember($accountId,$threadId);
        if ($member === null) {
            $invite = $this->db->prepare(
                "SELECT 1 FROM mucho_thread_invites
                 WHERE thread_id=:thread AND invitee_account_id=:account
                   AND status='pending' LIMIT 1"
            );
            $invite->execute(['thread'=>$threadId,'account'=>$accountId]);
            if ($invite->fetchColumn() === false) {
                throw new RuntimeException('Private member roster');
            }
        }
        $members = $this->db->prepare(
            'SELECT m.account_id,a.username,p.user_id,p.cube,p.color1,
                    p.color2,p.special
             FROM mucho_thread_members m JOIN accounts a
                 ON a.account_id=m.account_id
             JOIN profiles p ON p.account_id=a.account_id
             WHERE m.thread_id=:thread
             ORDER BY m.joined_at ASC LIMIT 32'
        );
        $members->execute(['thread'=>$threadId]);
        $invites = $this->db->prepare(
            "SELECT i.id,i.invitee_account_id,i.inviter_account_id,a.username,
                    p.user_id,p.cube,p.color1,p.color2,p.special
             FROM mucho_thread_invites i
             JOIN accounts a ON a.account_id=i.invitee_account_id
             JOIN profiles p ON p.account_id=i.invitee_account_id
             WHERE i.thread_id=:thread AND i.status='pending'
             ORDER BY i.id DESC LIMIT 32"
        );
        $invites->execute(['thread'=>$threadId]);
        return ['members'=>$members->fetchAll(PDO::FETCH_ASSOC),
            'invites'=>$invites->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function threads(int $accountId, string $credential, int $page): array
    {
        $this->authorize($accountId, $credential);
        $offset = max(0, min($page, 1000)) * 20;
        $stmt = $this->db->prepare(
            "SELECT t.id,t.title,t.owner_account_id,t.is_closed,t.updated_at
             FROM mucho_threads t
             INNER JOIN mucho_thread_members m ON m.thread_id=t.id
             WHERE m.account_id=:account
             ORDER BY t.updated_at DESC,t.id DESC LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['account'=>$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function messages(int $accountId, string $credential, int $threadId, int $page): array
    {
        $this->authorize($accountId, $credential);
        if (!$this->threadForMember($accountId, $threadId)) {
            return [];
        }
        $offset = max(0, min($page, 1000)) * 20;
        $stmt = $this->db->prepare(
            "SELECT m.id,m.thread_id,m.sender_account_id,m.body,m.created_at,a.username
             FROM mucho_thread_messages m
             JOIN accounts a ON a.account_id=m.sender_account_id
             WHERE m.thread_id=:thread
             ORDER BY m.id DESC LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['thread'=>$threadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function members(int $accountId, string $credential, int $threadId): array
    {
        $this->authorize($accountId, $credential);
        if (!$this->threadForMember($accountId, $threadId)) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT m.account_id,a.username,p.user_id,p.cube,p.color1,p.color2,p.special
             FROM mucho_thread_members m
             JOIN accounts a ON a.account_id=m.account_id
             JOIN profiles p ON p.account_id=a.account_id
             WHERE m.thread_id=:thread ORDER BY m.joined_at ASC,m.account_id ASC LIMIT 32'
        );
        $stmt->execute(['thread'=>$threadId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function invites(int $accountId, string $credential): array
    {
        $this->authorize($accountId, $credential);
        $stmt = $this->db->prepare(
            "SELECT i.id,i.thread_id,i.inviter_account_id,
                    t.title,t.description,t.emote,t.is_dm,
                    i.created_at,a.username AS inviter_username,
                    p.cube AS inviter_cube,p.color1 AS inviter_color1,
                    p.color2 AS inviter_color2,p.special AS inviter_special
             FROM mucho_thread_invites i
             JOIN mucho_threads t ON t.id=i.thread_id
             JOIN accounts a ON a.account_id=i.inviter_account_id
             JOIN profiles p ON p.account_id=i.inviter_account_id
             WHERE i.invitee_account_id=:account AND i.status='pending'
               AND t.is_closed=0
             ORDER BY i.id DESC LIMIT 50"
        );
        $stmt->execute(['account'=>$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Native GD 2.209 search: Base64 query is decoded at HTTP boundary.
     * beforeID is a message-ID cursor. Report real remaining 20-item pages.
     *
     * @return array{rows:array,remaining:int}
     */
    public function nativeSearch(
        int $accountId, string $credential, int $threadId,
        string $term, int $beforeId
    ): array {
        $this->authorize($accountId,$credential);
        if ($beforeId < 0 || $beforeId > PHP_INT_MAX
            || !$this->threadForMember($accountId,$threadId)) {
            throw new RuntimeException('Thread search access denied');
        }
        $term = trim($term);
        if (mb_strlen($term,'UTF-8') < 2 || mb_strlen($term,'UTF-8') > 120) {
            throw new RuntimeException('Invalid search query');
        }
        $state = $this->threadForMember($accountId, $threadId);
        $needle = '%' . addcslashes($term, '%_\\') . '%';
        $condition = $beforeId > 0 ? ' AND m.id < :before' : '';
        $args = ['thread'=>$threadId,'needle'=>$needle,
            'cleared'=>(int)$state['last_cleared_message_id']];
        if ($beforeId > 0) {
            $args['before'] = $beforeId;
        }
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM mucho_thread_messages m
             WHERE m.thread_id=:thread AND m.id>:cleared AND m.body LIKE :needle' . $condition
        );
        $count->execute($args);
        $total = (int)$count->fetchColumn();

        $rows = $this->db->prepare(
            'SELECT m.id,m.thread_id,m.sender_account_id,m.body,m.created_at,
                    a.username
             FROM mucho_thread_messages m
             JOIN accounts a ON a.account_id=m.sender_account_id
             WHERE m.thread_id=:thread AND m.id>:cleared AND m.body LIKE :needle' . $condition .
            ' ORDER BY m.id DESC LIMIT 20'
        );
        $rows->execute($args);
        return [
            'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC),
            'remaining'=>max(0,(int)ceil($total / 20)-1),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(
        int $accountId,
        string $credential,
        int $threadId,
        string $term,
        int $page
    ): array {
        $this->authorize($accountId, $credential);
        $term = trim($term);
        if (mb_strlen($term, 'UTF-8') < 2 || mb_strlen($term, 'UTF-8') > 120 ||
            !$this->threadForMember($accountId, $threadId)) {
            return [];
        }
        $escaped = addcslashes($term, '%_\\');
        $offset = max(0, min($page, 1000)) * 20;
        $stmt = $this->db->prepare(
            "SELECT m.id,m.thread_id,m.sender_account_id,m.body,m.created_at,a.username
             FROM mucho_thread_messages m
             JOIN accounts a ON a.account_id=m.sender_account_id
             WHERE m.thread_id=:thread AND m.body LIKE :needle
             ORDER BY m.id DESC LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['thread'=>$threadId,'needle'=>'%'.$escaped.'%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
