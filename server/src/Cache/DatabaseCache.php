<?php

declare(strict_types=1);

namespace MuchoCore\Cache;

use PDO;
use Throwable;

final readonly class DatabaseCache implements CacheInterface
{
    public function __construct(private PDO $pdo) {}

    public function get(string $key): ?string
    {
        try {
            $key = substr($key, 0, 191);
            $now = time();
            $stmt = $this->pdo->prepare(
                'SELECT cache_value,expires_at FROM mucho_cache
                 WHERE cache_key = :key LIMIT 1'
            );
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            if ((int)$row['expires_at'] > 0 && (int)$row['expires_at'] <= $now) {
                // A writer may have refreshed this key since our SELECT.
                // Delete only a value that is still expired, never its replacement.
                $delete = $this->pdo->prepare(
                    'DELETE FROM mucho_cache WHERE cache_key=:key
                     AND expires_at > 0 AND expires_at <= :now'
                );
                $delete->execute(['key' => $key, 'now' => $now]);
                return null;
            }
            return (string)$row['cache_value'];
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mucho_cache(cache_key,cache_value,expires_at)
                 VALUES(:key,:value,:expires)
                 ON DUPLICATE KEY UPDATE
                   cache_value=VALUES(cache_value),
                   expires_at=VALUES(expires_at),
                   updated_at=CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                'key' => substr($key, 0, 191),
                'value' => $value,
                'expires' => $ttlSeconds > 0 ? time() + $ttlSeconds : 0,
            ]);
        } catch (Throwable) {
        }
    }

    public function delete(string $key): void
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM mucho_cache WHERE cache_key=:key');
            $stmt->execute(['key' => substr($key, 0, 191)]);
        } catch (Throwable) {
        }
    }

    public function deletePrefix(string $prefix): void
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM mucho_cache WHERE cache_key LIKE :prefix ESCAPE '!'");
            $stmt->execute(['prefix' => strtr($prefix, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%']);
        } catch (Throwable) {
        }
    }
}
