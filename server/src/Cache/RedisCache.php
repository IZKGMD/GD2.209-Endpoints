<?php

declare(strict_types=1);

namespace MuchoCore\Cache;

use Redis;
use RuntimeException;
use Throwable;

final class RedisCache implements CacheInterface
{
    private Redis $redis;

    public function __construct(array $config)
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('PHP Redis extension is unavailable.');
        }

        $this->redis = new Redis();
        if (!$this->redis->connect(
            (string)($config['host'] ?? '127.0.0.1'),
            (int)($config['port'] ?? 6379),
            (float)($config['timeout'] ?? 1.5)
        )) {
            throw new RuntimeException('Redis connection failed.');
        }

        $password = (string)($config['password'] ?? '');
        if ($password !== '') {
            $this->redis->auth($password);
        }

        $database = max(0, (int)($config['database'] ?? 0));
        if ($database > 0) {
            $this->redis->select($database);
        }
    }

    public function get(string $key): ?string
    {
        try {
            $value = $this->redis->get($key);
            return $value === false ? null : (string)$value;
        } catch (Throwable) {
            return null;
        }
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        try {
            if ($ttlSeconds > 0) {
                $this->redis->setex($key, $ttlSeconds, $value);
            } else {
                $this->redis->set($key, $value);
            }
        } catch (Throwable) {
        }
    }

    public function delete(string $key): void
    {
        try { $this->redis->del($key); } catch (Throwable) {}
    }

    public function deletePrefix(string $prefix): void
    {
        try {
            $iterator = null;
            $pattern = strtr($prefix, ['\\' => '\\\\', '*' => '\\*', '?' => '\\?', '[' => '\\[', ']' => '\\]']) . '*';
            do {
                $keys = $this->redis->scan($iterator, $pattern, 200);
                if ($keys !== false && $keys !== []) {
                    $this->redis->del($keys);
                }
            } while ($iterator !== 0);
        } catch (Throwable) {
        }
    }
}
