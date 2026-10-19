<?php

declare(strict_types=1);

namespace MuchoCore\Backup;

use PDO;
use RuntimeException;
use Throwable;

final class DatabaseBackupService
{
    private const MIN_BACKUP_BYTES = 100;
    private const ROW_BATCH = 250;
    private const MAX_BATCH_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    public function create(string $label = 'muchocore'): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!is_dir($this->directory)
            && !mkdir($this->directory, 0750, true)
            && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create backup directory.');
        }

        if (!is_writable($this->directory)) {
            throw new RuntimeException('Backup directory is not writable.');
        }

        $lockPath = rtrim($this->directory, '/\\') . '/.backup.lock';
        $lock = fopen($lockPath, 'c');

        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another database backup is already running.');
        }

        $safeLabel = preg_replace('/[^A-Za-z0-9_.-]/', '_', $label) ?: 'muchocore';
        $final = rtrim($this->directory, '/\\') . '/'
            . $safeLabel . '_' . gmdate('Ymd_His') . '.sql.gz';
        $temporary = $final . '.tmp';
        $checksumFile = $final . '.sha256';

        $estimatedBytes = $this->databaseSizeBytes();
        $freeBytes = @disk_free_space($this->directory);
        if (is_float($freeBytes) || is_int($freeBytes)) {
            $requiredBytes = max(
                16 * 1024 * 1024,
                (int)ceil($estimatedBytes * 1.50) + 8 * 1024 * 1024
            );

            if ((float)$freeBytes < $requiredBytes) {
                throw new RuntimeException(
                    'Not enough free storage for a safe database backup.'
                );
            }
        }

        $startedTransaction = false;

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->exec(
                    'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'
                );
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $gzip = gzopen($temporary, 'wb9');

            if ($gzip === false) {
                throw new RuntimeException('Unable to create compressed backup file.');
            }

            try {
                $this->write($gzip, "-- MuchoCore database backup\n");
                $this->write($gzip, "-- Generated: " . gmdate('c') . "\n");
                $this->write($gzip, "SET NAMES utf8mb4;\n");
                $this->write($gzip, "SET FOREIGN_KEY_CHECKS=0;\n\n");

                $objects = $this->objects();

                foreach ($objects['tables'] as $table) {
                    $this->writeTable($gzip, $table);
                }

                foreach ($objects['views'] as $view) {
                    $this->writeView($gzip, $view);
                }

                $this->write($gzip, "SET FOREIGN_KEY_CHECKS=1;\n");
            } finally {
                gzclose($gzip);
            }

            $size = filesize($temporary);

            if ($size === false || $size < self::MIN_BACKUP_BYTES) {
                @unlink($temporary);
                throw new RuntimeException('Generated database backup is unexpectedly small.');
            }

            $this->verifyGzip($temporary);

            if (!rename($temporary, $final)) {
                throw new RuntimeException('Unable to publish the verified database backup.');
            }

            $sha256 = hash_file('sha256', $final);

            if ($sha256 === false || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                @unlink($final);
                throw new RuntimeException('Unable to calculate the database backup checksum.');
            }

            if (file_put_contents(
                $checksumFile,
                $sha256 . '  ' . basename($final) . PHP_EOL,
                LOCK_EX
            ) === false) {
                @unlink($final);
                throw new RuntimeException('Unable to write database backup checksum.');
            }

            $this->verifyChecksum($final, $checksumFile);

            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
                $startedTransaction = false;
            }

            return [
                'file' => $final,
                'sha256' => $sha256,
                'size' => (int)$size,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            @unlink($temporary);
            @unlink($final);
            @unlink($checksumFile);
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function databaseSizeBytes(): int
    {
        $statement = $this->pdo->query(
            'SELECT COALESCE(SUM(data_length + index_length), 0)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()'
        );

        return max(0, (int)$statement->fetchColumn());
    }

    private function objects(): array
    {
        $tables = [];
        $views = [];

        $statement = $this->pdo->query('SHOW FULL TABLES');

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            $name = isset($row[0]) && is_string($row[0]) ? $row[0] : '';
            $type = strtoupper((string)($row[1] ?? ''));

            if ($name === '') {
                continue;
            }

            if ($type === 'VIEW') {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }

        sort($tables, SORT_STRING);
        sort($views, SORT_STRING);

        return ['tables' => $tables, 'views' => $views];
    }

    private function writeTable($gzip, string $table): void
    {
        $quotedTable = $this->quoteIdentifier($table);
        $create = $this->pdo->query(
            'SHOW CREATE TABLE ' . $quotedTable
        )->fetch(PDO::FETCH_ASSOC);

        $createSql = (string)($create['Create Table'] ?? '');

        if ($createSql === '') {
            throw new RuntimeException('Unable to read schema for table ' . $table . '.');
        }

        $this->write($gzip, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
        $this->write($gzip, $createSql . ";\n\n");

        $columns = $this->columns($table);

        if ($columns === []) {
            return;
        }

        $columnSql = implode(', ', array_map(
            fn(string $column): string => $this->quoteIdentifier($column),
            $columns
        ));

        $buffered = true;

        try {
            $buffered = (bool)$this->pdo->getAttribute(
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY
            );
        } catch (Throwable) {
        }

        try {
            $this->pdo->setAttribute(
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,
                false
            );

            $query = $this->pdo->query(
                'SELECT ' . $columnSql . ' FROM ' . $quotedTable
            );
            $query->setFetchMode(PDO::FETCH_ASSOC);

            $rows = [];
            $batchBytes = 0;

            while (($row = $query->fetch()) !== false) {
                $values = [];

                foreach ($columns as $column) {
                    $values[] = $this->sqlValue($row[$column] ?? null);
                }

                $rowSql = '(' . implode(', ', $values) . ')';
                $rowBytes = strlen($rowSql) + 2;
                // Profile JPEGs can be hundreds of KiB per row. Bound both
                // memory usage and restore statement size, not just row count.
                if ($rows !== [] && $batchBytes + $rowBytes > self::MAX_BATCH_BYTES) {
                    $this->writeInsert($gzip, $quotedTable, $columnSql, $rows);
                    $rows = []; $batchBytes = 0;
                }
                $rows[] = $rowSql; $batchBytes += $rowBytes;

                if (count($rows) >= self::ROW_BATCH) {
                    $this->writeInsert($gzip, $quotedTable, $columnSql, $rows);
                    $rows = [];
                    $batchBytes = 0;
                }
            }

            if ($rows !== []) {
                $this->writeInsert($gzip, $quotedTable, $columnSql, $rows);
            }

            $this->write($gzip, "\n");
        } finally {
            try {
                $this->pdo->setAttribute(
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,
                    $buffered
                );
            } catch (Throwable) {
            }
        }
    }

    private function columns(string $table): array
    {
        $columns = [];
        $statement = $this->pdo->query(
            'SHOW COLUMNS FROM ' . $this->quoteIdentifier($table)
        );

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['Field'] ?? '');
            $extra = strtoupper((string)($row['Extra'] ?? ''));

            if ($name === '' || str_contains($extra, 'GENERATED')) {
                continue;
            }

            $columns[] = $name;
        }

        return $columns;
    }

    private function writeInsert($gzip, string $table, string $columns, array $rows): void
    {
        $this->write(
            $gzip,
            'INSERT INTO ' . $table . ' (' . $columns . ') VALUES '
            . implode(",\n", $rows) . ";\n"
        );
    }

    private function writeView($gzip, string $view): void
    {
        $quotedView = $this->quoteIdentifier($view);
        $row = $this->pdo->query(
            'SHOW CREATE VIEW ' . $quotedView
        )->fetch(PDO::FETCH_ASSOC);

        $createSql = (string)($row['Create View'] ?? '');

        if ($createSql === '') {
            throw new RuntimeException('Unable to read schema for view ' . $view . '.');
        }

        $this->write(
            $gzip,
            'DROP VIEW IF EXISTS ' . $quotedView . ";\n"
            . $createSql . ";\n\n"
        );
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $quoted = $this->pdo->quote((string)$value);

        if ($quoted === false) {
            throw new RuntimeException('Unable to quote a database value for backup.');
        }

        return $quoted;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new RuntimeException('Invalid database identifier.');
        }

        $quote = chr(96);
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function write($gzip, string $content): void
    {
        if (gzwrite($gzip, $content) !== strlen($content)) {
            throw new RuntimeException('Failed while writing the database backup.');
        }
    }

    private function verifyGzip(string $file): void
    {
        $gzip = gzopen($file, 'rb');

        if ($gzip === false) {
            throw new RuntimeException('Generated database backup cannot be opened.');
        }

        try {
            $sample = gzread($gzip, 4096);
        } finally {
            gzclose($gzip);
        }

        if (!is_string($sample) || !str_contains($sample, 'MuchoCore database backup')) {
            throw new RuntimeException('Generated database backup failed integrity verification.');
        }
    }

    private function verifyChecksum(string $file, string $checksumFile): void
    {
        $line = trim((string)file_get_contents($checksumFile));
        $parts = preg_split('/\s+/', $line);
        $expected = is_array($parts) ? (string)($parts[0] ?? '') : '';

        if (!preg_match('/^[a-f0-9]{64}$/i', $expected)) {
            throw new RuntimeException('Database backup checksum file is invalid.');
        }

        $actual = hash_file('sha256', $file);

        if ($actual === false || !hash_equals(strtolower($expected), strtolower($actual))) {
            @unlink($file);
            @unlink($checksumFile);
            throw new RuntimeException('Database backup checksum verification failed.');
        }
    }
}
