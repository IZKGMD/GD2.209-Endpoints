<?php
declare(strict_types=1);

namespace MuchoCore\Social;

use PDO;

final readonly class MessageRepository
{
    private const PAGE_SIZE = 10;

    public function __construct(private PDO $pdo) {}

    public function isMessageBanned(int $accountId): bool
    {
        return \MuchoCore\Moderation\Gd2209Restrictions::active(
            $this->pdo, $accountId, 'message'
        );
    }

    public function send(
        int $from,
        int $to,
        string $subject,
        string $body
    ): bool {
        $q = $this->pdo->prepare(
            'INSERT INTO messages
             (account_id,to_account_id,subject,body)
             VALUES (:f,:t,:s,:b)'
        );

        return $q->execute([
            'f'=>$from,
            't'=>$to,
            's'=>$subject,
            'b'=>$body,
        ]);
    }

    public function list(
        int $accountId,
        bool $sent,
        int $page
    ): array {
        $offset = max(0, $page) * self::PAGE_SIZE;

        if ($sent) {
            $sql =
                "SELECT m.id,m.account_id,m.to_account_id,
                        m.subject,m.is_read,m.created_at,
                        p.user_id AS to_user_id,
                        a.username AS to_username,
                        COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), '') AS to_clan_tag
                 FROM messages m
                 JOIN profiles p
                   ON p.account_id=m.to_account_id
                 JOIN accounts a
                   ON a.account_id=m.to_account_id
                 WHERE m.account_id=:id
                   AND m.is_sender_deleted=0
                 ORDER BY m.id DESC
                 LIMIT 10 OFFSET {$offset}";
        } else {
            $sql =
                "SELECT m.id,m.account_id,m.to_account_id,
                        m.subject,m.is_read,m.created_at,
                        p.user_id,a.username,
                        COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), '') AS clan_tag
                 FROM messages m
                 JOIN profiles p
                   ON p.account_id=m.account_id
                 JOIN accounts a
                   ON a.account_id=m.account_id
                 WHERE m.to_account_id=:id
                   AND m.is_receiver_deleted=0
                 ORDER BY m.id DESC
                 LIMIT 10 OFFSET {$offset}";
        }

        $q = $this->pdo->prepare($sql);
        $q->execute(['id'=>$accountId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(int $accountId, bool $sent): int
    {
        $where = $sent
            ? 'account_id=:id AND is_sender_deleted=0'
            : 'to_account_id=:id AND is_receiver_deleted=0';

        $q = $this->pdo->prepare(
            "SELECT COUNT(*) FROM messages WHERE $where"
        );
        $q->execute(['id'=>$accountId]);
        return (int)$q->fetchColumn();
    }

    public function read(int $messageId, int $accountId): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT m.*,
                    pf.user_id,af.username,
                    pt.user_id AS to_user_id,
                    at.username AS to_username
             FROM messages m
             JOIN profiles pf ON pf.account_id=m.account_id
             JOIN accounts af ON af.account_id=m.account_id
             JOIN profiles pt ON pt.account_id=m.to_account_id
             JOIN accounts at ON at.account_id=m.to_account_id
             WHERE m.id=:mid
               AND (
                    m.account_id=:sender_aid
                    OR m.to_account_id=:receiver_aid
               )
             LIMIT 1'
        );
        $q->execute([
            'mid'=>$messageId,
            'sender_aid'=>$accountId,
            'receiver_aid'=>$accountId
        ]);
        $m = $q->fetch(PDO::FETCH_ASSOC);

        if (!$m) {
            return null;
        }

        if (
            (int)$m['to_account_id'] === $accountId &&
            (int)$m['is_read'] === 0
        ) {
            $this->pdo->prepare(
                'UPDATE messages SET is_read=1 WHERE id=:id'
            )->execute(['id'=>$messageId]);

            $m['is_read'] = 1;
        }

        return $m;
    }

    public function delete(
        int $messageId,
        int $accountId,
        bool $sent
    ): bool {
        $field = $sent
            ? 'is_sender_deleted'
            : 'is_receiver_deleted';

        $owner = $sent
            ? 'account_id'
            : 'to_account_id';

        $q = $this->pdo->prepare(
            "UPDATE messages
             SET $field=1
             WHERE id=:id AND $owner=:aid"
        );
        $q->execute(['id'=>$messageId,'aid'=>$accountId]);

        return $q->rowCount() > 0;
    }
}
