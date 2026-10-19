<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use PDO;
use Throwable;

final readonly class LevelRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function search(
        int $type,
        string $search,
        int $gameVersion,
        int $offset,
        int $limit,
        int $demonFilter = 0,
        array $input = []
    ): array {
        $where = [
            "l.is_deleted = 0",
            "l.is_unlisted = 0",
        ];

        $params = [];
        $historyJoin = '';
        $fixedOrder = null;

        if ($demonFilter > 0) {
            $where[] = "l.demon = 1";

            switch ($demonFilter) {
                case 1:
                case 7:
                    $where[] = "l.demon_difficulty = 3";
                    break;
                case 2:
                case 8:
                    $where[] = "l.demon_difficulty = 4";
                    break;
                case 3:
                case 6:
                case 11:
                    $where[] = "COALESCE(l.demon_difficulty, 0) = 0";
                    break;
                case 4:
                case 9:
                    $where[] = "l.demon_difficulty = 5";
                    break;
                case 5:
                case 10:
                    $where[] = "l.demon_difficulty = 6";
                    break;
                case 12:
                    $where[] = "l.demon_difficulty = 8";
                    break;
                case 13:
                    $where[] = "l.demon_difficulty = 9";
                    break;
                case 14:
                    $where[] = "l.demon_difficulty = 10";
                    break;
                default:
                    $where[] = "1 = 0";
                    break;
            }
        }

        if ($gameVersion > 0) {
            $where[] = "l.game_version <= :game_version";
            $params['game_version'] = $gameVersion;
        }

        $diff = (string)($input['diff'] ?? '-');

        if ($diff === '-2' && $demonFilter === 0) {
            $where[] = 'l.demon = 1';

            $raw = $input['demonFilter'] ?? 0;
            $demonFilterValue = is_scalar($raw) && preg_match('/^-?\d+$/', (string)$raw) === 1
                ? (int)$raw
                : 0;

            if ($demonFilterValue > 0) {
                $this->applyDemonFilter(
                    $where,
                    $demonFilterValue
                );
            }
        } elseif ($diff === '-1') {
            // Legacy GD 1.9 "unrated" difficulty filter.
            $where[] = 'l.difficulty = 0';
            $where[] = 'l.demon = 0';
        } elseif ($diff === '-3') {
            // Legacy GD 1.9 "Auto" difficulty filter.
            $where[] = 'l.auto_level = 1';
        } elseif ($diff !== '-' && $diff !== '-2') {
            $difficultyIds = $this->idList($diff, 20);

            if ($difficultyIds) {
                $normalized = [];

                foreach ($difficultyIds as $value) {
                    if ($value >= 10 && $value % 10 === 0) {
                        $value = intdiv($value, 10);
                    }
                    $normalized[] = $value;
                }

                $marks = [];

                foreach (array_values(array_unique($normalized)) as $i => $value) {
                    $key = 'difficulty_' . $i;
                    $marks[] = ':' . $key;
                    $params[$key] = $value;
                }

                if ($marks) {
                    $where[] = 'l.difficulty IN (' . implode(',', $marks) . ')';
                }
            }
        }

        if ((string)($input['original'] ?? '0') === '1') {
            $where[] = 'l.original_level_id = 0';
        }

        if ((string)($input['coins'] ?? '0') === '1') {
            $where[] = 'l.coins_verified = 1 AND l.coins <> 0';
        }

        if ((string)($input['twoPlayer'] ?? '0') === '1') {
            $where[] = 'l.two_player = 1';
        }

        if ((string)($input['star'] ?? '0') === '1') {
            $where[] = 'l.stars > 0';
        }

        if ((string)($input['noStar'] ?? '0') === '1') {
            $where[] = 'l.stars = 0';
        }

        if ((string)($input['featured'] ?? '0') === '1') {
            $where[] = 'l.featured > 0';
        }

        if ((string)($input['epic'] ?? '0') === '1') {
            $where[] = 'l.epic = 1';
        }

        if ((string)($input['mythic'] ?? '0') === '1') {
            $where[] = 'l.epic = 2';
        }

        if ((string)($input['legendary'] ?? '0') === '1') {
            $where[] = 'l.epic = 3';
        }

        if (isset($input['len']) && (string)$input['len'] !== '-') {
            $lengths = $this->idList((string)$input['len'], 10);
            if ($lengths) {
                $marks = [];

                foreach ($lengths as $i => $value) {
                    $key = 'length_' . $i;
                    $marks[] = ':' . $key;
                    $params[$key] = $value;
                }

                $where[] = 'l.length IN (' . implode(',', $marks) . ')';
            }
        }

        $order = 'l.created_at DESC, l.level_id DESC';

        switch ($type) {
            case 0:
            case 15:
                $order = 'l.likes DESC, l.level_id DESC';

                if ($search !== '') {
                    if (ctype_digit($search)) {
                        $where[] = 'l.level_id = :level_id';
                        $params['level_id'] = (int)$search;
                    } else {
                        $where[] = 'l.name LIKE :search';
                        $params['search'] = '%' . $search . '%';
                    }
                }
                break;

            case 1:
                $order = 'l.downloads DESC, l.level_id DESC';
                break;

            case 2:
                $order = 'l.likes DESC, l.level_id DESC';
                break;

            case 3:
                $where[] = 'l.created_at >= (CURRENT_TIMESTAMP - INTERVAL 7 DAY)';
                // Prevent trending bans from promoting affected creators.
                if (\MuchoCore\Moderation\Gd2209Restrictions::schemaAvailable($this->pdo)) {
                    $where[] = 'NOT EXISTS (
                        SELECT 1 FROM mucho_gd2209_restrictions ban
                        WHERE ban.account_id=l.account_id AND ban.kind="trending"
                          AND (ban.expires_at IS NULL OR ban.expires_at>UTC_TIMESTAMP())
                    )';
                }
                $order = 'l.likes DESC, l.level_id DESC';
                break;

            case 5:
                if (ctype_digit($search)) {
                    $where[] = '(p.user_id = :uid OR l.account_id = :aid)';
                    $params['uid'] = (int)$search;
                    $params['aid'] = (int)$search;
                }
                break;

            case 6:
            case 17:
                /* GD 2.1 only exposes featured levels; Epic is a later protocol surface. */
                $where[] = $gameVersion > 21
                    ? '(l.featured > 0 OR l.epic > 0)'
                    : 'l.featured > 0';
                $order = 'l.updated_at DESC, l.level_id DESC';
                break;

            case 7:
                $where[] = 'l.object_count > 9999';
                $order = 'l.downloads DESC, l.level_id DESC';
                break;

            case 10:
            case 19:
                $ids = $this->idList($search, 100);

                if (!$ids) {
                    return ['levels' => [], 'total' => 0];
                }

                $marks = [];

                foreach ($ids as $i => $id) {
                    $key = 'pack_' . $i;
                    $marks[] = ':' . $key;
                    $params[$key] = $id;
                }

                $where[] = 'l.level_id IN (' . implode(',', $marks) . ')';
                $fixedOrder = $ids;
                break;

            case 11:
                $where[] = 'l.stars > 0';
                $order = 'l.updated_at DESC, l.level_id DESC';
                break;

            case 12:
                $ids = $this->idList(
                    (string)($input['followed'] ?? ''),
                    500
                );

                if (!$ids) {
                    return ['levels' => [], 'total' => 0];
                }

                $marks = [];

                foreach ($ids as $i => $id) {
                    $key = 'followed_' . $i;
                    $marks[] = ':' . $key;
                    $params[$key] = $id;
                }

                $where[] = 'l.account_id IN (' . implode(',', $marks) . ')';
                $order = 'l.created_at DESC, l.level_id DESC';
                break;

            case 13:
                $friendIds = $this->friendAccountIds(
                    ctype_digit((string)($input['accountID'] ?? ''))
                        ? (int)$input['accountID']
                        : 0
                );

                if (!$friendIds) {
                    return ['levels' => [], 'total' => 0];
                }

                $marks = [];

                foreach ($friendIds as $i => $id) {
                    $key = 'friend_' . $i;
                    $marks[] = ':' . $key;
                    $params[$key] = $id;
                }

                $where[] = 'l.account_id IN (' . implode(',', $marks) . ')';
                $order = 'l.created_at DESC, l.level_id DESC';
                break;

            case 16:
                $where[] = 'l.epic > 0';
                $order = 'l.updated_at DESC, l.level_id DESC';
                break;

            case 21:
                $historyJoin = "
                    INNER JOIN mucho_daily_rotation mdr
                      ON mdr.level_id = l.level_id
                     AND mdr.kind = 'daily'
                     AND mdr.enabled = 1
                ";
                $order = 'mdr.id DESC';
                break;

            case 22:
                $historyJoin = "
                    INNER JOIN mucho_daily_rotation mdr
                      ON mdr.level_id = l.level_id
                     AND mdr.kind = 'weekly'
                     AND mdr.enabled = 1
                ";
                $order = 'mdr.id DESC';
                break;

            case 23:
                $historyJoin = "
                    INNER JOIN mucho_daily_rotation mdr
                      ON mdr.level_id = l.level_id
                     AND mdr.kind = 'event'
                     AND mdr.enabled = 1
                ";
                $order = 'mdr.id DESC';
                break;

            case 25:
            case 26:
                $ids = $this->resolveListLevelIds($search);

                if (!$ids) {
                    return ['levels' => [], 'total' => 0];
                }

                $marks = [];

                foreach ($ids as $i => $id) {
                    $key = 'list_' . $i;
                    $marks[] = ':' . $key;
                    $params[$key] = $id;
                }

                $where[] = 'l.level_id IN (' . implode(',', $marks) . ')';
                $fixedOrder = $ids;

                if ($type === 26) {
                    $where[1] = '1 = 1';
                }
                break;

            case 8:
                // Queue: unrated levels with real moderator submissions.
                $where[] = 'l.stars = 0';
                $where[] = 'EXISTS (
                    SELECT 1 FROM moderation_suggestions ms
                    WHERE ms.level_id = l.level_id
                )';
                $order = '(SELECT MAX(ms_order.id)
                    FROM moderation_suggestions ms_order
                    WHERE ms_order.level_id = l.level_id) DESC, l.level_id DESC';
                if ($search !== '') {
                    if (ctype_digit($search)) {
                        $where[] = 'l.level_id = :queue_level_id';
                        $params['queue_level_id'] = (int)$search;
                    } else {
                        $where[] = 'l.name LIKE :queue_name';
                        $params['queue_name'] = '%' . $search . '%';
                    }
                }
                break;

            case 27:
                // Sent: only submissions by the authenticated advisor.
                $senderId = (int)($input['accountID'] ?? 0);
                if ($senderId <= 0) {
                    return ['levels' => [], 'total' => 0];
                }
                $where[] = 'EXISTS (
                    SELECT 1 FROM moderation_suggestions ms
                    WHERE ms.level_id = l.level_id
                      AND ms.account_id = :sender_account_id
                )';
                $params['sender_account_id'] = $senderId;
                // This ORDER BY subquery does not participate in the COUNT
                // query, so do not add an ORDER-only bound param to $params.
                $order = '(SELECT MAX(ms_order.id)
                    FROM moderation_suggestions ms_order
                    WHERE ms_order.level_id = l.level_id
                      AND ms_order.account_id = ' . (int)$senderId .
                ') DESC, l.level_id DESC';
                if ($search !== '') {
                    if (ctype_digit($search)) {
                        $where[] = 'l.level_id = :sent_level_id';
                        $params['sent_level_id'] = (int)$search;
                    } else {
                        $where[] = 'l.name LIKE :sent_name';
                        $params['sent_name'] = '%' . $search . '%';
                    }
                }
                break;

            default:
                break;
        }

        if ((string)($input['uncompleted'] ?? '0') === '1' || (string)($input['onlyCompleted'] ?? '0') === '1') {
            $completed = $this->idList(
                (string)($input['completedLevels'] ?? ''),
                1000
            );

            if ($completed) {
                $marks = [];

                foreach ($completed as $i => $id) {
                    $key = 'completed_' . $i;
                    $marks[] = ':' . $key;
                    $params[$key] = $id;
                }

                if ((string)($input['uncompleted'] ?? '0') === '1') {
                    $where[] = 'l.level_id NOT IN (' . implode(',', $marks) . ')';
                } else {
                    $where[] = 'l.level_id IN (' . implode(',', $marks) . ')';
                }
            }
        }

        if (
            isset($input['song']) &&
            (string)$input['song'] !== '' &&
            (string)$input['song'] !== '0'
        ) {
            $song = max(0, (int)$input['song']);

            if ((string)($input['customSong'] ?? '0') === '1') {
                $where[] = 'l.song_id = :song_id';
                $params['song_id'] = $song;
            } else {
                $where[] = 'l.audio_track = :audio_track';
                $params['audio_track'] = max(0, $song - 1);
                $where[] = 'l.song_id = 0';
            }
        }

        if ((string)($input['gauntlet'] ?? '') !== '') {
            try {
                $gauntletId = (int)$input['gauntlet'];
                $q = $this->pdo->prepare(
                    'SELECT level1,level2,level3,level4,level5
                     FROM mucho_gauntlets
                     WHERE id=:id AND enabled=1
                     LIMIT 1'
                );
                $q->execute(['id'=>$gauntletId]);
                $g = $q->fetch(PDO::FETCH_ASSOC);

                if ($g) {
                    $ids = array_map(
                        'intval',
                        [
                            $g['level1'],
                            $g['level2'],
                            $g['level3'],
                            $g['level4'],
                            $g['level5'],
                        ]
                    );
                    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
                    $marks = [];

                    foreach ($ids as $i => $id) {
                        $key = 'gauntlet_' . $i;
                        $marks[] = ':' . $key;
                        $params[$key] = $id;
                    }

                    if ($marks) {
                        $where[] = 'l.level_id IN (' . implode(',', $marks) . ')';
                        $fixedOrder = $ids;
                    }
                }
            } catch (Throwable) {
                $where[] = '1 = 0';
            }
        }

        $whereSql = implode(' AND ', $where);

        $from = "
            FROM levels l
            {$historyJoin}
            LEFT JOIN accounts a ON a.account_id = l.account_id
            LEFT JOIN profiles p ON p.account_id = l.account_id
            LEFT JOIN songs s
              ON s.id = l.song_id
             AND s.is_verified = 1
        ";

        // COUNT(*) should not pay for username and song metadata joins.
        $countFrom = "FROM levels l {$historyJoin}";
        if ($type === 5 && ctype_digit($search)) {
            $countFrom .= " LEFT JOIN profiles p ON p.account_id=l.account_id";
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) ' . $countFrom . ' WHERE ' . $whereSql
        );
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $orderSql = $order;

        if ($fixedOrder) {
            $escaped = array_map(
                static fn(int $id): string => (string)$id,
                $fixedOrder
            );
            $orderSql = 'FIELD(l.level_id,' . implode(',', $escaped) . ')';
        }

        // Type 27 is the requesting advisor's history, not the global last
        // suggestion. The sender ID is an integer from the authenticated
        // request, not an interpolated untrusted string.
        $senderFilterSql = $type === 27
            ? ' AND ms_stars.account_id = ' . (int)$senderId
            : '';
        $requestedStarsExpr = in_array($type, [8, 27], true)
            ? 'COALESCE((SELECT ms_stars.stars
                  FROM moderation_suggestions ms_stars
                  WHERE ms_stars.level_id = l.level_id'
                    . $senderFilterSql .
                  ' ORDER BY ms_stars.id DESC LIMIT 1), l.requested_stars)'
            : 'l.requested_stars';

        $sql = '
            SELECT
                -- No LONGTEXT level_data or revision fields in level lists.
                l.level_id, l.account_id, l.name, l.description,
                l.level_version, l.game_version, l.length,
                l.difficulty, l.downloads, l.likes, l.audio_track,
                l.demon, l.demon_difficulty, l.auto_level,
                l.stars, l.featured, l.epic, l.object_count,
                l.original_level_id, l.two_player, l.coins,
                l.coins_verified, l.ldm, l.song_id,
                l.created_at, l.updated_at,
                ' . $requestedStarsExpr . ' AS requested_stars,
                COALESCE(a.username, "Player") AS username,
                COALESCE(p.user_id, l.account_id) AS user_id,
                s.id AS song_protocol_id,
                s.name AS song_name,
                s.author_id AS song_author_id,
                s.author_name AS song_author_name,
                s.size AS song_size,
                s.download_url AS song_download_url,
                s.youtube_video_id AS song_youtube_video_id,
                s.youtube_channel_id AS song_youtube_channel_id
            ' . $from . '
            WHERE ' . $whereSql . '
            ORDER BY ' . $orderSql . '
            LIMIT ' . (int)$limit . '
            OFFSET ' . (int)$offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'levels' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    private function searchIndexAvailable(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'mucho_level_search_index'"
            );
            $available = (int)$stmt->fetchColumn() === 1;
        } catch (Throwable) {
            $available = false;
        }

        return $available;
    }

    private function applyDemonFilter(
        array &$where,
        int $demonFilter
    ): void {
        switch ($demonFilter) {
            case 1:
            case 7:
                $where[] = 'l.demon_difficulty = 3';
                break;
            case 2:
            case 8:
                $where[] = 'l.demon_difficulty = 4';
                break;
            case 3:
            case 6:
            case 11:
                $where[] = 'COALESCE(l.demon_difficulty, 0) = 0';
                break;
            case 4:
            case 9:
                $where[] = 'l.demon_difficulty = 5';
                break;
            case 5:
            case 10:
                $where[] = 'l.demon_difficulty = 6';
                break;
            case 12:
                $where[] = 'l.demon_difficulty = 8';
                break;
            case 13:
                $where[] = 'l.demon_difficulty = 9';
                break;
            case 14:
                $where[] = 'l.demon_difficulty = 10';
                break;
            default:
                break;
        }
    }

    private function friendAccountIds(int $accountId): array
    {
        if ($accountId <= 0) {
            return [];
        }

        try {
            $q = $this->pdo->prepare(
                'SELECT friend_account_id
                 FROM friends
                 WHERE account_id=:id
                 UNION
                 SELECT account_id
                 FROM friends
                 WHERE friend_account_id=:id'
            );
            $q->execute(['id'=>$accountId]);
            $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

            return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveListLevelIds(string $search): array
    {
        $ids = $this->idList($search, 1000);

        if (count($ids) > 1) {
            return $ids;
        }

        if (!$ids) {
            return [];
        }

        try {
            $q = $this->pdo->prepare(
                'SELECT level_ids
                 FROM mucho_level_lists
                 WHERE list_id=:id
                 LIMIT 1'
            );
            $q->execute(['id'=>$ids[0]]);
            $value = $q->fetchColumn();

            if (is_string($value) && $value !== '') {
                return $this->idList($value, 1000);
            }
        } catch (Throwable) {
            // Older installations may not have level lists.
        }

        return $ids;
    }

    private function idList(string $value, int $max): array
    {
        $result = [];

        foreach (preg_split('/[,\s]+/', trim($value)) ?: [] as $part) {
            if (ctype_digit($part)) {
                $id = (int)$part;

                if ($id > 0 && !in_array($id, $result, true)) {
                    $result[] = $id;
                }
            }

            if (count($result) >= $max) {
                break;
            }
        }

        return $result;
    }
}
