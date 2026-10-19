<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use MuchoCore\Account\AccountAuthenticator;
use RuntimeException;

/**
 * Account abuse queue for 2.209. Wire framing and record positions are
 * recovered from the original EXE; unknown event sources remain absent.
 */
final readonly class ReportQueueService
{
    public function __construct(
        private AccountAuthenticator $auth,
        private ModerationRepository $roles,
        private ReportQueueRepository $reports,
    ) {}

    private function authorize(int $accountId, string $credential): void
    {
        $this->auth->authenticate($accountId, $credential);
    }

    public function getQueue(
        int $accountId,
        string $credential,
        int $page,
        int $sort,
        int $mode,
        int $flags
    ): string {
        $this->authorize($accountId, $credential);

        // 2.209 renamed elder moderators to Moderators and legacy moderators
        // to Rating Advisors. Only Moderators and owners can view abuse reports.
        if (!in_array($this->roles->getAccountRole($accountId),
            ['elder_moderator','owner'], true)) {
            throw new RuntimeException('Moderator privilege required.');
        }

        if ($page < 0 || $page > 1000
            || $sort < 0 || $sort > 100
            || $mode < 0 || $mode > 100
            || $flags < 0 || $flags > 65535) {
            throw new RuntimeException('Invalid queue filters.');
        }

        $result = $this->reports->pending($page, $sort);
        // Disassembled native GD 2.209 parser at 0x140242a0c:
        // response MUST have "<total>:<offset>:<pageSize>#<rows>".
        // A '-2' body or a reversed envelope calls the client's failure
        // delegate and displays "Failed to load the queue".
        // Entry records are pipe-separated, colon-positional and need >=9
        // fields. Record semantics beyond account ID/name are provisional.
        $header = $result['total'] . ':' . ($page * 20) . ':20';
        if ($result['rows'] === []) {
            return $header . '#';
        }

        // Verified through AdminReportQueueEntry positional parser
        // (image VA 0x140242c47) and ReportQueuePopup field consumers:
        //   slot 0 account ID; 1 username; 2 item count; 4 anonymous count;
        //   slot 5 most reported reason (-1 = none);
        //   slot 8 direct-reported count; slot 12 abuse score;
        //   slot 13 flagged reason code (0 = unflagged);
        //   slot 16 flagged occurrences; slot 19 reporter count.
        // All other slots remain placeholders, not fabricated filter events.
        // Supplying twenty slots enables the client's optional score/summary
        // UI (>=18/20 slot branches); unknown values are zero/empty.
        $items = [];
        foreach ($result['rows'] as $row) {
            $reports = (int)$row['report_count'];
            $reportedItems = (int)$row['reported_items'];
            $reporters = (int)$row['reporter_count'];
            $items[] = implode(':', [
                (int)$row['target_account_id'],                              // 0 account
                \MuchoCore\Protocol\ProtocolText::username((string)$row['username']), // 1 name
                $reportedItems,                                               // 2 items
                0,                                                            // 3 reserved
                0,                                                            // 4 anonymous
                -1,                                                           // 5 no validated leading reason
                0,                                                            // 6 reserved
                '',                                                           // 7 reporter-related string
                0,                                                            // 8 direct profile reports unknown
                0,                                                            // 9
                '',                                                           // 10
                '',                                                           // 11
                (int)$row['report_points'],                                   // 12 abuse score
                0,                                                            // 13 no text-filter flag
                0,                                                            // 14
                0,                                                            // 15
                0,                                                            // 16 filter hits
                0,                                                            // 17
                0,                                                            // 18
                $reporters,                                                   // 19 distinct reporters
            ]);
        }

        return $header . '#' . implode('|', $items);
    }

    public function reportLevel(
        int $accountId,
        string $credential,
        int $levelId,
        int $reason,
        string $details
    ): bool {
        $this->authorize($accountId, $credential);
        if ($reason < 0 || $reason > 255 || strlen($details) > 500) {
            return false;
        }
        return $this->reports->reportLevel(
            $accountId, $levelId, $reason, $details
        );
    }
}
