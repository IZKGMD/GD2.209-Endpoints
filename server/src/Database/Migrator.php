<?php

declare(strict_types=1);

namespace MuchoCore\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationPath,
    ) {
        $this->createMigrationTable();
    }

    private function createMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function migrate(): void
    {
        /*
         * The app entrypoint runs migrations automatically. Administrators may
         * also invoke bin/mucho-migrate.php manually during deployment, so two
         * processes can legitimately reach this method at the same time.
         *
         * MySQL advisory locking serializes migration execution across all
         * MuchoCore processes that share the same database.
         */
        $lock = 'muchocore:schema-migrations';

        $lockStmt = $this->pdo->query(
            "SELECT GET_LOCK(" .
            $this->pdo->quote($lock) .
            ", 30)"
        );

        if ((int)$lockStmt->fetchColumn() !== 1) {
            throw new RuntimeException(
                'Unable to acquire the MuchoCore migration lock within 30 seconds.'
            );
        }

        try {
            foreach ($this->migrationFiles() as $file) {
                $version = basename($file, '.php');

                if ($this->isApplied($version)) {
                    echo "[SKIP] {$version}\n";
                    continue;
                }

                echo "[RUN ] {$version}\n";

                $migration = require $file;
                $this->applyMigration($migration, $version);

                $stmt = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version)
                     VALUES (:version)'
                );

                /*
                 * Keep the second check immediately before the insert. The
                 * advisory lock should make it unnecessary under normal use,
                 * but this also keeps the state correct if the lock is removed
                 * by an external database operation.
                 */
                if ($this->isApplied($version)) {
                    echo "[SKIP] {$version} (already recorded)\n";
                    continue;
                }

                $stmt->execute([
                    'version' => $version,
                ]);

                echo "[ OK ] {$version}\n";
            }
        } finally {
            $this->pdo->query(
                "SELECT RELEASE_LOCK(" .
                $this->pdo->quote($lock) .
                ")"
            );
        }
    }

    public function status(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $version = basename($file, '.php');

            echo sprintf(
                "%-10s %s\n",
                $this->isApplied($version) ? '[APPLIED]' : '[PENDING]',
                $version
            );
        }
    }

    private function applyMigration(
        mixed $migration,
        string $version
    ): void {
        if ($migration instanceof Migration) {
            $migration->up($this->pdo);
            return;
        }

        if (is_callable($migration)) {
            $migration($this->pdo);
            return;
        }

        if (is_array($migration)) {
            foreach ($migration as $sql) {
                if (!is_string($sql) || trim($sql) === '') {
                    continue;
                }

                $this->pdo->exec($sql);
            }

            return;
        }

        throw new RuntimeException(
            "Migration {$version} must return SQL array, callable, or Migration object"
        );
    }

    private function migrationFiles(): array
    {
        $files = glob($this->migrationPath . '/*.php') ?: [];
        sort($files, SORT_STRING);

        return array_values($files);
    }

    private function isApplied(string $version): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM schema_migrations
             WHERE version = :version
             LIMIT 1'
        );

        $stmt->execute([
            'version' => $version,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
