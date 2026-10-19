<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use PDO;

final readonly class RecoveryRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findAccountByIdentity(string $identity): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                account_id,
                username,
                email,
                is_active,
                is_banned
             FROM accounts
             WHERE username = :username_identity
                OR email = :email_identity
             LIMIT 1'
        );

        $stmt->execute([
            'username_identity' => $identity,
            'email_identity' => $identity,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function createToken(
        int $accountId,
        string $tokenHash,
        string $expiresAt,
        string $ipHash
    ): void {
        $cleanup = $this->pdo->prepare(
            'DELETE FROM account_recovery_tokens
             WHERE account_id = :account_id
                OR expires_at <= NOW()
                OR used_at IS NOT NULL'
        );

        $cleanup->execute([
            'account_id' => $accountId,
        ]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO account_recovery_tokens
                (account_id, token_hash, expires_at, created_ip_hash)
             VALUES
                (:account_id, :token_hash, :expires_at, :created_ip_hash)'
        );

        $stmt->execute([
            'account_id' => $accountId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_ip_hash' => $ipHash,
        ]);
    }

    public function tokenIsValid(string $tokenHash): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM account_recovery_tokens
             WHERE token_hash = :token_hash
               AND used_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );

        $stmt->execute([
            'token_hash' => $tokenHash,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function resetPassword(
        string $tokenHash,
        string $passwordHash,
        string $gjp2Hash
    ): ?int {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT
                    art.account_id
                 FROM account_recovery_tokens art
                 INNER JOIN accounts a
                    ON a.account_id = art.account_id
                 WHERE art.token_hash = :token_hash
                   AND art.used_at IS NULL
                   AND art.expires_at > NOW()
                   AND a.is_active = 1
                   AND a.is_banned = 0
                 LIMIT 1
                 FOR UPDATE'
            );

            $stmt->execute([
                'token_hash' => $tokenHash,
            ]);

            $accountId = $stmt->fetchColumn();

            if ($accountId === false) {
                $this->pdo->rollBack();
                return null;
            }

            $updateAccount = $this->pdo->prepare(
                'UPDATE accounts SET
                    password_hash = :password_hash,
                    gjp2_hash = :gjp2_hash
                 WHERE account_id = :account_id
                 LIMIT 1'
            );

            $updateAccount->execute([
                'account_id' => (int)$accountId,
                'password_hash' => $passwordHash,
                'gjp2_hash' => $gjp2Hash,
            ]);

            if ($updateAccount->rowCount() !== 1) {
                throw new \RuntimeException(
                    'Unable to update password.'
                );
            }

            // Password recovery revokes legacy 1.9 upload sessions so an
            // old authenticated device cannot keep using the previous session.
            $revokeSessions = $this->pdo->prepare(
                'DELETE FROM mucho_legacy_19_sessions
                 WHERE account_id = :account_id'
            );
            $revokeSessions->execute([
                'account_id' => (int)$accountId,
            ]);

            $markUsed = $this->pdo->prepare(
                'UPDATE account_recovery_tokens
                 SET used_at = NOW()
                 WHERE token_hash = :token_hash
                   AND used_at IS NULL
                   AND expires_at > NOW()'
            );

            $markUsed->execute([
                'token_hash' => $tokenHash,
            ]);

            if ($markUsed->rowCount() !== 1) {
                throw new \RuntimeException(
                    'Unable to consume recovery token.'
                );
            }

            $this->pdo->commit();

            return (int)$accountId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function audit(
        int $accountId,
        string $action,
        string $ip,
        array $metadata = []
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (account_id, action, ip_address, metadata)
             VALUES
                (:account_id, :action, :ip_address, :metadata)'
        );

        $stmt->execute([
            'account_id' => $accountId,
            'action' => $action,
            'ip_address' => $ip,
            'metadata' => json_encode(
                $metadata,
                JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ),
        ]);
    }
}
