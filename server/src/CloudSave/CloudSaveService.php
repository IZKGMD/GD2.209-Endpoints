<?php

declare(strict_types=1);

namespace MuchoCore\CloudSave;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use RuntimeException;

final readonly class CloudSaveService
{
    private const MAX_SAVE_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
        private CloudSaveRepository $repository
    ) {}

    public function backup(array $data): string
    {
        $accountId = $this->authenticate($data);

        $saveData = $data['saveData'] ?? null;

        if (!is_string($saveData) || $saveData === '') {
            throw new RuntimeException('Missing cloud save payload.');
        }

        $size = strlen($saveData);
        if ($size <= 0 || $size > self::MAX_SAVE_BYTES) {
            throw new RuntimeException('Cloud save exceeds size limit.');
        }

        $this->repository->save($accountId, $saveData);

        return '1';
    }

    public function sync(array $data): string
    {
        $accountId = $this->authenticate($data);

        $saveData = $this->repository->load($accountId);

        if ($saveData === null || $saveData === '') {
            return '-1';
        }

        // Preserve the stored save payload byte-for-byte. Trimming can
        // corrupt a legitimate legacy payload before it is sent back.
        if (!str_contains($saveData, ';21;30;a;a')) {
            $saveData .= ';21;30;a;a';
        }

        return $saveData;
    }

    private function authenticate(array $data): int
    {
        $accountId = (int)($data['accountID'] ?? 0);
        $username = trim((string)($data['userName'] ?? $data['username'] ?? ''));

        if ($accountId <= 0 && $username !== '') {
            $q = $this->db->prepare("
                SELECT account_id
                FROM accounts
                WHERE username = :username
                LIMIT 1
            ");
            $q->execute(['username' => $username]);
            $accountId = (int)$q->fetchColumn();
        }

        // Match both the classic Cvolton contract and MuchoCore's GJP2 flow.
        // Some clients submit an empty legacy password field alongside GJP/GJP2,
        // so choose the first non-empty credential instead of the first present key.
        $credential = '';

        foreach (['password', 'gjp2', 'gjp'] as $key) {
            $candidate = $data[$key] ?? '';

            if (!is_scalar($candidate)) {
                continue;
            }

            $candidate = trim((string)$candidate);

            if ($candidate !== '') {
                $credential = $candidate;
                break;
            }
        }

        if ($accountId <= 0 || $credential === '') {
            throw new RuntimeException('Unauthorized cloud save account.');
        }

        $this->auth->authenticate($accountId, $credential);

        return $accountId;
    }
}
