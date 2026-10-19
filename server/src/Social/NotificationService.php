<?php

declare(strict_types=1);

namespace MuchoCore\Social;

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Protocol\ProtocolText;
use PDO;

/**
 * Notification inbox for native 2.209 events.
 *
 * Wire layout is provisional pending captured official 2.209 requests.
 * No currency is awarded by claiming: reward rules are not yet verified.
 */
final readonly class NotificationService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
    ) {}

    public function list(int $accountId, string $credential, int $page): string
    {
        $this->auth->authenticate($accountId, $credential);
        $page = max(0, min(1000, $page));
        $offset = $page * 20;
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM mucho_notifications WHERE account_id=:account'
        );
        $count->execute(['account' => $accountId]);
        $total = (int)$count->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT n.id,n.notification_type,n.source_id,n.is_read,n.reward_claimed,
                    n.created_at,n.actor_account_id,a.username,p.user_id
             FROM mucho_notifications n
             INNER JOIN accounts a ON a.account_id=n.actor_account_id
             LEFT JOIN profiles p ON p.account_id=n.actor_account_id
             WHERE n.account_id=:account
             ORDER BY n.id DESC
             LIMIT 20 OFFSET {$offset}"
        );
        $stmt->execute(['account' => $accountId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return '-2';
        }

        $items = [];
        foreach ($rows as $row) {
            $timestamp = strtotime((string)$row['created_at']);
            // The client response keys need golden-fixture confirmation.
            $items[] = implode(':', [
                1, (int)$row['id'],
                2, ProtocolText::username((string)$row['username']),
                3, (int)($row['user_id'] ?? 0),
                4, (int)$row['actor_account_id'],
                5, (string)$row['notification_type'],
                6, (int)$row['source_id'],
                7, (int)$row['is_read'],
                8, (int)$row['reward_claimed'],
                9, $timestamp === false ? 0 : $timestamp,
            ]);
        }
        return implode('|', $items) . '#' . $total . ':' . $offset . ':20';
    }

    /**
     * Claiming verifies ownership and is idempotent, without inventing an
     * unsupported RobTop reward amount/currency. Full reward parity is pending.
     */
    public function claim(int $accountId, string $credential, int $notificationId): bool
    {
        $this->auth->authenticate($accountId, $credential);
        if ($notificationId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE mucho_notifications
             SET reward_claimed=1,is_read=1
             WHERE id=:id AND account_id=:account AND reward_claimed=0'
        );
        $stmt->execute(['id' => $notificationId, 'account' => $accountId]);
        return $stmt->rowCount() === 1;
    }
}
