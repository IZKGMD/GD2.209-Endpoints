<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;

final class RatingService
{
    public function __construct(
        private readonly RatingRepository $repository,
        private readonly AccountAuthenticator $auth
    ) {}

    public function rateLevel(int $levelId, int $accountId, string $gjp, int $stars): void
    {
        // Базовая защита от подмены ID
        $this->auth->authenticate($accountId, $gjp);
        
        // Защита от мусорных данных (в GD максимум 10 звезд)
        if ($stars < 1 || $stars > 10) {
            return; // Просто игнорируем некорректные запросы
        }

        $this->repository->rateStars($levelId, $accountId, $stars);
    }
}
