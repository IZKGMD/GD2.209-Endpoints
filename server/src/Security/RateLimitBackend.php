<?php
declare(strict_types=1);

namespace MuchoCore\Security;

interface RateLimitBackend
{
    public function allowStrict(string $key,int $limit,int $windowSeconds): bool;
    public function allow(string $key,int $limit,int $windowSeconds): bool;
    public function cleanup(int $staleAfterSeconds=3600,int $maxEntries=64): int;
}
