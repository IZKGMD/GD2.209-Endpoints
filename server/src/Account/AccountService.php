<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use MuchoCore\Job\JobQueue;
use PDO;
use Throwable;

final readonly class AccountService
{
    public const GJP2_SALT = 'mI29fmAnxgTs';
    public const LEGACY_GJP2_SALT = 'mPtFakeGJKeyPair';

    public function __construct(
        private PDO $pdo,
        private AccountRepository $accounts,
        private JobQueue $jobs,
    ) {}

    public function register(
        string $username,
        string $password,
        string $email,
        string $ip
    ): string {
        $username = trim($username);
        $email = trim($email);

        if ($username === '' || $password === '' || $email === '') {
            return '-1';
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{1,20}$/D',
                $username
            ) !== 1
        ) {
            return '-4';
        }

        if (
            strlen($password) > 256 ||
            strlen($email) > 254
        ) {
            return '-1';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '-1';
        }

        if ($this->accounts->usernameExists($username)) {
            return '-2';
        }

        if ($this->accounts->emailExists($email)) {
            return '-3';
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $gjp2 = sha1($password . self::GJP2_SALT);
        $gjp2Hash = password_hash($gjp2, PASSWORD_DEFAULT);

        try {
            $this->pdo->beginTransaction();

            $accountId = $this->accounts->createAccount(
                $username,
                $email,
                $passwordHash,
                $gjp2Hash
            );

            $this->accounts->createProfile($accountId);
            $this->accounts->audit($accountId, 'account.register', $ip);

            $this->pdo->commit();

            try {
                $this->jobs->enqueue(
                    'webhook.dispatch',
                    [
                        'event' => 'account.registered',
                        'request_id' => (string)($_SERVER['MUCHO_REQUEST_ID'] ?? ''),
                        'data' => [
                            'account_id' => $accountId,
                            'username' => $username,
                        ],
                    ]
                );
            } catch (Throwable) {
            }

            return '1';
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function login(
        string $username,
        string $password,
        string $gjp2,
        string $ip,
        string $udid = ''
    ): string {
        $username = trim($username);

        if (
            $username === '' ||
            strlen($username) > 20 ||
            strlen($password) > 512 ||
            strlen($gjp2) > 512
        ) {
            return '-1';
        }

        $account = $this->accounts->findByUsername($username);

        if ($account === null) {
            return '-1';
        }

        if (
            (int)($account['is_banned'] ?? 0) === 1 ||
            (int)($account['is_active'] ?? 0) !== 1
        ) {
            return '-1';
        }

        $storedPass = (string)($account['password_hash'] ?? '');
        $storedGjp2 = (string)($account['gjp2_hash'] ?? '');

        $valid = false;

        if ($password !== '') {
            // Обычный пароль.
            $valid = password_verify($password, $storedPass);

            // Совместимость с хранимым GJP2.
            if (!$valid && $storedGjp2 !== '') {
                $derived = sha1($password . self::GJP2_SALT);
                $legacy = sha1($password . self::LEGACY_GJP2_SALT);

                $valid =
                    password_verify($derived, $storedGjp2) ||
                    password_verify($legacy, $storedGjp2);
            }

            // Некоторые клиенты/патчеры могут прислать готовый hash.
            if (
                !$valid &&
                preg_match('/^[a-f0-9]{40}$/i', $password) === 1 &&
                $storedGjp2 !== ''
            ) {
                $valid = password_verify($password, $storedGjp2);
            }
        }

        if (
            !$valid &&
            $gjp2 !== '' &&
            $storedGjp2 !== ''
        ) {
            $valid = password_verify($gjp2, $storedGjp2);
        }

        if (!$valid) {
            $this->accounts->audit(
                (int)$account['account_id'],
                'account.login_failed',
                $ip
            );

            return '-1';
        }

        $accountId = (int)$account['account_id'];
        $userId = $this->accounts->ensureProfile($accountId);

        /*
         * Everything below this point is post-login bookkeeping.
         *
         * IMPORTANT: the Geometry Dash login protocol must not turn a
         * successful credential check into "-1" just because an auxiliary
         * table, audit insert or compatibility session is unavailable.
         * Cvolton returns the "accountID,userID" result immediately after
         * resolving the user ID. Keep that same failure boundary here.
         */
        try {
            $this->accounts->markLogin($accountId);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore Login] markLogin failed for account %d: %s: %s',
                $accountId,
                $e::class,
                $e->getMessage()
            ));
        }

        try {
            $this->accounts->audit($accountId, 'account.login', $ip);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MuchoCore Login] audit failed for account %d: %s: %s',
                $accountId,
                $e::class,
                $e->getMessage()
            ));
        }

        /*
         * A small number of legacy 1.9 clients authenticate successfully but
         * omit GJP from the later upload request. Bind a short-lived upload
         * session to the same account, device UDID and source IP.
         */
        $udid = trim($udid);

        if ($udid !== '' && strlen($udid) <= 255) {
            try {
                $this->rememberLegacy19UploadSession(
                    $accountId,
                    $udid,
                    $ip
                );
            } catch (Throwable $e) {
                error_log(sprintf(
                    '[MuchoCore Login] legacy upload session unavailable for account %d: %s: %s',
                    $accountId,
                    $e::class,
                    $e->getMessage()
                ));
            }
        }

        /*
         * Protocol-critical response. Keep this as the final operation and
         * never allow auxiliary post-login persistence to replace it.
         */
        return $accountId . ',' . $userId;
    }

    private function rememberLegacy19UploadSession(
        int $accountId,
        string $udid,
        string $ip
    ): void {
        if ($accountId <= 0 || $udid === '' || $ip === '') {
            return;
        }

        $cleanup = $this->pdo->prepare(
            'DELETE FROM mucho_legacy_19_sessions
             WHERE expires_at <= UTC_TIMESTAMP()
                OR (
                    account_id = :account_id
                    AND ip_address = :ip_address
                )'
        );

        $cleanup->execute([
            'account_id' => $accountId,
            'ip_address' => $ip,
        ]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO mucho_legacy_19_sessions
                (account_id, udid_hash, ip_address, expires_at)
             VALUES
                (:account_id, :udid_hash, :ip_address,
                 DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))'
        );

        $stmt->execute([
            'account_id' => $accountId,
            'udid_hash' => password_hash($udid, PASSWORD_DEFAULT),
            'ip_address' => $ip,
        ]);
    }
}
