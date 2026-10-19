<?php

declare(strict_types=1);

namespace MuchoCore\Job;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final readonly class JobQueue
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private PDO $pdo) {}

    public function enqueue(
        string $jobType,
        array $payload = [],
        int $delaySeconds = 0
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mucho_jobs(job_type,payload_json,available_at)
             VALUES(:job_type,:payload,DATE_ADD(UTC_TIMESTAMP(), INTERVAL :delay SECOND))'
        );

        $stmt->execute([
            'job_type' => substr($jobType, 0, 96),
            'payload' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            ),
            'delay' => max(0, $delaySeconds),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function reserve(string $workerId): ?array
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Job reservation requires its own transaction.');
        }
        $workerId = substr($workerId, 0, 96);
        if ($workerId === '') {
            throw new \InvalidArgumentException('Worker ID cannot be empty.');
        }
        return $this->withDeadlockRetry(fn(): ?array => $this->reserveOnce($workerId));
    }

    private function reserveOnce(string $workerId): ?array
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->query(
                "SELECT *
                 FROM mucho_jobs
                 WHERE status='queued'
                   AND available_at<=UTC_TIMESTAMP()
                 ORDER BY available_at ASC,id ASC
                 LIMIT 1
                 FOR UPDATE"
            );

            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE mucho_jobs
                 SET status='running',
                     attempts=attempts+1,
                     locked_at=UTC_TIMESTAMP(),
                     worker_id=:worker,
                     updated_at=CURRENT_TIMESTAMP
                 WHERE id=:id AND status='queued'"
            );
            $update->execute([
                'worker' => substr($workerId, 0, 96),
                'id' => (int)$job['id'],
            ]);

            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return null;
            }

            $this->pdo->commit();

            $job['attempts'] = (int)$job['attempts'] + 1;
            $job['status'] = 'running';
            $job['worker_id'] = $workerId;

            $payload = json_decode(
                (string)$job['payload_json'],
                true
            );
            $job['payload'] = is_array($payload) ? $payload : [];

            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function complete(int $jobId, string $workerId, int $attempt): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE mucho_jobs
             SET status='done',
                 locked_at=NULL,
                 worker_id=NULL,
                 last_error=NULL,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND status='running'
               AND worker_id=:worker AND attempts=:attempt"
        );
        return $this->executeUpdate($stmt, [
            'id' => $jobId, 'worker' => substr($workerId, 0, 96), 'attempt' => $attempt,
        ]) === 1;
    }

    public function fail(
        int $jobId,
        string $error,
        string $workerId,
        int $attempt,
        int $retryDelaySeconds = 30,
        int $maxAttempts = self::MAX_ATTEMPTS
    ): bool {
        $retryDelaySeconds = max(5, min(86400, $retryDelaySeconds));
        // The attempt counter fences a previous lease, including when the
        // same worker ID reserves this job again after stale recovery.
        $update = $this->pdo->prepare(
            "UPDATE mucho_jobs
             SET status=IF(attempts>=:max_attempts,'failed','queued'),
                 available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$retryDelaySeconds} SECOND),
                 last_error=:error,
                 locked_at=NULL,
                 worker_id=NULL,
                 updated_at=CURRENT_TIMESTAMP
             WHERE id=:id AND status='running'
               AND worker_id=:worker AND attempts=:attempt"
        );
        return $this->executeUpdate($update, [
            'id' => $jobId,
            'max_attempts' => max(1, $maxAttempts),
            'worker' => substr($workerId, 0, 96),
            'attempt' => $attempt,
            'error' => substr($error, 0, 2000),
        ]) === 1;
    }

    public function retryStale(int $minutes = 10, int $maxAttempts = self::MAX_ATTEMPTS): int
    {
        $minutes = max(1, min(1440, $minutes));

        $stmt = $this->pdo->prepare(
            "UPDATE mucho_jobs
             SET status=IF(attempts>=:max_attempts,'failed','queued'),
                 last_error='Worker lease expired.',
                 worker_id=NULL,
                 locked_at=NULL,
                 available_at=UTC_TIMESTAMP(),
                 updated_at=CURRENT_TIMESTAMP
             WHERE status='running'
               AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$minutes} MINUTE)"
        );

        return $this->executeUpdate($stmt, [
            'max_attempts' => max(1, $maxAttempts),
        ]);
    }

    private function executeUpdate(PDOStatement $stmt, array $parameters): int
    {
        return $this->withDeadlockRetry(static function () use ($stmt, $parameters): int {
            $stmt->execute($parameters);
            return $stmt->rowCount();
        });
    }

    private function withDeadlockRetry(Closure $operation): mixed
    {
        // Never replay one statement from a caller-owned transaction: the
        // caller must retry its entire transaction after a database deadlock.
        $ownsTransaction = !$this->pdo->inTransaction();
        for ($retry = 0; ; ++$retry) {
            try {
                return $operation();
            } catch (PDOException $e) {
                if (!$ownsTransaction || $retry >= 3 || (int)($e->errorInfo[1] ?? 0) !== 1213) {
                    throw $e;
                }
                usleep(random_int(1000, 5000) * ($retry + 1));
            }
        }
    }
}
