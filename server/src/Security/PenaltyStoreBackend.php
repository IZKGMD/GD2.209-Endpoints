<?php
declare(strict_types=1);

namespace MuchoCore\Security;

interface PenaltyStoreBackend
{
    /** @return array{active:bool,remaining:int,strikes:int} */
    public function status(string $key): array;
    /** @return array{seconds:int,strikes:int} */
    public function penalize(string $key,int $baseSeconds=15,int $maxSeconds=900,int $strikeWindowSeconds=600): array;
    public function cleanup(int $staleAfterSeconds=3600,int $maxEntries=64): int;
}
