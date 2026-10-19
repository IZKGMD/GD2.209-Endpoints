<?php

declare(strict_types=1);

/*
 * MuchoCore v7.6 Moderation
 * Copyright (C) 2026 IZK
 */

namespace MuchoCore\Moderation;

use MuchoCore\Account\AccountAuthenticator;
use RuntimeException;

final readonly class ModerationService
{
    private const MODERATOR_ROLES=[
        'moderator',
        'elder_moderator',
        'owner'
    ];

    private const DIRECT_RATE_ROLES=[
        'elder_moderator',
        'owner'
    ];

    public function __construct(
        private AccountAuthenticator $auth,
        private ModerationRepository $repository
    ) {}

    public function suggestStars(
        int $accountId,
        string $gjp,
        int $levelId,
        int $stars,
        int $featureTier,
        int $coinsVerified
    ): void {
        $this->auth->authenticate(
            $accountId,
            $gjp
        );

        $this->validateStars($stars);

        if (
            $featureTier < 0 ||
            $featureTier > 4
        ) {
            throw new RuntimeException(
                'Invalid feature tier'
            );
        }

        $coinsVerified=$coinsVerified > 0
            ? 1
            : 0;

        if (!$this->repository->levelExists($levelId)) {
            throw new RuntimeException(
                'Level not found'
            );
        }

        $role=$this->repository
            ->getAccountRole($accountId);

        if (
            !in_array(
                $role,
                self::MODERATOR_ROLES,
                true
            )
        ) {
            throw new RuntimeException(
                'Moderator role required'
            );
        }

        if (
            in_array(
                $role,
                self::DIRECT_RATE_ROLES,
                true
            )
        ) {
            $author=$this->repository
                ->applyFullRate(
                    $levelId,
                    $stars,
                    $featureTier,
                    $coinsVerified
                );

            if ($author === null) {
                throw new RuntimeException(
                    'Level not found'
                );
            }

            $this->repository->logAction(
                $accountId,
                $levelId,
                'RATE_APPLIED',
                [
                    'stars'=>$stars,
                    'feature_tier'=>$featureTier,
                    'coins_verified'=>$coinsVerified,
                    'author_account_id'=>$author
                ]
            );

            return;
        }

        $this->repository->recordSuggestion(
            $levelId,
            $accountId,
            $stars,
            $featureTier,
            $coinsVerified
        );

        $this->repository->logAction(
            $accountId,
            $levelId,
            'SUGGESTION_SENT',
            [
                'stars'=>$stars,
                'feature_tier'=>$featureTier,
                'coins_verified'=>$coinsVerified
            ]
        );
    }

    public function rateStars(
        int $accountId,
        string $gjp,
        int $levelId,
        int $stars
    ): void {
        $this->auth->authenticate(
            $accountId,
            $gjp
        );

        $this->validateStars($stars);

        $role=$this->repository
            ->getAccountRole($accountId);

        if (
            !in_array(
                $role,
                self::DIRECT_RATE_ROLES,
                true
            )
        ) {
            throw new RuntimeException(
                'Direct rate permission required'
            );
        }

        $author=$this->repository
            ->applyStarRate(
                $levelId,
                $stars
            );

        if ($author === null) {
            throw new RuntimeException(
                'Level not found'
            );
        }

        $this->repository->logAction(
            $accountId,
            $levelId,
            'STAR_RATE_APPLIED',
            [
                'stars'=>$stars,
                'author_account_id'=>$author
            ]
        );
    }

    public function rateDemon(
        int $accountId,
        string $gjp,
        int $levelId,
        int $rating
    ): void {
        $this->auth->authenticate(
            $accountId,
            $gjp
        );

        if ($rating < 1 || $rating > 8) {
            throw new RuntimeException(
                'Invalid demon rating'
            );
        }

        $role=$this->repository
            ->getAccountRole($accountId);

        if (
            !in_array(
                $role,
                self::MODERATOR_ROLES,
                true
            )
        ) {
            throw new RuntimeException(
                'Demon rate permission required'
            );
        }

        if (
            !$this->repository
                ->applyDemonDifficulty(
                    $levelId,
                    $rating
                )
        ) {
            throw new RuntimeException(
                'Level not found'
            );
        }

        $this->repository->logAction(
            $accountId,
            $levelId,
            'DEMON_DIFFICULTY_APPLIED',
            [
                'rating'=>$rating
            ]
        );
    }

    public function reportLevel(
        int $levelId,
        string $reporterHash
    ): int {
        if ($levelId <= 0) {
            return 0;
        }

        return $this->repository
            ->reportLevel(
                $levelId,
                $reporterHash
            );
    }

    private function validateStars(
        int $stars
    ): void {
        if ($stars < 1 || $stars > 10) {
            throw new RuntimeException(
                'Invalid stars'
            );
        }
    }
}
