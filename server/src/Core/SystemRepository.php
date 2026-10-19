<?php

declare(strict_types=1);

namespace MuchoCore\Core;

use PDO;

final readonly class SystemRepository
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function setting(
        string $key,
        ?string $default = null
    ): ?string {
        $stmt = $this->pdo->prepare(
            'SELECT setting_value
             FROM server_settings
             WHERE setting_key = :key
             LIMIT 1'
        );

        $stmt->execute([
            'key' => $key,
        ]);

        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return (string) $value;
    }

    public function boolSetting(
        string $key,
        bool $default = false
    ): bool {
        $value = $this->setting(
            $key,
            $default ? '1' : '0'
        );

        return $value === '1';
    }
}
