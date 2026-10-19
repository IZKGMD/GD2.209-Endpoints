<?php
declare(strict_types=1);

namespace MuchoCore\LevelList;

use PDO;
use MuchoCore\V71\SchemaInspector;

final class LevelListRepository
{
    private SchemaInspector $schema;

    public function __construct(private readonly PDO $db)
    {
        $this->schema = new SchemaInspector($db);
    }

    public function friendAccountIds(int $accountId): array
    {
        if ($accountId <= 0) {
            return [];
        }

        try {
            $rows = $this->db->prepare(
                'SELECT friend_account_id
                 FROM friends
                 WHERE account_id=:account_id
                 UNION
                 SELECT account_id
                 FROM friends
                 WHERE friend_account_id=:account_id'
            );
            $rows->execute(['account_id' => $accountId]);

            $ids = array_map(
                'intval',
                $rows->fetchAll(PDO::FETCH_COLUMN)
            );

            $ids[] = $accountId;

            return array_values(
                array_unique(
                    array_filter(
                        $ids,
                        static fn(int $id): bool => $id > 0
                    )
                )
            );
        } catch (\Throwable) {
            return [$accountId];
        }
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function search(array $filters, int $offset, int $limit, string $order): array
    {
        $where = ['l.unlisted = 0'];
        $params = [];
        $suggestedJoin = '';

        if (isset($filters['id'])) {
            $where = ['l.list_id = :list_id'];
            $params[':list_id'] = (int)$filters['id'];
        } else {
            if (isset($filters['name']) && $filters['name'] !== '') {
                $where[] = 'l.list_name LIKE :name';
                $params[':name'] = '%' . $filters['name'] . '%';
            }
            if (isset($filters['account_id'])) {
                $where[] = 'l.account_id = :account_id';
                $params[':account_id'] = (int)$filters['account_id'];
            }
            if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
                $marks = [];
                foreach (array_values($filters['account_ids']) as $i => $accountId) {
                    $key = ':account_' . $i;
                    $marks[] = $key;
                    $params[$key] = (int)$accountId;
                }
                if ($marks) {
                    $where[] = 'l.account_id IN (' . implode(',', $marks) . ')';
                }
            }
            if (isset($filters['min_created'])) {
                $where[] = 'l.created_at >= :min_created';
                $params[':min_created'] = (int)$filters['min_created'];
            }
            if (!empty($filters['rated'])) {
                $where[] = 'l.stars > 0';
            }
            if (!empty($filters['featured'])) {
                $where[] = 'l.featured > 0';
            }
            if (!empty($filters['suggested'])) {
                $suggestedJoin = "
                    LEFT JOIN (
                        SELECT list_id, MAX(created_at) AS suggested_at
                        FROM mucho_level_list_suggestions
                        GROUP BY list_id
                    ) ls
                      ON ls.list_id = l.list_id
                ";
                $where[] = 'ls.list_id IS NOT NULL';
            }
            if (!empty($filters['difficulties']) && is_array($filters['difficulties'])) {
                $marks = [];

                foreach (array_values($filters['difficulties']) as $i => $difficulty) {
                    $key = ':difficulty_' . $i;
                    $marks[] = $key;
                    $params[$key] = (int)$difficulty;
                }

                if ($marks) {
                    $where[] = 'l.difficulty IN (' . implode(',', $marks) . ')';
                }
            }
        }

        $allowedOrder = [
            'downloads' => 'l.downloads DESC, l.list_id DESC',
            'likes' => 'l.likes DESC, l.list_id DESC',
            'created' => 'l.created_at DESC, l.list_id DESC',
            'updated' => 'l.updated_at DESC, l.list_id DESC',
            'suggested' => 'ls.suggested_at DESC, l.list_id DESC',
        ];
        $orderSql = $allowedOrder[$order] ?? $allowedOrder['likes'];

        $userJoin = '';
        $userSelect = "'Unknown' AS user_name,
                       COALESCE(p.user_id, l.account_id) AS user_id,
                       l.account_id AS ext_id";

        if (
            $this->schema->tableExists('accounts') &&
            $this->schema->columnExists('accounts', 'account_id') &&
            $this->schema->columnExists('accounts', 'username')
        ) {
            $userJoin = ' LEFT JOIN accounts a ON a.account_id = l.account_id ';
            $userSelect = "COALESCE(a.username, 'Unknown') AS user_name,
                           COALESCE(p.user_id, l.account_id) AS user_id,
                           l.account_id AS ext_id";
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM mucho_level_lists l ' . $suggestedJoin . $whereSql
        );
        foreach ($params as $key => $value) {
            $count->bindValue($key, $value, PDO::PARAM_INT);
        }
        if (isset($params[':name'])) {
            $count->bindValue(':name', (string)$params[':name'], PDO::PARAM_STR);
        }
        $count->execute();
        $total = (int)$count->fetchColumn();

        $sql = "SELECT l.*, {$userSelect}
                FROM mucho_level_lists l
                LEFT JOIN profiles p ON p.account_id = l.account_id
                {$userJoin}
                {$suggestedJoin}
                {$whereSql}
                ORDER BY {$orderSql}
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $key === ':name' ? PDO::PARAM_STR : PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll() ?: [],
            'total' => $total,
        ];
    }

    /** @param list<int> $levelIds */
    public function allLevelsExist(array $levelIds): bool
    {
        if (!$levelIds) {
            return false;
        }

        if (
            !$this->schema->tableExists('levels') ||
            !$this->schema->columnExists('levels', 'level_id')
        ) {
            return false;
        }

        $marks = [];
        $params = [];
        foreach (array_values($levelIds) as $i => $levelId) {
            $key = ':level_' . $i;
            $marks[] = $key;
            $params[$key] = (int)$levelId;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT level_id) FROM levels WHERE level_id IN (' . implode(',', $marks) . ')'
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }

        $stmt->execute();
        return (int)$stmt->fetchColumn() === count($levelIds);
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mucho_level_lists
             (account_id, list_name, list_desc, list_version, level_ids, difficulty,
              original_id, unlisted, downloads, likes, featured, stars, count_for_reward,
              created_at, updated_at)
             VALUES
             (:account_id, :list_name, :list_desc, :list_version, :level_ids, :difficulty,
              :original_id, :unlisted, 0, 0, 0, 0, 0, :created_at, :updated_at)'
        );
        $stmt->execute($data);
        return (int)$this->db->lastInsertId();
    }

    public function updateOwned(int $listId, int $accountId, array $data): bool
    {
        $data[':list_id'] = $listId;
        $data[':owner_id'] = $accountId;

        $stmt = $this->db->prepare(
            'UPDATE mucho_level_lists SET
                list_name = :list_name,
                list_desc = :list_desc,
                list_version = :list_version,
                level_ids = :level_ids,
                difficulty = :difficulty,
                original_id = :original_id,
                unlisted = :unlisted,
                updated_at = :updated_at
             WHERE list_id = :list_id AND account_id = :owner_id'
        );
        $stmt->execute($data);
        return $stmt->rowCount() > 0 || $this->ownedBy($listId, $accountId);
    }

    public function deleteOwned(int $listId, int $accountId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM mucho_level_lists WHERE list_id = :list_id AND account_id = :account_id'
        );
        $stmt->execute([':list_id' => $listId, ':account_id' => $accountId]);
        return $stmt->rowCount() > 0;
    }

    public function ownedBy(int $listId, int $accountId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM mucho_level_lists WHERE list_id = :list_id AND account_id = :account_id'
        );
        $stmt->execute([':list_id' => $listId, ':account_id' => $accountId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function incrementDownload(int $listId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mucho_level_lists SET downloads = downloads + 1 WHERE list_id = :id'
        );
        $stmt->execute([':id' => $listId]);
    }
}
