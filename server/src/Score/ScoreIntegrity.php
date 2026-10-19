<?php

declare(strict_types=1);

namespace MuchoCore\Score;

use PDO;
use Throwable;

final class ScoreIntegrity
{
    public const STATUS_TRUSTED = 'trusted';
    public const STATUS_SUSPICIOUS = 'suspicious';
    public const STATUS_QUARANTINED = 'quarantined';

    public static function evaluateRegular(
        int $percent,
        int $attempts,
        int $clicks,
        int $playTime,
        string $progresses
    ): array {
        $risk = 0;
        $reasons = [];

        if ($percent >= 90 && $playTime > 0 && $playTime < 2) {
            $risk += 80;
            $reasons[] = 'near_complete_in_under_2s';
        } elseif ($percent === 100 && $playTime > 0 && $playTime < 5) {
            $risk += 60;
            $reasons[] = 'complete_in_under_5s';
        }

        if ($percent >= 95 && $attempts <= 1) {
            $risk += 55;
            $reasons[] = 'near_complete_with_one_attempt';
        }

        if ($percent >= 90 && $clicks <= 3) {
            $risk += 40;
            $reasons[] = 'near_complete_with_three_or_fewer_clicks';
        }

        if ($percent > 0 && $attempts === 0) {
            $risk += 45;
            $reasons[] = 'progress_without_attempts';
        }

        if ($percent >= 90 && $progresses === '') {
            $risk += 15;
            $reasons[] = 'high_progress_without_progress_trace';
        }

        $risk = min(100, $risk);
        $status = $risk >= 70
            ? self::STATUS_SUSPICIOUS
            : self::STATUS_TRUSTED;

        return [
            'risk_score' => $risk,
            'status' => $status,
            'reasons' => $reasons,
            'metrics' => [
                'percent' => $percent,
                'attempts' => $attempts,
                'clicks' => $clicks,
                'play_time' => $playTime,
                'progress_bytes' => strlen($progresses),
            ],
        ];
    }

    public static function evaluatePlatformer(
        int $timeMs,
        int $points
    ): array {
        $risk = 0;
        $reasons = [];

        if ($timeMs <= 250 && $points > 0) {
            $risk += 80;
            $reasons[] = 'platformer_completion_under_250ms';
        } elseif ($timeMs <= 1000 && $points > 0) {
            $risk += 55;
            $reasons[] = 'platformer_completion_under_1s';
        }

        if ($points > 100000000) {
            $risk += 35;
            $reasons[] = 'abnormally_high_platformer_points';
        }

        $risk = min(100, $risk);
        $status = $risk >= 70
            ? self::STATUS_SUSPICIOUS
            : self::STATUS_TRUSTED;

        return [
            'risk_score' => $risk,
            'status' => $status,
            'reasons' => $reasons,
            'metrics' => [
                'time_ms' => $timeMs,
                'points' => $points,
            ],
        ];
    }

    public static function record(
        PDO $db,
        string $scoreType,
        int $scoreId,
        int $accountId,
        int $levelId,
        array $result
    ): void {
        if (
            $scoreType === '' ||
            $scoreId <= 0 ||
            $accountId <= 0 ||
            $levelId <= 0
        ) {
            return;
        }

        try {
            $q = $db->prepare(
                'INSERT INTO mucho_score_integrity_events
                 (score_type,score_id,account_id,level_id,risk_score,status,reasons,metrics)
                 VALUES
                 (:type,:score,:account,:level,:risk,:status,:reasons,:metrics)
                 ON DUPLICATE KEY UPDATE
                    account_id=VALUES(account_id),
                    level_id=VALUES(level_id),
                    risk_score=VALUES(risk_score),
                    status=VALUES(status),
                    reasons=VALUES(reasons),
                    metrics=VALUES(metrics),
                    updated_at=CURRENT_TIMESTAMP'
            );

            $q->execute([
                'type' => $scoreType,
                'score' => $scoreId,
                'account' => $accountId,
                'level' => $levelId,
                'risk' => (int)($result['risk_score'] ?? 0),
                'status' => self::effectiveStatus($result),
                'reasons' => json_encode(
                    $result['reasons'] ?? [],
                    JSON_UNESCAPED_SLASHES
                ),
                'metrics' => json_encode(
                    $result['metrics'] ?? [],
                    JSON_UNESCAPED_SLASHES
                ),
            ]);
        } catch (Throwable) {
            // Integrity telemetry must never break the GD score protocol.
        }
    }

    public static function effectiveStatus(array $result): string
    {
        $status = (string)($result['status'] ?? self::STATUS_TRUSTED);
        if ($status === self::STATUS_SUSPICIOUS && self::quarantineEnabled()) {
            return self::STATUS_QUARANTINED;
        }
        return in_array(
            $status,
            [self::STATUS_TRUSTED, self::STATUS_SUSPICIOUS, self::STATUS_QUARANTINED],
            true
        ) ? $status : self::STATUS_TRUSTED;
    }

    public static function quarantineEnabled(): bool
    {
        $raw = $_ENV['MUCHO_ANTICHEAT_QUARANTINE']
            ?? getenv('MUCHO_ANTICHEAT_QUARANTINE')
            ?? '0';

        return in_array(
            strtolower(trim((string)$raw)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }
}
