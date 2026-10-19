<?php

declare(strict_types=1);

/*
 * MuchoCore 1.0 score integrity for existing installations.
 *
 * Older deployments may already have these tables without the database-level
 * uniqueness guarantees used by the current score writers. Collapse duplicate
 * rows before adding the unique keys so a rerun cannot turn a pre-existing
 * data issue into a migration failure.
 */
return static function(PDO $db): void {
    $hasTable = static function (PDO $db, string $table): bool {
        $q = $db->prepare(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table"
        );
        $q->execute(['table' => $table]);
        return (int)$q->fetchColumn() > 0;
    };

    $hasUnique = static function (PDO $db, string $table, string $index): bool {
        $q = $db->prepare(
            "SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND index_name = :index
               AND non_unique = 0"
        );
        $q->execute([
            'table' => $table,
            'index' => $index,
        ]);
        return (int)$q->fetchColumn() > 0;
    };

    if ($hasTable($db, 'mucho_level_scores') &&
        !$hasUnique($db, 'mucho_level_scores', 'uq_mucho_level_score_account_level_daily')) {
        $db->exec(<<<'SQL'
DELETE old_score
FROM mucho_level_scores old_score
JOIN mucho_level_scores keep_score
  ON keep_score.account_id = old_score.account_id
 AND keep_score.level_id = old_score.level_id
 AND keep_score.is_daily = old_score.is_daily
 AND (
      keep_score.percent > old_score.percent
      OR (
          keep_score.percent = old_score.percent
          AND keep_score.updated_at > old_score.updated_at
      )
      OR (
          keep_score.percent = old_score.percent
          AND keep_score.updated_at = old_score.updated_at
          AND keep_score.score_id > old_score.score_id
      )
 )
SQL);

        $db->exec(
            'ALTER TABLE mucho_level_scores
             ADD UNIQUE KEY uq_mucho_level_score_account_level_daily
             (account_id, level_id, is_daily)'
        );
    }

    if ($hasTable($db, 'mucho_platformer_scores') &&
        !$hasUnique($db, 'mucho_platformer_scores', 'uq_mucho_platformer_score_account_level')) {
        $db->exec(<<<'SQL'
CREATE TEMPORARY TABLE mucho_platformer_score_keep AS
SELECT account_id,
       level_id,
       MIN(NULLIF(time_ms, 0)) AS best_time_ms,
       MAX(points) AS best_points,
       MIN(created_at) AS first_created_at,
       MAX(updated_at) AS last_updated_at
FROM mucho_platformer_scores
GROUP BY account_id, level_id
SQL);

        $db->exec(<<<'SQL'
DELETE FROM mucho_platformer_scores
WHERE (account_id, level_id) IN (
    SELECT account_id, level_id
    FROM mucho_platformer_score_keep
)
SQL);

        $db->exec(<<<'SQL'
INSERT INTO mucho_platformer_scores
    (account_id, level_id, time_ms, points, created_at, updated_at)
SELECT account_id,
       level_id,
       COALESCE(best_time_ms, 0),
       best_points,
       first_created_at,
       last_updated_at
FROM mucho_platformer_score_keep
SQL);

        $db->exec('DROP TEMPORARY TABLE mucho_platformer_score_keep');

        $db->exec(
            'ALTER TABLE mucho_platformer_scores
             ADD UNIQUE KEY uq_mucho_platformer_score_account_level
             (account_id, level_id)'
        );
    }
};
