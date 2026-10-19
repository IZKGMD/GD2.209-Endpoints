<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\GdRelationshipEncoder;
use PDO;
use Throwable;

/**
 * Persistent follow and mute operations for the GD 2.209 endpoint family.
 *
 * The semantics and permission boundaries are implemented server-side.
 * Native 2.209 response framing remains provisional until client captures.
 */
final readonly class FollowingService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
        private GdRelationshipEncoder $encoder,
    ) {}

    private function authorize(int $accountId, string $credential): void
    {
        $this->auth->authenticate($accountId, $credential);
    }

    private function verifyPeer(int $accountId, int $target): bool
    {
        if ($accountId <= 0 || $target <= 0 || $accountId === $target) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT 1 FROM accounts WHERE account_id=:target AND is_active=1 AND is_banned=0 LIMIT 1'
        );
        $stmt->execute(['target' => $target]);
        if ($stmt->fetchColumn() === false) {
            return false;
        }

        $blocked = $this->db->prepare(
            'SELECT 1 FROM blocks
             WHERE (account_id=:me1 AND blocked_account_id=:peer1)
                OR (account_id=:peer2 AND blocked_account_id=:me2)
             LIMIT 1'
        );
        $blocked->execute([
            'me1' => $accountId, 'peer1' => $target,
            'peer2' => $target, 'me2' => $accountId,
        ]);
        return $blocked->fetchColumn() === false;
    }

    public function follow(int $accountId, string $credential, int $target): bool
    {
        $this->authorize($accountId, $credential);
        if (!$this->verifyPeer($accountId, $target)) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO mucho_follows (follower_account_id, followed_account_id)
                 VALUES (:me,:target)'
            );
            $stmt->execute(['me' => $accountId, 'target' => $target]);

            if ($stmt->rowCount() === 1) {
                // A genuine new follow is visible to its recipient. Duplicate
                // requests do not create repeated notifications.
                $notify = $this->db->prepare(
                    'INSERT IGNORE INTO mucho_notifications
                        (account_id,actor_account_id,notification_type,source_id)
                     VALUES (:target,:actor,:type,:source)'
                );
                $notify->execute([
                    'target' => $target,
                    'actor' => $accountId,
                    'type' => 'follow',
                    'source' => $accountId,
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

    public function unfollow(int $accountId, string $credential, int $target): bool
    {
        $this->authorize($accountId, $credential);
        if ($target <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM mucho_follows WHERE follower_account_id=:me AND followed_account_id=:target'
        );
        $stmt->execute(['me' => $accountId, 'target' => $target]);
        return true;
    }

    public function mute(int $accountId, string $credential, int $target, bool $muted): bool
    {
        $this->authorize($accountId, $credential);
        if ($target <= 0 || $target === $accountId) {
            return false;
        }

        if (!$muted) {
            $stmt = $this->db->prepare(
                'DELETE FROM mucho_muted_users WHERE account_id=:me AND muted_account_id=:target'
            );
            $stmt->execute(['me' => $accountId, 'target' => $target]);
            return true;
        }

        if (!$this->verifyPeer($accountId, $target)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO mucho_muted_users (account_id, muted_account_id)
             VALUES (:me,:target)'
        );
        $stmt->execute(['me' => $accountId, 'target' => $target]);
        return true;
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,offset:int}
     */
    public function list(int $accountId, string $credential, int $page, bool $followers): array
    {
        $this->authorize($accountId, $credential);
        $page = max(0, min(1000, $page));
        $offset = $page * 20;
        $source = $followers ? 'followed_account_id' : 'follower_account_id';
        $peer = $followers ? 'follower_account_id' : 'followed_account_id';

        $total = $this->db->prepare(
            "SELECT COUNT(*) FROM mucho_follows WHERE {$source}=:me"
        );
        $total->execute(['me' => $accountId]);
        $count = (int)$total->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT a.account_id,a.username,p.user_id,p.cube,p.color1,p.color2,p.special,
                    0 AS is_new
             FROM mucho_follows f
             JOIN accounts a ON a.account_id=f.{$peer}
             JOIN profiles p ON p.account_id=a.account_id
             WHERE f.{$source}=:me AND a.is_active=1 AND a.is_banned=0
             ORDER BY f.created_at DESC, a.account_id DESC
             LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['me' => $accountId]);
        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $count,
            'offset' => $offset,
        ];
    }

    public function encodedList(
        int $accountId,
        string $credential,
        int $page,
        bool $followers
    ): string {
        $list = $this->list($accountId, $credential, $page, $followers);
        if ($list['rows'] === []) {
            return '-2';
        }

        // Existing MuchoCore user wire encoder is an interim 2.2 format.
        return $this->encoder->users($list['rows'])
            . '#' . $list['total'] . ':' . $list['offset'] . ':20';
    }

    /**
     * Client-side follow state can be reconciled after login by reading
     * the authoritative server state. Never bulk-write unvalidated IDs.
     */
    public function sync(int $accountId, string $credential): string
    {
        return $this->encodedList($accountId, $credential, 0, false);
    }
}
