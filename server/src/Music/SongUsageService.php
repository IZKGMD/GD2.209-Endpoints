<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use PDO;

/**
 * GD 2.209 getGJSongUsage response.
 *
 * Wire: allLevels,ratedLevels,downloadsOnRated#tier:difficulty:count|...
 * Tiers: 0=Rated 1=Featured 2=Epic 3=Legendary 4=Mythic.
 * Difficulty: 0=Auto 1=Easy 2=Normal 3=Hard 4=Harder 5=Insane 6=Demon.
 */
final readonly class SongUsageService
{
    public function __construct(private PDO $pdo) {}

    public function usage(int $songId): string
    {
        if ($songId <= 0) {
            return '-1';
        }

        $summary = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN stars > 0 THEN 1 ELSE 0 END), 0) AS rated,
                COALESCE(SUM(CASE WHEN stars > 0 THEN downloads ELSE 0 END), 0) AS rated_downloads
             FROM levels
             WHERE song_id = :song AND is_deleted = 0 AND is_unlisted = 0'
        );
        $summary->execute(['song' => $songId]);
        $stats = $summary->fetch(PDO::FETCH_ASSOC) ?: [];

        $grouped = $this->pdo->prepare(
            'SELECT
                CASE
                  WHEN epic = 3 THEN 4
                  WHEN epic = 2 THEN 3
                  WHEN epic = 1 THEN 2
                  WHEN featured > 0 THEN 1
                  ELSE 0
                END AS tier,
                CASE
                  WHEN auto_level > 0 THEN 0
                  WHEN demon > 0 THEN 6
                  WHEN difficulty >= 10 AND difficulty <= 60
                       AND MOD(difficulty, 10) = 0 THEN LEAST(5, difficulty DIV 10)
                  ELSE LEAST(5, GREATEST(0, difficulty))
                END AS difficulty_bucket,
                COUNT(*) AS amount
             FROM levels
             WHERE song_id = :song AND is_deleted = 0
               AND is_unlisted = 0 AND stars > 0
             GROUP BY tier, difficulty_bucket
             ORDER BY tier ASC, difficulty_bucket ASC'
        );
        $grouped->execute(['song' => $songId]);

        $groups = [];
        foreach ($grouped->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[] = (int)$row['tier']
                . ':' . (int)$row['difficulty_bucket']
                . ':' . (int)$row['amount'];
        }

        return (int)($stats['total'] ?? 0)
            . ',' . (int)($stats['rated'] ?? 0)
            . ',' . (int)($stats['rated_downloads'] ?? 0)
            . '#' . implode('|', $groups);
    }
}
