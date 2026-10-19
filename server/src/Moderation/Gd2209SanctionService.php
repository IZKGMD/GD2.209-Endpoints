<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;
use Throwable;

final readonly class Gd2209SanctionService
{
    private const KINDS = ['comment','upload','message','freeze','trending','name'];

    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
        private ModerationRepository $roles
    ) {}

    /**
     * Record or remove a real restriction. Expiry is enforced for non-freeze.
     * Exact 2.209 client request fields/reply values still require captures.
     */
    public function change(
        int $actor, string $credential, int $target,
        string $kind, bool $enabled, int $durationMinutes
    ): bool {
        $this->auth->authenticate($actor, $credential);
        $role = $this->roles->getAccountRole($actor);
        if (!in_array($role, ['elder_moderator', 'owner'], true)
            || $target <= 0 || $target === $actor
            || !in_array($kind, self::KINDS, true)
            || $durationMinutes < 0 || $durationMinutes > 525600
            || ($kind === 'freeze' && $durationMinutes !== 0)) {
            return false;
        }
        $rank = ['user'=>0, 'moderator'=>50, 'elder_moderator'=>75, 'owner'=>100];

        $this->db->beginTransaction();
        try {
            $lookup = $this->db->prepare(
                'SELECT a.is_active, COALESCE(r.code, "user") AS role
                 FROM accounts a LEFT JOIN roles r ON r.id=a.role_id
                 WHERE a.account_id=:target FOR UPDATE'
            );
            $lookup->execute(['target'=>$target]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($rank[$role] ?? 0) <= ($rank[(string)$row['role']] ?? 100)) {
                $this->db->rollBack();
                return false;
            }
            $state = $this->db->prepare(
                'SELECT prior_is_active FROM mucho_gd2209_restrictions
                 WHERE account_id=:target AND kind=:kind FOR UPDATE'
            );
            $state->execute(['target'=>$target,'kind'=>$kind]);
            $original = $state->fetchColumn();
            $exists = $original !== false;
            if ($enabled) {
                // A repeat freeze must not erase the target's pre-freeze state.
                $prior = $kind === 'freeze'
                    ? ($exists ? (int)$original : (int)$row['is_active'])
                    : null;
                $expiry = $durationMinutes === 0 ? null
                    : gmdate('Y-m-d H:i:s', time() + 60 * $durationMinutes);
                $q = $this->db->prepare(
                    'INSERT INTO mucho_gd2209_restrictions
                     (account_id,kind,expires_at,created_by,prior_is_active)
                     VALUES (:target,:kind,:expiry,:actor,:prior)
                     ON DUPLICATE KEY UPDATE
                        expires_at=VALUES(expires_at),
                        created_by=VALUES(created_by),
                        prior_is_active=VALUES(prior_is_active)'
                );
                $q->execute([
                    'target'=>$target,'kind'=>$kind,'expiry'=>$expiry,
                    'actor'=>$actor,'prior'=>$prior
                ]);
                if ($kind === 'freeze') {
                    $q = $this->db->prepare(
                        'UPDATE accounts SET is_active=0 WHERE account_id=:target'
                    );
                    $q->execute(['target'=>$target]);
                }
            } else {
                if (!$exists) {
                    $this->db->rollBack();
                    return false;
                }
                $q = $this->db->prepare(
                    'DELETE FROM mucho_gd2209_restrictions
                     WHERE account_id=:target AND kind=:kind'
                );
                $q->execute(['target'=>$target,'kind'=>$kind]);
                if ($kind === 'freeze') {
                    $restore = $this->db->prepare(
                        'UPDATE accounts SET is_active=:prior WHERE account_id=:target'
                    );
                    $restore->execute([
                        'prior'=>(int)$original,
                        'target'=>$target
                    ]);
                }
            }

            $q = $this->db->prepare(
                'INSERT INTO mucho_gd2209_case_actions
                 (actor_account_id,target_account_id,action,detail)
                 VALUES (:actor,:target,:action,:detail)'
            );
            $q->execute([
                'actor'=>$actor, 'target'=>$target,
                'action'=>strtoupper($kind) . ($enabled ? '_BAN' : '_UNBAN'),
                'detail'=>$durationMinutes,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
