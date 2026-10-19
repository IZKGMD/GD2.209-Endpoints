<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use PDO;

final readonly class RewardsRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function account(int $accountId): ?array
    {
        $q=$this->pdo->prepare(
            'SELECT
                a.account_id,
                p.user_id
             FROM accounts a
             INNER JOIN profiles p
               ON p.account_id=a.account_id
             WHERE a.account_id=:id
               AND a.is_active=1
               AND a.is_banned=0
             LIMIT 1'
        );

        $q->execute(['id'=>$accountId]);

        $r=$q->fetch(PDO::FETCH_ASSOC);

        return $r ?: null;
    }

    public function state(int $accountId): array
    {
        $q=$this->pdo->prepare(
            'INSERT IGNORE INTO mucho_reward_state
             (account_id)
             VALUES (:id)'
        );

        $q->execute(['id'=>$accountId]);

        $q=$this->pdo->prepare(
            'SELECT *
             FROM mucho_reward_state
             WHERE account_id=:id
             LIMIT 1'
        );

        $q->execute(['id'=>$accountId]);

        return $q->fetch(PDO::FETCH_ASSOC) ?: [
            'account_id'=>$accountId,
            'small_last_at'=>0,
            'small_count'=>0,
            'big_last_at'=>0,
            'big_count'=>0,
        ];
    }

    public function claim(
        int $accountId,
        int $type,
        int $now,
        int $wait
    ): ?array {
        $this->pdo->beginTransaction();

        try {
            $q=$this->pdo->prepare(
                'INSERT IGNORE INTO mucho_reward_state
                 (account_id)
                 VALUES (:id)'
            );

            $q->execute(['id'=>$accountId]);

            $q=$this->pdo->prepare(
                'SELECT *
                 FROM mucho_reward_state
                 WHERE account_id=:id
                 FOR UPDATE'
            );

            $q->execute(['id'=>$accountId]);

            $state=$q->fetch(PDO::FETCH_ASSOC);

            if (!$state) {
                $this->pdo->rollBack();
                return null;
            }

            $lastField=$type === 1
                ? 'small_last_at'
                : 'big_last_at';

            $countField=$type === 1
                ? 'small_count'
                : 'big_count';

            $last=(int)$state[$lastField];

            $left=$last > 0
                ? max(0,$wait-($now-$last))
                : 0;

            if ($left > 0) {
                $this->pdo->rollBack();
                return null;
            }

            $sql=$type === 1
                ? 'UPDATE mucho_reward_state
                   SET small_last_at=:now,
                       small_count=small_count+1
                   WHERE account_id=:id'
                : 'UPDATE mucho_reward_state
                   SET big_last_at=:now,
                       big_count=big_count+1
                   WHERE account_id=:id';

            $q=$this->pdo->prepare($sql);

            $q->execute([
                'now'=>$now,
                'id'=>$accountId
            ]);

            $this->pdo->commit();

            return $this->state($accountId);

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function challenges(): array
    {
        return $this->pdo->query(
            'SELECT
                challenge_id,
                type,
                amount,
                reward,
                name
             FROM mucho_challenge_pool
             WHERE active=1
             ORDER BY challenge_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function secretReward(string $rewardKey): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT
                reward_id,
                reward_key,
                chest_type,
                rewards,
                uses,
                expires_at
             FROM mucho_secret_rewards
             WHERE reward_key=:reward_key
               AND active=1
             LIMIT 1'
        );

        $q->execute(['reward_key' => $rewardKey]);

        $row = $q->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $expiresAt = (int)$row['expires_at'];

        if ($expiresAt > 0 && $expiresAt <= time()) {
            return null;
        }

        if ((int)$row['uses'] <= 0) {
            return null;
        }

        return $row;
    }

    public function claimSecretReward(
        int $rewardId,
        string $claimKey
    ): ?array {
        $this->pdo->beginTransaction();

        try {
            $q = $this->pdo->prepare(
                'SELECT
                    reward_id,
                    reward_key,
                    chest_type,
                    rewards,
                    uses,
                    expires_at,
                    active
                 FROM mucho_secret_rewards
                 WHERE reward_id=:reward_id
                 FOR UPDATE'
            );
            $q->execute(['reward_id' => $rewardId]);

            $reward = $q->fetch(PDO::FETCH_ASSOC);

            if (
                !$reward ||
                (int)$reward['active'] !== 1 ||
                (int)$reward['uses'] <= 0 ||
                (
                    (int)$reward['expires_at'] > 0 &&
                    (int)$reward['expires_at'] <= time()
                )
            ) {
                $this->pdo->rollBack();
                return null;
            }

            $claim = $this->pdo->prepare(
                'INSERT IGNORE INTO mucho_secret_reward_claims
                    (reward_id, claim_key)
                 VALUES (:reward_id, :claim_key)'
            );
            $claim->execute([
                'reward_id' => $rewardId,
                'claim_key' => $claimKey,
            ]);

            if ($claim->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE mucho_secret_rewards
                 SET uses=uses-1
                 WHERE reward_id=:reward_id
                   AND uses>0'
            );
            $update->execute(['reward_id' => $rewardId]);

            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }

            $this->pdo->commit();

            return $reward;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}


