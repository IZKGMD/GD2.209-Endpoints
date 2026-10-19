<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use PDO;
use RuntimeException;
use Throwable;

final readonly class AccountAuthenticator
{
    private const XOR_KEY = '37526';
    private const DUMMY_PASSWORD_HASH = '$2y$12$1/MaE58zhdgQZQpBkgNBhuqdaaih/yUIuXVGBWgthDWALLCBtilDC';

    public function __construct(
        private PDO $pdo
    ) {}

    public function authenticateGjp2(
        int $accountId,
        string $gjp2
    ): ?array {
        try {
            return $this->authenticate($accountId, $gjp2);
        } catch (Throwable) {
            return null;
        }
    }

    public function authenticate(
        int $accountId,
        string $credential,
        string $ip = ''
    ): array {
        $credential = trim($credential);

        if (
            $accountId <= 0 ||
            $credential === '' ||
            strlen($credential) > 1024
        ) {
            throw new RuntimeException('Unauthorized.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                a.account_id,
                a.username,
                a.password_hash,
                a.gjp2_hash,
                a.is_active,
                a.is_banned,
                p.user_id
             FROM accounts a
             LEFT JOIN profiles p
               ON p.account_id = a.account_id
             WHERE a.account_id = :account_id
             LIMIT 1'
        );

        $stmt->execute([
            ':account_id' => $accountId
        ]);

        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            // Consume comparable password-hash work even when the account ID
            // does not exist, reducing the usefulness of timing probes.
            password_verify($credential, self::DUMMY_PASSWORD_HASH);
            throw new RuntimeException('Unauthorized.');
        }

        if (
            (int)$account['is_banned'] === 1 ||
            (int)$account['is_active'] !== 1
        ) {
            password_verify($credential, self::DUMMY_PASSWORD_HASH);
            throw new RuntimeException('Unauthorized.');
        }

        $storedPass = (string)($account['password_hash'] ?? '');
        $storedGjp2 = (string)($account['gjp2_hash'] ?? '');
        $valid = false;

        /*
         * Standard Geometry Dash endpoints must always prove possession of a
         * valid account credential. The historical account+IP grant is not a
         * safe authentication proof on shared/NAT networks because account IDs
         * are public and IP addresses are shared identifiers.
         *
         * Keep the $ip argument for call-site compatibility, but deliberately
         * do not use it to bypass credential verification. Protocol versions
         * that genuinely omit a credential must use a dedicated, stronger
         * compatibility path such as authenticateLegacy19Upload(), which is
         * additionally bound to the client's UDID.
         */

        // 1. Plain credential for current-core and older client compatibility.
        if ($storedPass !== '') {
            $valid = password_verify($credential, $storedPass);
        }

        // 2. Already-derived 40-character GJP2 hash.
        if (
            !$valid &&
            $storedGjp2 !== '' &&
            preg_match('/^[a-f0-9]{40}$/i', $credential) === 1
        ) {
            $valid = password_verify($credential, $storedGjp2);
        }

        // 3. URL-safe Base64 + XOR credential supported by older MuchoCore.
        if (!$valid) {
            $decodedPassword = $this->decodeXorCredential($credential);

            if ($decodedPassword !== null) {
                if ($storedPass !== '') {
                    $valid = password_verify(
                        $decodedPassword,
                        $storedPass
                    );
                }

                if (!$valid && $storedGjp2 !== '') {
                    $current = sha1(
                        $decodedPassword .
                        AccountService::GJP2_SALT
                    );

                    $legacy = sha1(
                        $decodedPassword .
                        AccountService::LEGACY_GJP2_SALT
                    );

                    $valid =
                        password_verify($current, $storedGjp2) ||
                        password_verify($legacy, $storedGjp2);
                }
            }
        }

        if (!$valid) {
            throw new RuntimeException('Unauthorized.');
        }

        if (empty($account['user_id'])) {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO profiles
                    (account_id, stars, created_at, updated_at)
                 VALUES
                    (:aid, 0, NOW(), NOW())'
            );

            $insert->execute([
                ':aid' => $accountId
            ]);

            $profile = $this->pdo->prepare(
                'SELECT user_id
                 FROM profiles
                 WHERE account_id = :account_id
                 LIMIT 1'
            );
            $profile->execute(['account_id' => $accountId]);
            $userId = (int)$profile->fetchColumn();

            if ($userId <= 0) {
                throw new RuntimeException('Unable to resolve profile user ID.');
            }

            $account['user_id'] = $userId;
        }

        return $account;
    }

    public function authenticateLegacy19Upload(
        int $accountId,
        string $udid,
        string $ip
    ): array {
        $udid = trim($udid);
        $ip = trim($ip);

        if (
            $accountId <= 0 ||
            $udid === '' ||
            strlen($udid) > 255 ||
            $ip === ''
        ) {
            throw new RuntimeException('Unauthorized.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                a.account_id,
                a.username,
                a.password_hash,
                a.gjp2_hash,
                a.is_active,
                a.is_banned,
                p.user_id,
                s.id AS session_id,
                s.udid_hash
             FROM accounts a
             LEFT JOIN profiles p
               ON p.account_id = a.account_id
             INNER JOIN mucho_legacy_19_sessions s
               ON s.account_id = a.account_id
              AND s.ip_address = :ip_address
              AND s.expires_at > UTC_TIMESTAMP()
             WHERE a.account_id = :account_id
             ORDER BY s.created_at DESC
             LIMIT 16'
        );

        $stmt->execute([
            'account_id' => $accountId,
            'ip_address' => $ip,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!password_verify($udid, (string)$row['udid_hash'])) {
                continue;
            }

            $touch = $this->pdo->prepare(
                'UPDATE mucho_legacy_19_sessions
                 SET last_used_at = UTC_TIMESTAMP(),
                     expires_at = DATE_ADD(
                         UTC_TIMESTAMP(),
                         INTERVAL 1 HOUR
                     )
                 WHERE id = :id'
            );

            $touch->execute([
                'id' => (int)$row['session_id'],
            ]);

            unset($row['session_id'], $row['udid_hash']);

            if (
                (int)$row['is_banned'] === 1 ||
                (int)$row['is_active'] !== 1
            ) {
                throw new RuntimeException('Unauthorized.');
            }

            return $row;
        }

        throw new RuntimeException('Unauthorized.');
    }

    private function decodeXorCredential(
        string $credential
    ): ?string {
        $normalized = strtr(
            $credential,
            '-_',
            '+/'
        );

        $padding = strlen($normalized) % 4;

        if ($padding !== 0) {
            $normalized .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            $normalized,
            true
        );

        if ($decoded === false || $decoded === '') {
            return null;
        }

        $result = '';
        $keyLength = strlen(self::XOR_KEY);

        for ($i = 0, $length = strlen($decoded); $i < $length; $i++) {
            $result .=
                $decoded[$i] ^
                self::XOR_KEY[$i % $keyLength];
        }

        if (
            $result === '' ||
            strlen($result) > 256 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $result)
        ) {
            return null;
        }

        return $result;
    }
}
