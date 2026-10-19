<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Account\AccountAuthenticator;
use PDO;

/**
 * GD 2.209 level-position aggregates and owner-controlled platformer settings.
 * Byte serialization requires official capture before marking protocol parity.
 */
final readonly class LevelTelemetryService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
    ) {}

    private function level(int $levelId): ?array
    {
        if ($levelId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT level_id,account_id,level_version,is_deleted,is_unlisted
             FROM levels WHERE level_id=:level LIMIT 1'
        );
        $stmt->execute(['level' => $levelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (int)$row['is_deleted'] === 0 ? $row : null;
    }

    private function owner(int $accountId, string $credential, int $levelId): bool
    {
        $this->auth->authenticate($accountId, $credential);
        $level = $this->level($levelId);
        return $level !== null && (int)$level['account_id'] === $accountId;
    }

    public static function parsePosition(string $raw): ?float
    {
        if (!preg_match('/^-?(?:\d{1,7})(?:\.\d{1,3})?$/D', $raw)) {
            return null;
        }
        $value = (float)$raw;
        return is_finite($value) && abs($value) <= 1000000 ? $value : null;
    }

    /**
     * The client-reported percent is an integer in 0..100. Each distinct
     * player/level/version/position is aggregated; per-player row/counter caps
     * prevent trivially unbounded telemetry writes from one account.
     */
    public function uploadDeath(
        int $accountId,
        string $credential,
        int $levelId,
        int $percent,
        string $rawX,
        string $rawY
    ): bool {
        $this->auth->authenticate($accountId, $credential);
        $level = $this->level($levelId);
        $x = self::parsePosition($rawX);
        $y = self::parsePosition($rawY);
        if ($level === null || $percent < 0 || $percent > 100 || $x === null || $y === null) {
            return false;
        }

        $xb = (int)round($x * 10);
        $yb = (int)round($y * 10);
        $version = max(0, (int)$level['level_version']);

        $lookup = $this->db->prepare(
            'SELECT 1 FROM mucho_level_deaths
             WHERE level_id=:level AND account_id=:account
               AND level_version=:version AND percent_basis_points=:percent
               AND x_bucket=:x AND y_bucket=:y'
        );
        $arguments = [
            'level'=>$levelId,'account'=>$accountId,'version'=>$version,
            'percent'=>$percent*100,'x'=>$xb,'y'=>$yb,
        ];
        $lookup->execute($arguments);
        if ($lookup->fetchColumn() === false) {
            $count = $this->db->prepare(
                'SELECT COUNT(*) FROM mucho_level_deaths
                 WHERE level_id=:level AND account_id=:account AND level_version=:version'
            );
            $count->execute(['level'=>$levelId,'account'=>$accountId,'version'=>$version]);
            if ((int)$count->fetchColumn() >= 500) {
                return false;
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO mucho_level_deaths
                (level_id,account_id,level_version,percent_basis_points,x_bucket,y_bucket,deaths)
             VALUES (:level,:account,:version,:percent,:x,:y,1)
             ON DUPLICATE KEY UPDATE deaths=LEAST(deaths+1,100)'
        );
        return $stmt->execute($arguments);
    }

    /**
     * @return array<int,array<string,int>>
     */
    public function deaths(int $levelId): array
    {
        $level = $this->level($levelId);
        if ($level === null || (int)$level['is_unlisted'] === 1) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT percent_basis_points,x_bucket,y_bucket,
                    SUM(deaths) AS total
             FROM mucho_level_deaths
             WHERE level_id=:level AND level_version=:version
             GROUP BY percent_basis_points,x_bucket,y_bucket
             ORDER BY total DESC,percent_basis_points DESC LIMIT 256'
        );
        $stmt->execute([
            'level'=>$levelId,
            'version'=>(int)$level['level_version'],
        ]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'percent' => (int)$r['percent_basis_points'],
                'x' => (int)$r['x_bucket'],
                'y' => (int)$r['y_bucket'],
                'count' => (int)$r['total'],
            ];
        }
        return $rows;
    }

    public function uploadControls(
        int $accountId,
        string $credential,
        int $levelId,
        string $contents
    ): bool {
        if (!$this->owner($accountId, $credential, $levelId)) {
            return false;
        }
        if ($contents === '' || strlen($contents) > 16384 ||
            str_contains($contents, "\0") || !mb_check_encoding($contents,'UTF-8')) {
            return false;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO mucho_level_controls(level_id,contents) VALUES (:level,:data)
             ON DUPLICATE KEY UPDATE contents=VALUES(contents)'
        );
        return $stmt->execute(['level'=>$levelId,'data'=>$contents]);
    }

    public function controls(int $levelId): ?string
    {
        if ($this->level($levelId) === null) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT contents FROM mucho_level_controls WHERE level_id=:level'
        );
        $stmt->execute(['level'=>$levelId]);
        $contents = $stmt->fetchColumn();
        return is_string($contents) ? $contents : null;
    }

    public function deleteControls(
        int $accountId,
        string $credential,
        int $levelId
    ): bool {
        if (!$this->owner($accountId, $credential, $levelId)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM mucho_level_controls WHERE level_id=:level'
        );
        $stmt->execute(['level'=>$levelId]);
        return $stmt->rowCount() === 1;
    }
}
