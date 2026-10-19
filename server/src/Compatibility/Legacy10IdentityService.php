<?php

declare(strict_types=1);

namespace MuchoCore\Compatibility;

use PDO;
use RuntimeException;

final readonly class Legacy10IdentityService
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function resolveAccount(
        string $udid,
        string $username = '',
        string $ip = ''
    ): int {
        $hash = $this->udidHash($udid);

        $lookup = $this->pdo->prepare(
            'SELECT account_id
             FROM legacy_device_identities
             WHERE udid_hash=:hash
             LIMIT 1'
        );
        $lookup->execute(['hash' => $hash]);

        $existing = $lookup->fetchColumn();
        if ($existing !== false) {
            $accountId = (int)$existing;
            $this->touch($hash, $ip);

            if ($username !== '') {
                $this->updateUsername($udid, $username);
            }

            return $accountId;
        }

        $displayName = $this->uniqueUsername(
            $this->normalizeUsername($username, $hash),
            null,
            $hash
        );
        $email = 'legacy-' . $hash . '@legacy.invalid';
        $passwordHash = password_hash(
            bin2hex(random_bytes(24)),
            PASSWORD_DEFAULT
        );

        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('Failed to initialize legacy identity.');
        }

        $this->pdo->beginTransaction();

        try {
            $account = $this->pdo->prepare(
                'INSERT INTO accounts
                    (username,email,password_hash,role_id,is_active,is_banned)
                 VALUES
                    (:username,:email,:password_hash,1,1,0)'
            );
            $account->execute([
                'username' => $displayName,
                'email' => $email,
                'password_hash' => $passwordHash,
            ]);

            $accountId = (int)$this->pdo->lastInsertId();

            $profile = $this->pdo->prepare(
                'INSERT INTO profiles (account_id)
                 VALUES (:account_id)'
            );
            $profile->execute(['account_id' => $accountId]);

            $identity = $this->pdo->prepare(
                'INSERT INTO legacy_device_identities
                    (udid_hash,account_id,last_ip)
                 VALUES
                    (:hash,:account_id,:last_ip)'
            );
            $identity->execute([
                'hash' => $hash,
                'account_id' => $accountId,
                'last_ip' => $this->packIp($ip),
            ]);

            $this->pdo->commit();

            return $accountId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $lookup->execute(['hash' => $hash]);
            $existing = $lookup->fetchColumn();

            if ($existing !== false) {
                return (int)$existing;
            }

            throw $e;
        }
    }

    public function updateUsername(
        string $udid,
        string $username
    ): bool {
        $hash = $this->udidHash($udid);
        $lookup = $this->pdo->prepare(
            'SELECT account_id
             FROM legacy_device_identities
             WHERE udid_hash=:hash
             LIMIT 1'
        );
        $lookup->execute(['hash' => $hash]);
        $accountId = $lookup->fetchColumn();

        if ($accountId === false) {
            $accountId = $this->resolveAccount($udid, $username);
            return $accountId > 0;
        }

        $accountIdInt = (int)$accountId;
        if (\MuchoCore\Moderation\Gd2209Restrictions::active(
            $this->pdo, $accountIdInt, 'name'
        )) {
            return false;
        }
        $name = $this->uniqueUsername(
            $this->normalizeUsername($username, $hash),
            $accountIdInt,
            $hash
        );

        $stmt = $this->pdo->prepare(
            'UPDATE accounts
             SET username=:username
             WHERE account_id=:account_id
             LIMIT 1'
        );
        $stmt->execute([
            'username' => $name,
            'account_id' => (int)$accountId,
        ]);

        return $stmt->rowCount() >= 0;
    }

    private function touch(
        string $hash,
        string $ip
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE legacy_device_identities
             SET last_ip=:ip,
                 updated_at=CURRENT_TIMESTAMP
             WHERE udid_hash=:hash'
        );
        $stmt->execute([
            'hash' => $hash,
            'ip' => $this->packIp($ip),
        ]);
    }

    private function udidHash(
        string $udid
    ): string {
        $udid = trim($udid);

        if (
            $udid === '' ||
            strlen($udid) > 256 ||
            preg_match('/[\x00-\x1F\x7F]/', $udid) === 1
        ) {
            throw new RuntimeException('Invalid legacy device identity.');
        }

        return hash(
            'sha256',
            'MUCHO_GD10_UDID|' . $udid
        );
    }

    private function normalizeUsername(
        string $username,
        string $hash
    ): string {
        $username = trim($username);
        $username = preg_replace(
            '/[^A-Za-z0-9 _.-]/',
            '',
            $username
        ) ?? '';

        if ($username === '') {
            $username = 'Legacy' . substr($hash, 0, 14);
        }

        return substr($username, 0, 20) ?: 'Legacy' . substr($hash, 0, 14);
    }

    private function uniqueUsername(
        string $candidate,
        ?int $currentAccountId,
        string $hash
    ): string {
        $candidate = substr($candidate, 0, 20);
        if ($candidate === '') {
            $candidate = 'Legacy' . substr($hash, 0, 14);
        }

        $stmt = $this->pdo->prepare(
            'SELECT account_id
             FROM accounts
             WHERE username=:username
             LIMIT 1'
        );
        $stmt->execute(['username' => $candidate]);
        $owner = $stmt->fetchColumn();

        if (
            $owner === false ||
            ($currentAccountId !== null && (int)$owner === $currentAccountId)
        ) {
            return $candidate;
        }

        $suffix = '~' . substr($hash, 0, 5);
        $prefix = substr($candidate, 0, max(1, 20 - strlen($suffix)));

        return $prefix . $suffix;
    }

    private function packIp(
        string $ip
    ): ?string {
        $ip = trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        return is_string($packed) ? $packed : null;
    }
}
