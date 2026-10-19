<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use MuchoCore\Account\AccountAuthenticator;

final class LikeService
{
    public function __construct(
        private readonly LikeRepository $repository,
        private readonly AccountAuthenticator $auth
    ) {}

    public function likeItem(
        int $itemId,
        int $type,
        int $accountId,
        string $gjp,
        bool $isLike
    ): void {
        $this->auth->authenticate($accountId, $gjp);
        $this->repository->addLike(
            $itemId,
            $type,
            $accountId,
            $isLike,
            ''
        );
    }

    public function likeAnonymous(
        int $itemId,
        int $type,
        string $ip
    ): void {
        $this->repository->addLike(
            $itemId,
            $type,
            0,
            true,
            $ip
        );
    }
}
