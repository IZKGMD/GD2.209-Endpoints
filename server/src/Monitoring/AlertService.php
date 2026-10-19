<?php

declare(strict_types=1);

namespace MuchoCore\Monitoring;

use MuchoCore\Job\JobQueue;
use PDO;
use Throwable;

final readonly class AlertService
{
    public function __construct(
        private PDO $pdo,
        private ?JobQueue $jobs = null
    ) {}

    public function ensureStorage(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS mucho_system_alerts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                severity ENUM('info','warning','critical')
                    NOT NULL DEFAULT 'warning',
                source VARCHAR(64) NOT NULL,
                message VARCHAR(500) NOT NULL,
                resolved TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL DEFAULT NULL,
                KEY idx_alert_resolved(resolved),
                KEY idx_alert_created(created_at),
                KEY idx_alert_source_message(source,message)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
             COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function raise(
        string $severity,
        string $source,
        string $message
    ): bool {
        $severity = in_array(
            $severity,
            ['info','warning','critical'],
            true
        ) ? $severity : 'warning';

        $source = substr(trim($source), 0, 64);
        $message = substr(trim($message), 0, 500);

        if ($source === '' || $message === '') {
            return false;
        }

        try {
            $this->ensureStorage();

            $check = $this->pdo->prepare(
                'SELECT id
                 FROM mucho_system_alerts
                 WHERE resolved=0
                   AND source=:source
                   AND message=:message
                 LIMIT 1'
            );
            $check->execute([
                'source' => $source,
                'message' => $message,
            ]);

            if ($check->fetchColumn() !== false) {
                return false;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO mucho_system_alerts
                    (severity,source,message)
                 VALUES
                    (:severity,:source,:message)'
            );
            $insert->execute([
                'severity' => $severity,
                'source' => $source,
                'message' => $message,
            ]);

            if ($this->jobs !== null) {
                try {
                    $this->jobs->enqueue(
                        'webhook.dispatch',
                        [
                            'event' => 'system.alert',
                            'data' => [
                                'severity' => $severity,
                                'source' => $source,
                                'message' => $message,
                            ],
                        ]
                    );
                } catch (Throwable) {
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function resolve(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE mucho_system_alerts
                 SET resolved=1,resolved_at=UTC_TIMESTAMP()
                 WHERE id=:id AND resolved=0'
            );
            $stmt->execute(['id' => $id]);

            return $stmt->rowCount() === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
