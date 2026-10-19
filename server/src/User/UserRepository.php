<?php

declare(strict_types=1);

namespace MuchoCore\User;

use PDO;

final readonly class UserRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function search(string $query, int $offset = 0, int $limit = 100): array
    {
        $sql = '
            SELECT
                COALESCE(p.stars, 0) AS stars,
                COALESCE(p.moons, 0) AS moons,
                COALESCE(p.demons, 0) AS demons,
                COALESCE(p.diamonds, 0) AS diamonds,
                COALESCE(p.secret_coins, 0) AS secret_coins,
                COALESCE(p.user_coins, 0) AS user_coins,
                COALESCE(p.creator_points, 0) AS creator_points,
                COALESCE(p.icon_id, 1) AS icon_id,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.glow, 0) AS glow,
                COALESCE(p.demon_info, "") AS demon_info,
                COALESCE(p.star_info, "") AS star_info,
                COALESCE(p.platformer_info, "") AS platformer_info,
                COALESCE(p.explosion, 1) AS explosion,
                COALESCE(p.cube, 1) AS cube,
                COALESCE(p.ship, 1) AS ship,
                COALESCE(p.ball, 1) AS ball,
                COALESCE(p.ufo, 1) AS ufo,
                COALESCE(p.wave, 1) AS wave,
                COALESCE(p.robot, 1) AS robot,
                COALESCE(p.spider, 1) AS spider,
                COALESCE(p.swing, 1) AS swing,
                COALESCE(p.jetpack, 1) AS jetpack,
                COALESCE(p.color1, 0) AS color1,
                COALESCE(p.color2, 3) AS color2,
                COALESCE(p.color3, 0) AS color3,
                COALESCE(p.special, 0) AS special,
                p.user_id,
                a.account_id,
                a.username,
                COALESCE(ar.code, \'user\') AS role_code,
                COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), \'\') AS clan_tag
            FROM accounts a
            LEFT JOIN profiles p
                ON p.account_id = a.account_id
            LEFT JOIN roles ar
                ON ar.id = a.role_id
            WHERE
                (a.username LIKE :query
                 OR (:numeric_query = 1 AND COALESCE(p.user_id, 0) = :user_id))
            ORDER BY COALESCE(p.stars, 0) DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        $stmt = $this->pdo->prepare($sql);
        $isNumeric = ctype_digit($query) && $query !== '';
        $stmt->execute([
            'query' => '%' . $query . '%',
            'numeric_query' => $isNumeric ? 1 : 0,
            'user_id' => $isNumeric ? (int)$query : 0,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchCount(string $query): int
    {
        $isNumeric = ctype_digit($query) && $query !== '';

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM accounts a
             LEFT JOIN profiles p
               ON p.account_id = a.account_id
             WHERE
                (a.username LIKE :query
                 OR (:numeric_query = 1 AND COALESCE(p.user_id, 0) = :user_id))'
        );

        $stmt->execute([
            'query' => '%' . $query . '%',
            'numeric_query' => $isNumeric ? 1 : 0,
            'user_id' => $isNumeric ? (int)$query : 0,
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function findAccountIdByUserId(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT account_id
             FROM profiles
             WHERE user_id = :user_id
             LIMIT 1'
        );

        $stmt->execute([
            'user_id' => $userId,
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    /** Accurate 2.209 profile counters using indexed social/notification tables. */
    public function getGd2209ProfileCounters(int $targetId, int $viewerId): array
    {
        $query = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM mucho_follows
                 WHERE followed_account_id=:followers_for) AS followers,
                (SELECT COUNT(*) FROM mucho_follows
                 WHERE follower_account_id=:following_for) AS following,
                (SELECT COUNT(*) FROM mucho_follows
                 WHERE follower_account_id=:viewer AND followed_account_id=:peer)
                    AS is_following,
                (SELECT COUNT(*) FROM mucho_notifications
                 WHERE account_id=:notifications_for AND is_read=0)
                    AS notification_count'
        );
        $query->execute([
            'followers_for' => $targetId,
            'following_for' => $targetId,
            'viewer' => max(0, $viewerId),
            'peer' => $targetId,
            'notifications_for' => $viewerId === $targetId ? $targetId : 0,
        ]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function ensureProfileForAccount(
        int $accountId
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM profiles
             WHERE account_id = :account_id
             LIMIT 1'
        );

        $stmt->execute([
            'account_id' => $accountId,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return;
        }

        /*
         * updateGJUserScore and getGJUserInfo20 may arrive concurrently.
         * Create the profile atomically so the first request does not fail.
         */
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO profiles (account_id)
             SELECT account_id
             FROM accounts
             WHERE account_id = :account_id'
        );

        $stmt->execute([
            'account_id' => $accountId,
        ]);
    }

    public function getProfileByTarget(
        int $targetAccountId,
        int $viewerAccountId = 0
    ): ?array
    {
        $this->ensureProfileForAccount($targetAccountId);

        $sql = '
            SELECT
                p.user_id,
                a.account_id,
                a.username,
                COALESCE(ar.code, \'user\') AS role_code,
                COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=a.account_id LIMIT 1), \'\') AS clan_tag,
                COALESCE(a.messages_state, 0) AS message_state,
                COALESCE(a.friend_requests_state, 0) AS friend_request_state,
                COALESCE(a.comments_state, 0) AS comment_history_state,
                COALESCE(a.youtube_url, "") AS youtube,
                COALESCE(a.twitter, "") AS twitter,
                COALESCE(a.twitch, "") AS twitch,
                COALESCE(a.instagram, "") AS instagram,
                COALESCE(a.discord, "") AS discord,
                COALESCE(a.tiktok, "") AS tiktok,
                COALESCE(a.custom_link, "") AS custom_link,
                a.created_at AS registered_at,
                COALESCE(p.stars, 0) AS stars,
                COALESCE(p.moons, 0) AS moons,
                COALESCE(p.demons, 0) AS demons,
                COALESCE(p.diamonds, 0) AS diamonds,
                COALESCE(p.secret_coins, 0) AS secret_coins,
                COALESCE(p.user_coins, 0) AS user_coins,
                COALESCE(p.creator_points, 0) AS creator_points,
                COALESCE(p.icon_id, 1) AS icon_id,
                COALESCE(p.icon_type, 0) AS icon_type,
                COALESCE(p.glow, 0) AS glow,
                COALESCE(p.demon_info, "") AS demon_info,
                COALESCE(p.star_info, "") AS star_info,
                COALESCE(p.platformer_info, "") AS platformer_info,
                COALESCE(p.explosion, 1) AS explosion,
                COALESCE(p.cube, 1) AS cube,
                COALESCE(p.ship, 1) AS ship,
                COALESCE(p.ball, 1) AS ball,
                COALESCE(p.ufo, 1) AS ufo,
                COALESCE(p.wave, 1) AS wave,
                COALESCE(p.robot, 1) AS robot,
                COALESCE(p.spider, 1) AS spider,
                COALESCE(p.swing, 1) AS swing,
                COALESCE(p.jetpack, 1) AS jetpack,
                COALESCE(p.color1, 0) AS color1,
                COALESCE(p.color2, 3) AS color2,
                COALESCE(p.color3, 0) AS color3,
                COALESCE(p.special, 0) AS special,
                COALESCE((SELECT COUNT(*) FROM levels WHERE account_id = a.account_id AND is_deleted = 0), 0) AS levels_count
            FROM accounts a
            LEFT JOIN profiles p
                ON p.account_id = a.account_id
            LEFT JOIN roles ar
                ON ar.id = a.role_id
            WHERE a.account_id = :account_id
            LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['account_id' => $targetAccountId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['friend_state'] = 0;
        $row['request_id'] = 0;
        $row['request_comment'] = '';
        $row['request_date'] = '';

        if ($viewerAccountId > 0) {
            try {
                if ($viewerAccountId === $targetAccountId) {
                    $row['messages_count'] = (int)$this->scalar(
                        'SELECT COUNT(*) FROM messages
                         WHERE to_account_id=:id
                           AND is_read=0
                           AND is_receiver_deleted=0',
                        ['id'=>$viewerAccountId]
                    );
                    $row['friend_requests_count'] = (int)$this->scalar(
                        'SELECT COUNT(*) FROM friend_requests
                         WHERE to_account_id=:id',
                        ['id'=>$viewerAccountId]
                    );
                    $row['friends_count'] = (int)$this->scalar(
                        'SELECT COUNT(*) FROM friends
                         WHERE account_id=:id
                           AND is_new=1',
                        ['id'=>$viewerAccountId]
                    );
                } else {
                    $blocked = (int)$this->scalar(
                        'SELECT COUNT(*) FROM blocks
                         WHERE (account_id=:viewer AND blocked_account_id=:target)
                            OR (account_id=:target AND blocked_account_id=:viewer)',
                        [
                            'viewer'=>$viewerAccountId,
                            'target'=>$targetAccountId
                        ]
                    );

                    if ($blocked > 0) {
                        return null;
                    }

                    $friend = (int)$this->scalar(
                        'SELECT COUNT(*) FROM friends
                         WHERE account_id=:viewer
                           AND friend_account_id=:target',
                        [
                            'viewer'=>$viewerAccountId,
                            'target'=>$targetAccountId
                        ]
                    );

                    if ($friend > 0) {
                        $row['friend_state'] = 1;
                    } else {
                        $incoming = $this->pdo->prepare(
                            'SELECT id,comment,created_at
                             FROM friend_requests
                             WHERE account_id=:target
                               AND to_account_id=:viewer
                             LIMIT 1'
                        );
                        $incoming->execute([
                            'target'=>$targetAccountId,
                            'viewer'=>$viewerAccountId
                        ]);
                        $request = $incoming->fetch(PDO::FETCH_ASSOC);

                        if ($request) {
                            $row['friend_state'] = 3;
                            $row['request_id'] = (int)$request['id'];
                            $row['request_comment'] = (string)$request['comment'];
                            $row['request_date'] = date(
                                'd/m/Y G.i',
                                strtotime((string)$request['created_at'])
                            );
                        } else {
                            $outgoing = (int)$this->scalar(
                                'SELECT COUNT(*) FROM friend_requests
                                 WHERE account_id=:viewer
                                   AND to_account_id=:target',
                                [
                                    'viewer'=>$viewerAccountId,
                                    'target'=>$targetAccountId
                                ]
                            );

                            if ($outgoing > 0) {
                                $row['friend_state'] = 4;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Optional social metadata must never break profile loading.
            }
        }

        return $row;
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function leaderboardTop(int $limit = 1000, int $gameVersion = 0): array
    {
        $sql = '
            SELECT
                p.*,
                a.username,
                COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=p.account_id LIMIT 1), \'\') AS clan_tag,
                COALESCE(ar.code, \'user\') AS role_code,
                ROW_NUMBER() OVER (ORDER BY p.stars DESC, p.account_id ASC) AS `rank`
            FROM profiles p
            INNER JOIN accounts a
                ON a.account_id = p.account_id
            LEFT JOIN roles ar
                ON ar.id = a.role_id
            WHERE p.stars > 0
              AND a.is_active=1
              AND a.is_banned=0
            ORDER BY `rank`
            LIMIT ' . (int)$limit;

        if ($gameVersion >= 20) {
            $sql = str_replace(
                'WHERE p.stars > 0',
                'WHERE p.stars > 0 AND COALESCE(p.game_version, 0) >= 20',
                $sql
            );
        } elseif ($gameVersion > 0) {
            $sql = str_replace(
                'WHERE p.stars > 0',
                'WHERE p.stars > 0 AND COALESCE(p.game_version, 0) > 0 AND COALESCE(p.game_version, 0) < 20',
                $sql
            );
        }

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function leaderboardCreators(int $limit = 1000, int $gameVersion = 0): array
    {
        $sql = '
            SELECT
                p.*,
                a.username,
                COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=p.account_id LIMIT 1), \'\') AS clan_tag,
                COALESCE(ar.code, \'user\') AS role_code,
                ROW_NUMBER() OVER (ORDER BY p.creator_points DESC, p.account_id ASC) AS `rank`
            FROM profiles p
            INNER JOIN accounts a
                ON a.account_id = p.account_id
            LEFT JOIN roles ar
                ON ar.id = a.role_id
            WHERE p.creator_points > 0
              AND a.is_active=1
              AND a.is_banned=0
            ORDER BY `rank`
            LIMIT ' . (int)$limit;

        if ($gameVersion >= 20) {
            $sql = str_replace(
                'WHERE p.creator_points > 0',
                'WHERE p.creator_points > 0 AND COALESCE(p.game_version, 0) >= 20',
                $sql
            );
        } elseif ($gameVersion > 0) {
            $sql = str_replace(
                'WHERE p.creator_points > 0',
                'WHERE p.creator_points > 0 AND COALESCE(p.game_version, 0) > 0 AND COALESCE(p.game_version, 0) < 20',
                $sql
            );
        }

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function leaderboardFriends(int $accountId, int $limit = 100, int $gameVersion = 0): array
    {
        if ($accountId <= 0) {
            return [];
        }

        $sql = '
            WITH FriendIds AS (
                SELECT friend_account_id AS account_id
                FROM friends
                WHERE account_id = :account_a
                UNION
                SELECT account_id
                FROM friends
                WHERE friend_account_id = :account_b
                UNION
                SELECT :self_id
            ),
            RankedFriends AS (
                SELECT
                    p.*,
                    a.username,
                    COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=p.account_id LIMIT 1), \'\') AS clan_tag,
                    COALESCE(ar.code, \'user\') AS role_code,
                    ROW_NUMBER() OVER (
                        ORDER BY p.stars DESC, p.account_id ASC
                    ) AS `rank`
                FROM FriendIds f
                INNER JOIN profiles p
                    ON p.account_id = f.account_id
                INNER JOIN accounts a
                    ON a.account_id = f.account_id
                LEFT JOIN roles ar
                    ON ar.id = a.role_id
                WHERE a.is_active=1
                  AND a.is_banned=0
                  AND (:game_version < 20 OR COALESCE(p.game_version, 0) >= 20)
            )
            SELECT *
            FROM RankedFriends
            ORDER BY `rank`
            LIMIT ' . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'account_a' => $accountId,
            'account_b' => $accountId,
            'self_id' => $accountId,
            'game_version' => $gameVersion,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function leaderboardRelative(int $accountId, int $limit = 50, int $gameVersion = 0): array
    {
        $versionFilter = $gameVersion >= 20
            ? 'WHERE COALESCE(p.game_version, 0) >= 20'
            : ($gameVersion > 0
                ? 'WHERE COALESCE(p.game_version, 0) > 0 AND COALESCE(p.game_version, 0) < 20'
                : '');

        $sql = "WITH RankedProfiles AS (
            SELECT p.*, a.username,
                   COALESCE((SELECT c.tag FROM mucho_clan_members cm INNER JOIN mucho_clans c ON c.clan_id=cm.clan_id WHERE cm.account_id=p.account_id LIMIT 1), \'\') AS clan_tag,
                   COALESCE(ar.code, \'user\') AS role_code,
                   ROW_NUMBER() OVER (ORDER BY p.stars DESC, p.account_id ASC) AS `rank`
            FROM profiles p
            INNER JOIN accounts a ON a.account_id = p.account_id
            LEFT JOIN roles ar ON ar.id = a.role_id
            {$versionFilter}
        ),
        TargetRank AS (
            SELECT `rank` FROM RankedProfiles WHERE account_id = :account_id
        )
        SELECT r.* FROM RankedProfiles r
        JOIN TargetRank t ON 1=1
        WHERE r.`rank` BETWEEN t.`rank` - 25 AND t.`rank` + 25
        LIMIT " . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['account_id' => $accountId]);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return empty($res)
            ? $this->leaderboardTop($limit, $gameVersion)
            : $res;
    }
}
