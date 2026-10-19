<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use PDO;

/**
 * Isolated abuse-report queue, not a list of level rating suggestions.
 * No raw IP, credential or account token is stored in report records.
 */
final readonly class ReportQueueRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * GD 2.209 queues *accounts* with pending abuse cases.
     * A native moderation report is worth 3 abuse points according to the
     * client help text. Filter hits (1 point each, last 7 days) are not
     * present in MuchoCore's persisted moderation data yet; do not invent.
     *
     * @return array{total:int,rows:array<int,array<string,mixed>>}
     */
    public function pending(int $page, int $sort): array
    {
        $page = min(1000, max(0, $page));
        $offset = $page * 20;

        // Do not classify the same user's different levels as separate cases.
        // Reports without a verified target account cannot join the queue.
        $count = $this->pdo->query(
            "SELECT COUNT(DISTINCT target_account_id)
             FROM mucho_content_reports
             WHERE status='pending' AND target_account_id IS NOT NULL"
        );
        $total = (int)$count->fetchColumn();

        $order = match ($sort) {
            1 => 'last_report_id ASC',
            2 => 'report_count DESC, last_report_id DESC',
            default => 'last_report_id DESC',
        };

        $sql = "SELECT
                  r.target_account_id,
                  a.username,
                  MIN(r.id) AS first_report_id,
                  MAX(r.id) AS last_report_id,
                  COUNT(*) AS report_count,
                  COUNT(DISTINCT r.content_type, r.content_id) AS reported_items,
                  COUNT(DISTINCT r.reporter_account_id) AS reporter_count,
                  COUNT(*) * 3 AS report_points,
                  MAX(r.created_at) AS last_report_at
                FROM mucho_content_reports r
                INNER JOIN accounts a ON a.account_id=r.target_account_id
                WHERE r.status='pending' AND r.target_account_id IS NOT NULL
                GROUP BY r.target_account_id, a.username
                ORDER BY {$order}
                LIMIT 20 OFFSET {$offset}";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return ['total' => $total, 'rows' => $rows];
    }

    public function reportLevel(int $reporter, int $levelId, int $reason, string $details): bool
    {
        if ($levelId <= 0) {
            return false;
        }

        $exist = $this->pdo->prepare(
            'SELECT account_id
             FROM levels WHERE level_id=:id AND is_deleted=0 LIMIT 1'
        );
        $exist->execute(['id' => $levelId]);
        $target = (int)($exist->fetchColumn() ?: 0);
        if ($target <= 0 || $target === $reporter) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO mucho_content_reports
             (reporter_account_id,content_type,content_id,target_account_id,reason,details)
             VALUES (:reporter,0,:level,:target,:reason,:details)
             ON DUPLICATE KEY UPDATE id=id"
        );
        $stmt->execute([
            'reporter' => $reporter,
            'level' => $levelId,
            'target' => $target,
            'reason' => $reason,
            'details' => $details,
        ]);
        return true;
    }
}
