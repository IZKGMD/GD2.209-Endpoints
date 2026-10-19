<?php
declare(strict_types=1);

namespace MuchoCore\Security;

use PDO;
use Throwable;

final readonly class DatabasePenaltyStore implements PenaltyStoreBackend
{
    public function __construct(private PDO $db) {}

    public function status(string $key): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT expires_at,strikes
                 FROM mucho_security_penalties
                 WHERE bucket_key=:bucket LIMIT 1'
            );
            $stmt->execute(['bucket'=>hash('sha256',$key)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) return ['active'=>false,'remaining'=>0,'strikes'=>0];

            $remaining = (int)($row['expires_at'] ?? 0) - time();
            return [
                'active' => $remaining > 0,
                'remaining' => max(0,$remaining),
                'strikes' => max(0,(int)($row['strikes'] ?? 0)),
            ];
        } catch (Throwable) {
            return ['active'=>false,'remaining'=>0,'strikes'=>0];
        }
    }

    public function penalize(string $key,int $baseSeconds=15,int $maxSeconds=900,int $strikeWindowSeconds=600): array
    {
        if ($baseSeconds < 1 || $maxSeconds < $baseSeconds) {
            return ['seconds'=>0,'strikes'=>0];
        }

        $bucket = hash('sha256',$key);
        $now = time();

        try {
            $this->db->beginTransaction();

            $insert = $this->db->prepare(
                'INSERT INTO mucho_security_penalties
                 (bucket_key,expires_at,last_at,strikes)
                 VALUES(:bucket,0,0,0)
                 ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)'
            );
            $insert->execute(['bucket'=>$bucket]);

            $select = $this->db->prepare(
                'SELECT last_at,strikes
                 FROM mucho_security_penalties
                 WHERE bucket_key=:bucket FOR UPDATE'
            );
            $select->execute(['bucket'=>$bucket]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                $this->db->rollBack();
                return ['seconds'=>0,'strikes'=>0];
            }

            $last = (int)($row['last_at'] ?? 0);
            $oldStrikes = max(0,(int)($row['strikes'] ?? 0));
            $strikes = ($last > 0 && ($now - $last) <= $strikeWindowSeconds)
                ? min(8,$oldStrikes+1)
                : 1;

            $seconds = min($maxSeconds,$baseSeconds * (2 ** min(7,$strikes-1)));

            $update = $this->db->prepare(
                'UPDATE mucho_security_penalties
                 SET expires_at=:expires,last_at=:last_at,strikes=:strikes,updated_at=CURRENT_TIMESTAMP
                 WHERE bucket_key=:bucket'
            );
            $update->execute([
                'expires'=>$now + (int)$seconds,
                'last_at'=>$now,
                'strikes'=>$strikes,
                'bucket'=>$bucket,
            ]);

            $this->db->commit();
            return ['seconds'=>(int)$seconds,'strikes'=>$strikes];
        } catch (Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['seconds'=>0,'strikes'=>0];
        }
    }

    public function cleanup(int $staleAfterSeconds=3600,int $maxEntries=64): int
    {
        if ($staleAfterSeconds < 1 || $maxEntries < 1) return 0;

        $cutoff = time() - $staleAfterSeconds;
        $maxEntries = max(1,min(1000,$maxEntries));

        try {
            $stmt = $this->db->prepare(
                "DELETE FROM mucho_security_penalties
                 WHERE updated_at < FROM_UNIXTIME(:cutoff)
                 ORDER BY updated_at ASC LIMIT {$maxEntries}"
            );
            $stmt->execute(['cutoff'=>$cutoff]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }
}
