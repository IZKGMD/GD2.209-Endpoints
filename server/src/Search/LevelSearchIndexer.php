<?php

declare(strict_types=1);

namespace MuchoCore\Search;

use PDO;
use Throwable;

final readonly class LevelSearchIndexer
{
    public function __construct(private PDO $pdo) {}

    public function upsert(int $levelId): bool
    {
        if ($levelId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT l.level_id,l.account_id,l.name,l.game_version,
                        l.is_deleted,l.is_unlisted,
                        COALESCE(a.username,'') AS username
                 FROM levels l
                 LEFT JOIN accounts a ON a.account_id=l.account_id
                 WHERE l.level_id=:level_id
                 LIMIT 1"
            );
            $stmt->execute(['level_id' => $levelId]);
            $level = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$level) {
                $this->delete($levelId);
                return false;
            }

            $name = self::normalize((string)$level['name']);
            $creator = self::normalize((string)$level['username']);

            $write = $this->pdo->prepare(
                'INSERT INTO mucho_level_search_index
                    (level_id,account_id,username,normalized_name,
                     normalized_creator,search_text,game_version,
                     is_deleted,is_unlisted)
                 VALUES
                    (:level_id,:account_id,:username,:name,:creator,
                     :search_text,:game_version,:is_deleted,:is_unlisted)
                 ON DUPLICATE KEY UPDATE
                    account_id=VALUES(account_id),
                    username=VALUES(username),
                    normalized_name=VALUES(normalized_name),
                    normalized_creator=VALUES(normalized_creator),
                    search_text=VALUES(search_text),
                    game_version=VALUES(game_version),
                    is_deleted=VALUES(is_deleted),
                    is_unlisted=VALUES(is_unlisted),
                    updated_at=CURRENT_TIMESTAMP'
            );

            $write->execute([
                'level_id' => (int)$level['level_id'],
                'account_id' => (int)$level['account_id'],
                'username' => substr((string)$level['username'], 0, 20),
                'name' => substr($name, 0, 128),
                'creator' => substr($creator, 0, 64),
                'search_text' => substr(trim($name . ' ' . $creator), 0, 255),
                'game_version' => (int)$level['game_version'],
                'is_deleted' => (int)$level['is_deleted'],
                'is_unlisted' => (int)$level['is_unlisted'],
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function rebuildAccount(int $accountId): int
    {
        if ($accountId <= 0) {
            return 0;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT level_id
                 FROM levels
                 WHERE account_id=:account_id
                 ORDER BY level_id ASC'
            );
            $stmt->execute(['account_id' => $accountId]);

            $count = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $levelId) {
                if ($this->upsert((int)$levelId)) {
                    $count++;
                }
            }

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    public function delete(int $levelId): void
    {
        if ($levelId <= 0) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM mucho_level_search_index WHERE level_id=:level_id'
            );
            $stmt->execute(['level_id' => $levelId]);
        } catch (Throwable) {
        }
    }

    public function rebuild(?int $limit = null): int
    {
        $sql = 'SELECT level_id FROM levels ORDER BY level_id ASC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, min(1000000, $limit));
        }

        $ids = $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;

        foreach ($ids as $id) {
            if ($this->upsert((int)$id)) {
                $count++;
            }
        }

        return $count;
    }

    public function searchIds(string $query, int $limit = 50): array
    {
        $query = self::normalize($query);

        if ($query === '') {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT level_id
                 FROM mucho_level_search_index
                 WHERE is_deleted=0
                   AND is_unlisted=0
                   AND (
                       normalized_name LIKE :prefix
                       OR normalized_creator LIKE :prefix2
                       OR search_text LIKE :contains
                   )
                 ORDER BY
                   CASE
                       WHEN normalized_name=:exact THEN 0
                       WHEN normalized_name LIKE :prefix3 THEN 1
                       WHEN normalized_creator LIKE :prefix4 THEN 2
                       ELSE 3
                   END,
                   updated_at DESC,
                   level_id DESC
                 LIMIT ' . max(1, min(500, $limit))
            );

            $stmt->execute([
                'prefix' => $query . '%',
                'prefix2' => $query . '%',
                'contains' => '%' . $query . '%',
                'exact' => $query,
                'prefix3' => $query . '%',
                'prefix4' => $query . '%',
            ]);

            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    public static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value))
            ?? trim($value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
