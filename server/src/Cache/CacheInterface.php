<?php

declare(strict_types=1);

namespace MuchoCore\Cache;

interface CacheInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $value, int $ttlSeconds): void;
    public function delete(string $key): void;
    public function deletePrefix(string $prefix): void;
}
