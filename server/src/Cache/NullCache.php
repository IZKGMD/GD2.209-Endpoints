<?php

declare(strict_types=1);

namespace MuchoCore\Cache;

final class NullCache implements CacheInterface
{
    public function get(string $key): ?string { return null; }
    public function set(string $key, string $value, int $ttlSeconds): void {}
    public function delete(string $key): void {}
    public function deletePrefix(string $prefix): void {}
}
