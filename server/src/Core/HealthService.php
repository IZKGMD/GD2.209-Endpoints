<?php

declare(strict_types=1);

namespace MuchoCore\Core;

use PDO;
use Throwable;

final readonly class HealthService
{
    public function __construct(
        private PDO $pdo,
        private SystemRepository $system,
    ) {
    }

    public function check(): array
    {
        $database = false;
        // The installed source is authoritative; a database can retain the
        // version seeded by the first installation after years of updates.
        $version = trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION'));
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            $version = 'unknown';
        }

        try {
            $database =
                $this->pdo
                    ->query('SELECT 1')
                    ->fetchColumn() == 1;
        } catch (Throwable) {
            $database = false;
        }

        return [
            'status' => $database
                ? 'ok'
                : 'degraded',

            'core' => 'MuchoCore',

            'version' => $version,

            'database' => $database
                ? 'ok'
                : 'error',

            'php' => PHP_VERSION,
        ];
    }
}
