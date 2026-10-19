<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use PDO;
use RuntimeException;

final readonly class LevelTransferRepository
{
    private const WRITABLE_FIELDS = [
        'account_id',
        'name',
        'description',
        'level_version',
        'game_version',
        'binary_version',
        'length',
        'audio_track',
        'song_id',
        'copy_password',
        'original_level_id',
        'two_player',
        'object_count',
        'coins',
        'requested_stars',
        'is_unlisted',
        'unlisted2',
        'wt',
        'wt2',
        'extra_string',
        'level_data',
        'level_info',
        'ldm',
        'ts',
    ];

    public function __construct(
        private PDO $pdo
    ) {}

    public function findLevel(int $levelId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, COALESCE(p.user_id, l.account_id) AS user_id
             FROM levels l
             LEFT JOIN profiles p ON p.account_id = l.account_id
             WHERE l.level_id = :level_id
               AND l.is_deleted = 0
             LIMIT 1'
        );

        $stmt->execute([
            'level_id' => $levelId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function incrementDownloads(int $levelId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE levels
             SET downloads = downloads + 1
             WHERE level_id = :level_id
               AND is_deleted = 0'
        );

        $stmt->execute([
            'level_id' => $levelId
        ]);
    }

    public function saveLevel(array $data): int
    {
        $levelId = (int)($data['level_id'] ?? 0);

        if ($levelId > 0) {
            $this->updateOwnedLevel($levelId, $data);
            return $levelId;
        }

        return $this->insertLevel($data);
    }

    private function insertLevel(array $data): int
    {
        $data = $this->filterFields($data);

        $fields = array_keys($data);

        if ($fields === []) {
            throw new RuntimeException('No level fields supplied.');
        }

        $columns = implode(', ', array_map(
            static fn(string $field): string => "`{$field}`",
            $fields
        ));

        $placeholders = implode(', ', array_map(
            static fn(string $field): string => ':' . $field,
            $fields
        ));

        $stmt = $this->pdo->prepare(
            "INSERT INTO levels ({$columns})
             VALUES ({$placeholders})"
        );

        $stmt->execute($data);

        $id = (int)$this->pdo->lastInsertId();

        if ($id <= 0) {
            throw new RuntimeException('Level insert failed.');
        }

        return $id;
    }

    private function updateOwnedLevel(
        int $levelId,
        array $data
    ): void {
        $accountId = (int)($data['account_id'] ?? 0);

        if ($accountId <= 0) {
            throw new RuntimeException('Missing level owner.');
        }

        $data = $this->filterFields($data);

        unset($data['account_id']);

        $assignments = [];

        foreach (array_keys($data) as $field) {
            $assignments[] = "`{$field}` = :{$field}";
        }

        if ($assignments === []) {
            return;
        }

        $data['level_id'] = $levelId;
        $data['owner_account_id'] = $accountId;

        $stmt = $this->pdo->prepare(
            'UPDATE levels
             SET ' . implode(', ', $assignments) . ',
                 updated_at = CURRENT_TIMESTAMP
             WHERE level_id = :level_id
               AND account_id = :owner_account_id
               AND is_deleted = 0'
        );

        $stmt->execute($data);

        /*
         * rowCount() может быть 0, если клиент загрузил
         * абсолютно те же данные, поэтому ownership
         * дополнительно проверяем SELECT-ом.
         */
        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT 1
                 FROM levels
                 WHERE level_id = :level_id
                   AND account_id = :account_id
                   AND is_deleted = 0
                 LIMIT 1'
            );

            $check->execute([
                'level_id' => $levelId,
                'account_id' => $accountId,
            ]);

            if ($check->fetchColumn() === false) {
                throw new RuntimeException(
                    'Level ownership mismatch.'
                );
            }
        }
    }

    public function deleteLevel(
        int $levelId,
        int $accountId
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE levels
             SET is_deleted = 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE level_id = :level_id
               AND account_id = :account_id
               AND is_deleted = 0'
        );

        $stmt->execute([
            'level_id' => $levelId,
            'account_id' => $accountId
        ]);

        return $stmt->rowCount() === 1;
    }

    public function updateDescription(
        int $levelId,
        int $accountId,
        string $description
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE levels
             SET description = :description,
                 updated_at = CURRENT_TIMESTAMP
             WHERE level_id = :level_id
               AND account_id = :account_id
               AND is_deleted = 0'
        );

        $stmt->execute([
            'description' => $description,
            'level_id' => $levelId,
            'account_id' => $accountId,
        ]);

        if ($stmt->rowCount() === 1) {
            return true;
        }

        $check = $this->pdo->prepare(
            'SELECT 1
             FROM levels
             WHERE level_id = :level_id
               AND account_id = :account_id
               AND is_deleted = 0
             LIMIT 1'
        );

        $check->execute([
            'level_id' => $levelId,
            'account_id' => $accountId,
        ]);

        return $check->fetchColumn() !== false;
    }

    private function filterFields(array $data): array
    {
        return array_intersect_key(
            $data,
            array_flip(self::WRITABLE_FIELDS)
        );
    }
}
