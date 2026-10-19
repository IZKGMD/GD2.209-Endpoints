<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use PDO;

final readonly class AccountRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                a.account_id,
                a.username,
                a.email,
                a.password_hash,
                a.gjp2_hash,
                a.is_active,
                a.is_banned,
                p.user_id
             FROM accounts a
             LEFT JOIN profiles p
                ON p.account_id = a.account_id
             WHERE a.username = :username
             LIMIT 1'
        );

        $stmt->execute([
            'username' => $username,
        ]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function usernameExists(string $username): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM accounts
             WHERE username = :username
             LIMIT 1'
        );

        $stmt->execute([
            'username' => $username,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM accounts
             WHERE email = :email
             LIMIT 1'
        );

        $stmt->execute([
            'email' => $email,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function createAccount(
        string $username,
        string $email,
        string $passwordHash,
        string $gjp2Hash
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO accounts (
                username,
                email,
                password_hash,
                gjp2_hash,
                role_id
             )
             VALUES (
                :username,
                :email,
                :password_hash,
                :gjp2_hash,
                (
                    SELECT id
                    FROM roles
                    WHERE code = 'user'
                    LIMIT 1
                )
             )"
        );

        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'gjp2_hash' => $gjp2Hash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createProfile(int $accountId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles (account_id)
             VALUES (:account_id)'
        );

        $stmt->execute([
            'account_id' => $accountId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function ensureProfile(int $accountId): int
    {
        /*
         * Атомарно создаём профиль или получаем существующий.
         * UNIQUE(account_id) защищает от параллельной регистрации.
         * LAST_INSERT_ID(user_id) позволяет получить ID существующей строки.
         */
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles (account_id)
             VALUES (:account_id)
             ON DUPLICATE KEY UPDATE
                 user_id = LAST_INSERT_ID(user_id)'
        );

        $stmt->execute([
            'account_id' => $accountId,
        ]);

        $userId = (int)$this->pdo->lastInsertId();

        if ($userId > 0) {
            return $userId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT user_id
             FROM profiles
             WHERE account_id = :account_id
             LIMIT 1'
        );

        $stmt->execute([
            'account_id' => $accountId,
        ]);

        $userId = (int)$stmt->fetchColumn();

        if ($userId <= 0) {
            throw new \RuntimeException('Unable to ensure profile.');
        }

        return $userId;
    }

    public function markLogin(int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE accounts
             SET last_login_at = CURRENT_TIMESTAMP
             WHERE account_id = :account_id'
        );

        $stmt->execute([
            'account_id' => $accountId,
        ]);
    }

    public function audit(
        ?int $accountId,
        string $action,
        string $ip,
        array $metadata = []
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (
                account_id,
                action,
                ip_address,
                metadata
             )
             VALUES (
                :account_id,
                :action,
                :ip_address,
                :metadata
             )'
        );

        $stmt->execute([
            'account_id' => $accountId,
            'action' => $action,
            'ip_address' => $ip,
            'metadata' => json_encode(
                $metadata,
                JSON_THROW_ON_ERROR
            ),
        ]);
    }
}
