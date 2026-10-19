<?php
declare(strict_types=1);

namespace MuchoCore\Security;

use MuchoCore\Core\Environment;
use PDO;
use Throwable;

final readonly class DatabaseRateLimiter implements RateLimitBackend
{
    public function __construct(private PDO $db) {}

    public function allowStrict(string $key,int $limit,int $windowSeconds): bool
    {
        if ($limit < 1 || $windowSeconds < 1) return false;

        $bucket = hash('sha256', $key);
        $now = time();

        try {
            $this->db->beginTransaction();

            $insert = $this->db->prepare(
                'INSERT INTO mucho_security_rate_limits
                 (bucket_key,window_start,request_count)
                 VALUES(:bucket,:start,0)
                 ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)'
            );
            $insert->execute(['bucket'=>$bucket,'start'=>$now]);

            $select = $this->db->prepare(
                'SELECT window_start,request_count
                 FROM mucho_security_rate_limits
                 WHERE bucket_key=:bucket
                 FOR UPDATE'
            );
            $select->execute(['bucket'=>$bucket]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $this->db->rollBack();
                return false;
            }

            $start = (int)($row['window_start'] ?? 0);
            $count = (int)($row['request_count'] ?? 0);

            if ($start <= 0 || ($now - $start) >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }

            ++$count;

            $update = $this->db->prepare(
                'UPDATE mucho_security_rate_limits
                 SET window_start=:start,request_count=:count,updated_at=CURRENT_TIMESTAMP
                 WHERE bucket_key=:bucket'
            );
            $update->execute([
                'start'=>$start,
                'count'=>$count,
                'bucket'=>$bucket,
            ]);

            $this->db->commit();
            return $count <= $limit;
        } catch (Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return false;
        }
    }

    public function allow(string $key,int $limit,int $windowSeconds): bool
    {
        if ($this->failOpen()) return true;
        return $this->allowStrict($key,$limit,$windowSeconds);
    }

    public function cleanup(int $staleAfterSeconds=3600,int $maxEntries=64): int
    {
        if ($staleAfterSeconds < 1 || $maxEntries < 1) return 0;

        $cutoff = time() - $staleAfterSeconds;
        $maxEntries = max(1,min(1000,$maxEntries));

        try {
            $stmt = $this->db->prepare(
                "DELETE FROM mucho_security_rate_limits
                 WHERE updated_at < FROM_UNIXTIME(:cutoff)
                 ORDER BY updated_at ASC LIMIT {$maxEntries}"
            );
            $stmt->execute(['cutoff'=>$cutoff]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    private function failOpen(): bool
    {
        return in_array(
            strtolower(trim((string)(Environment::get('MUCHO_PROTECT_FAIL_OPEN', '') ?? ''))),
            ['1','true','yes','on'],
            true
        );
    }
}
