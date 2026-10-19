<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Backed 2.209 account-abuse case operations.
 *
 * HTTP field names are derived from EXE format fragments, but most native
 * response parsers remain unverified. This is NOT certified wire parity.
 * Account IDs and role strings supplied by clients are never trusted.
 */
final readonly class Gd2209CaseService
{
    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
        private ModerationRepository $roles,
        private ReportQueueRepository $reports,
    ) {}

    public function reportLevel(
        int $actor, string $credential, int $type,
        int $targetId, int $special, string $reasons
    ): bool {
        $this->auth->authenticate($actor, $credential);
        // GD Docs 2.209: type 0=user; type 1=level; see
        // https://boomlings.dev/endpoints/misc/reportGJContent
        // Never interpret type 0 (user) as a level report.
        if ($type !== 1 || $special !== 0 || $targetId <= 0
            || strlen($reasons) > 40
            || !preg_match('/^[0-7](?:,[0-7]){0,7}$/D', $reasons)) {
            return false;
        }
        $values = array_map('intval', explode(',', $reasons));
        if (count(array_unique($values)) !== count($values)) {
            return false;
        }
        foreach ($values as $reason) {
            if (!in_array($reason, [0, 1, 2, 3, 6, 7], true)) {
                return false;
            }
        }
        // Store the primary reason and preserve the rest for auditing.
        return $this->reports->reportLevel(
            $actor, $targetId, $values[0], implode(',', $values)
        );
    }

    /** Only verified elder moderators and owners can access abuse cases. */
    private function assertModerator(int $actor, string $credential): string
    {
        $this->auth->authenticate($actor, $credential);
        $role = $this->roles->getAccountRole($actor);
        if (!in_array($role, ['elder_moderator', 'owner'], true)) {
            throw new RuntimeException('Insufficient moderation privileges');
        }
        return $role;
    }

    /** Target mutations must never affect an equal-or-higher role. */
    private function assertTarget(int $actor, string $role, int $target): void
    {
        if ($target <= 0 || $target === $actor) {
            throw new RuntimeException('Invalid moderation target');
        }
        $q = $this->db->prepare(
            'SELECT COALESCE(r.code, "user") FROM accounts a
             LEFT JOIN roles r ON r.id=a.role_id
             WHERE a.account_id=:id LIMIT 1'
        );
        $q->execute(['id' => $target]);
        $code = $q->fetchColumn();
        if ($code === false) {
            throw new RuntimeException('Target account not found');
        }
        $priority = ['user' => 0, 'moderator' => 50,
            'elder_moderator' => 75, 'owner' => 100];
        if (($priority[$role] ?? 0) <= ($priority[(string)$code] ?? 100)) {
            throw new RuntimeException('Target has equal or higher privileges');
        }
    }

    private static function checkedPage(int $page): int
    {
        if ($page < 0 || $page > 1000) {
            throw new RuntimeException('Invalid page');
        }
        return $page;
    }

    /** Returned framing is provisional pending native 2.209 captures. */
    public function reported(
        int $actor, string $credential, int $target, int $page
    ): string {
        $this->assertModerator($actor, $credential);
        $page = self::checkedPage($page);
        if ($target <= 0) {
            throw new RuntimeException('Invalid target');
        }
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM mucho_content_reports
             WHERE target_account_id=:id AND status="pending"'
        );
        $count->execute(['id' => $target]);
        $total = (int)$count->fetchColumn();
        $offset = $page * 20;
        $q = $this->db->prepare(
            'SELECT id, content_type, content_id, reason,
                    UNIX_TIMESTAMP(created_at) AS created_at_unix
             FROM mucho_content_reports
             WHERE target_account_id=:id AND status="pending"
             ORDER BY id DESC LIMIT 20 OFFSET ' . $offset
        );
        $q->execute(['id' => $target]);
        $records = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = implode(':', [
                (int)$row['id'], (int)$row['content_type'],
                (int)$row['content_id'], (int)$row['reason'],
                (int)$row['created_at_unix'],
            ]);
        }
        return $total . ':' . $offset . ':20#' . implode('|', $records);
    }

    /** Account-centric case events, only from actual persisted actions. */
    public function history(
        int $actor, string $credential, int $target, int $page
    ): string {
        $this->assertModerator($actor, $credential);
        $page = self::checkedPage($page);
        if ($target <= 0) {
            throw new RuntimeException('Invalid target');
        }
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM mucho_gd2209_case_actions
             WHERE target_account_id=:id'
        );
        $count->execute(['id' => $target]);
        $total = (int)$count->fetchColumn();
        $offset = $page * 20;
        $q = $this->db->prepare(
            'SELECT id, actor_account_id, action,
                    UNIX_TIMESTAMP(created_at) AS t
             FROM mucho_gd2209_case_actions
             WHERE target_account_id=:id
             ORDER BY id DESC LIMIT 20 OFFSET ' . $offset
        );
        $q->execute(['id' => $target]);
        $records = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $records[] = (int)$row['id'] . ':' . (int)$row['actor_account_id']
                . ':' . (string)$row['action'] . ':' . (int)$row['t'];
        }
        return $total . ':' . $offset . ':20#' . implode('|', $records);
    }

    public function abuseInfo(int $actor, string $credential, int $target): string
    {
        $this->assertModerator($actor, $credential);
        if ($target <= 0) {
            throw new RuntimeException('Invalid target');
        }
        $q = $this->db->prepare(
            'SELECT COUNT(*) AS reports,
                    COUNT(DISTINCT reporter_account_id) AS reporters,
                    COUNT(DISTINCT content_type,content_id) AS items
             FROM mucho_content_reports
             WHERE target_account_id=:id AND status="pending"'
        );
        $q->execute(['id' => $target]);
        $data = $q->fetch(PDO::FETCH_ASSOC);
        // Real 2.209 assigns three points per player report. No fictitious
        // auto-filter hits are produced.
        return '1:' . $target . ':2:' . (int)$data['reports']
            . ':3:' . ((int)$data['reports'] * 3)
            . ':4:' . (int)$data['reporters']
            . ':5:' . (int)$data['items'];
    }

    public function accountReview(
        int $actor, string $credential, int $target
    ): string {
        $this->assertModerator($actor, $credential);
        if ($target <= 0) {
            throw new RuntimeException('Invalid target');
        }
        $q = $this->db->prepare(
            'SELECT a.account_id, a.username, a.is_active, a.is_banned
             FROM accounts a WHERE a.account_id=:id LIMIT 1'
        );
        $q->execute(['id' => $target]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Account not found');
        }
        // Only safe public account properties; never expose login data.
        $username = \MuchoCore\Protocol\ProtocolText::username((string)$row['username']);
        return '1:' . (int)$row['account_id'] . ':2:' . $username
            . ':3:' . (int)$row['is_active'] . ':4:' . (int)$row['is_banned'];
    }

    /** Close selected report IDs for one account, never arbitrary reports. */
    public function clearReports(
        int $actor, string $credential, int $target, string $items
    ): bool {
        $role = $this->assertModerator($actor, $credential);
        $this->assertTarget($actor, $role, $target);
        if (!preg_match('/^[0-9]+(?:,[0-9]+){0,49}$/D', $items)) {
            return false;
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $items))));
        if (in_array(0, $ids, true)) {
            return false;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare(
                'UPDATE mucho_content_reports SET status="closed"
                 WHERE target_account_id=? AND status="pending" AND id IN (' . $marks . ')'
            );
            $q->execute(array_merge([$target], $ids));
            $changed = $q->rowCount();
            // All selected IDs must belong to this pending case. Reject
            // mixed-owner/missing input atomically instead of partial success.
            if ($changed !== count($ids)) {
                $this->db->rollBack();
                return false;
            }
            $this->audit($actor, $target, 'CLEAR_REPORTS', $changed);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Close all pending account reports; outcome is an audited enum. */
    public function closeCase(
        int $actor, string $credential, int $target, int $outcome
    ): bool {
        $role = $this->assertModerator($actor, $credential);
        $this->assertTarget($actor, $role, $target);
        if ($outcome < 0 || $outcome > 7) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare(
                'UPDATE mucho_content_reports SET status="closed"
                 WHERE target_account_id=:target AND status="pending"'
            );
            $q->execute(['target' => $target]);
            $count = $q->rowCount();
            if ($count < 1) {
                $this->db->rollBack();
                return false;
            }
            $this->audit($actor, $target, 'CLOSE_CASE', $outcome);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function audit(int $actor, int $target, string $action, int $detail): void
    {
        $q = $this->db->prepare(
            'INSERT INTO mucho_gd2209_case_actions
             (actor_account_id,target_account_id,action,detail)
             VALUES (:actor,:target,:action,:detail)'
        );
        $q->execute([
            'actor' => $actor, 'target' => $target,
            'action' => $action, 'detail' => $detail,
        ]);
    }
}
