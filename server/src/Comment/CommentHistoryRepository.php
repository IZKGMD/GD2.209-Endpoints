<?php
declare(strict_types=1);

namespace MuchoCore\Comment;

use PDO;
use MuchoCore\V71\SchemaInspector;
use RuntimeException;

final class CommentHistoryRepository
{
    private SchemaInspector $schema;

    public function __construct(private readonly PDO $db)
    {
        $this->schema = new SchemaInspector($db);
    }

    public function byUserId(
        int $accountId,
        int $offset,
        int $count,
        bool $orderByLikes
    ): array {
        if (!$this->schema->tableExists('comments')) {
            throw new RuntimeException('comments table missing');
        }

        $accountJoin = '';
        $username = "'Unknown' AS userName";

        if (
            $this->schema->tableExists('accounts') &&
            $this->schema->columnExists('accounts', 'account_id') &&
            $this->schema->columnExists('accounts', 'username')
        ) {
            $accountJoin =
                ' LEFT JOIN accounts a ON a.account_id = c.account_id ';

            $username =
                "COALESCE(a.username, 'Unknown') AS userName";
        }

        $levelJoin = '';
        $levelWhere = '';

        if (
            $this->schema->tableExists('levels') &&
            $this->schema->columnExists('levels', 'level_id')
        ) {
            $levelJoin =
                ' LEFT JOIN levels l ON l.level_id = c.level_id ';

            if ($this->schema->columnExists('levels', 'is_deleted')) {
                $levelWhere .= ' AND COALESCE(l.is_deleted, 0) = 0 ';
            }

            if ($this->schema->columnExists('levels', 'is_unlisted')) {
                $levelWhere .= ' AND COALESCE(l.is_unlisted, 0) = 0 ';
            }
        }

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM comments c
             {$levelJoin}
             WHERE c.account_id = :account_id
             {$levelWhere}"
        );

        $countStmt->execute([
            ':account_id' => $accountId
        ]);

        $total = (int)$countStmt->fetchColumn();

        if ($total === 0) {
            return [
                'rows' => [],
                'total' => 0,
                'visible' => 0
            ];
        }

        $order = $orderByLikes
            ? 'c.likes DESC, c.id DESC'
            : 'c.id DESC';

        $stmt = $this->db->prepare(
            "SELECT
                c.level_id AS levelID,
                c.id AS commentID,
                UNIX_TIMESTAMP(c.created_at) AS timestamp,
                c.content AS comment,
                c.account_id AS userID,
                c.likes AS likes,
                c.is_spam AS isSpam,
                c.percent AS percent,

                {$username},

                0 AS icon,
                0 AS color1,
                3 AS color2,
                0 AS iconType,
                0 AS special,
                c.account_id AS extID

             FROM comments c
             {$accountJoin}
             {$levelJoin}

             WHERE c.account_id = :account_id
             {$levelWhere}

             ORDER BY {$order}
             LIMIT :count OFFSET :offset"
        );

        $stmt->bindValue(
            ':account_id',
            $accountId,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':count',
            $count,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'visible' => count($rows)
        ];
    }
}
