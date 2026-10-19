<?php

declare(strict_types=1);

namespace MuchoCore\Cache;

use MuchoCore\Core\Environment;
use PDO;
use Throwable;

final class CacheManager
{
    public static function fromEnvironment(PDO $pdo): CacheInterface
    {
        $driver = strtolower(trim((string)(
            Environment::get('MUCHO_CACHE_DRIVER', 'database')
        )));

        if ($driver === '' || $driver === 'none' || $driver === 'null') {
            return new NullCache();
        }

        if ($driver === 'redis') {
            try {
                return new RedisCache([
                    'host' => Environment::get('MUCHO_REDIS_HOST', '127.0.0.1'),
                    'port' => Environment::get('MUCHO_REDIS_PORT', '6379'),
                    'password' => Environment::get('MUCHO_REDIS_PASSWORD', ''),
                    'database' => Environment::get('MUCHO_REDIS_DATABASE', '0'),
                    'timeout' => Environment::get('MUCHO_REDIS_TIMEOUT', '1.5'),
                ]);
            } catch (Throwable) {
            }
        }

        return new DatabaseCache($pdo);
    }
}
