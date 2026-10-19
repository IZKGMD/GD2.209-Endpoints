<?php

declare(strict_types=1);

/*
 * MuchoCore v7.6 Moderation
 * Copyright (C) 2026 IZK
 */

namespace MuchoCore\Moderation;

use PDO;

final readonly class ModerationRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function getAccountRole(int $accountId): string
    {
        $q=$this->pdo->prepare(
            'SELECT COALESCE(r.code, \'user\') AS role
             FROM accounts a
             LEFT JOIN roles r
               ON r.id=a.role_id
             WHERE a.account_id=:id
               AND is_active=1
               AND is_banned=0
             LIMIT 1'
        );

        $q->execute(['id'=>$accountId]);

        return strtolower(
            (string)($q->fetchColumn() ?: 'user')
        );
    }

    public function levelExists(int $levelId): bool
    {
        $q=$this->pdo->prepare(
            'SELECT 1
             FROM levels
             WHERE level_id=:id
               AND is_deleted=0
             LIMIT 1'
        );

        $q->execute(['id'=>$levelId]);

        return (bool)$q->fetchColumn();
    }

    public function recordSuggestion(
        int $levelId,
        int $accountId,
        int $stars,
        int $featureTier,
        int $coinsVerified
    ): void {
        $q=$this->pdo->prepare(
            'INSERT INTO moderation_suggestions
             (
                level_id,
                account_id,
                stars,
                feature_tier,
                coins_verified
             )
             VALUES
             (
                :level,
                :account,
                :stars,
                :feature,
                :coins
             )'
        );

        $q->execute([
            'level'=>$levelId,
            'account'=>$accountId,
            'stars'=>$stars,
            'feature'=>$featureTier,
            'coins'=>$coinsVerified
        ]);
    }

    public function applyFullRate(
        int $levelId,
        int $stars,
        int $featureTier,
        int $coinsVerified
    ): ?int {
        $author=$this->author($levelId);

        if ($author === null) {
            return null;
        }

        [$difficulty,$auto,$demon]
            =$this->starState($stars);

        $featured=$featureTier >= 1 ? 1 : 0;

        $epic=match($featureTier){
            2 => 1,
            3 => 2,
            4 => 3,
            default => 0
        };

        $q=$this->pdo->prepare(
            'UPDATE levels
             SET
                stars=:stars,
                difficulty=:difficulty,
                auto_level=:auto,
                demon=:demon,
                featured=:featured,
                epic=:epic,
                coins_verified=:coins
             WHERE level_id=:id
               AND is_deleted=0'
        );

        $q->execute([
            'stars'=>$stars,
            'difficulty'=>$difficulty,
            'auto'=>$auto,
            'demon'=>$demon,
            'featured'=>$featured,
            'epic'=>$epic,
            'coins'=>$coinsVerified,
            'id'=>$levelId
        ]);

        $this->recalculateCreatorPoints($author);

        return $author;
    }

    public function applyStarRate(
        int $levelId,
        int $stars
    ): ?int {
        $author=$this->author($levelId);

        if ($author === null) {
            return null;
        }

        [$difficulty,$auto,$demon]
            =$this->starState($stars);

        $q=$this->pdo->prepare(
            'UPDATE levels
             SET
                stars=:stars,
                difficulty=:difficulty,
                auto_level=:auto,
                demon=:demon,
                demon_difficulty=0,
                custom_difficulty=0
             WHERE level_id=:id
               AND is_deleted=0'
        );

        $q->execute([
            'stars'=>$stars,
            'difficulty'=>$difficulty,
            'auto'=>$auto,
            'demon'=>$demon,
            'id'=>$levelId
        ]);

        $this->recalculateCreatorPoints($author);

        return $author;
    }

    public function applyDemonDifficulty(
        int $levelId,
        int $rating
    ): bool {
        $difficulty=match($rating){
            1 => 3,
            2 => 4,
            3 => 0,
            4 => 5,
            5 => 6,
            6 => 8,   // INSANED
            7 => 9,   // BRUTAL
            8 => 10,  // NIGHTMARE
            default => -1
        };

        if ($difficulty < 0) {
            return false;
        }

        $customDifficulty=match($rating){
            6 => 1,
            7 => 2,
            8 => 3,
            default => 0
        };

        $stars=match($customDifficulty){
            1 => 15,
            2 => 30,
            3 => 50,
            default => 10
        };

        $q=$this->pdo->prepare(
            'UPDATE levels
             SET
                stars=:stars,
                demon=1,
                difficulty=50,
                auto_level=0,
                demon_difficulty=:difficulty,
                custom_difficulty=:custom_difficulty
             WHERE level_id=:id
               AND is_deleted=0'
        );

        $q->execute([
            'stars'=>$stars,
            'difficulty'=>$difficulty,
            'custom_difficulty'=>$customDifficulty,
            'id'=>$levelId
        ]);

        return $q->rowCount() === 1
            || $this->levelExists($levelId);
    }

    public function reportLevel(
        int $levelId,
        string $reporterHash
    ): int {
        if (!$this->levelExists($levelId)) {
            return 0;
        }

        $q=$this->pdo->prepare(
            'INSERT IGNORE INTO mucho_level_reports
             (level_id,reporter_hash)
             VALUES (:level,:reporter)'
        );

        $q->execute([
            'level'=>$levelId,
            'reporter'=>$reporterHash
        ]);

        if ($q->rowCount() !== 1) {
            return 0;
        }

        return (int)$this->pdo->lastInsertId();
    }

    public function recalculateCreatorPoints(
        int $authorAccountId
    ): void {
        $q=$this->pdo->prepare(
            'SELECT
                COALESCE(
                    SUM(
                        (CASE
                            WHEN stars>0 THEN 1
                            ELSE 0
                        END)
                        +
                        (CASE
                            WHEN featured>0 THEN 1
                            ELSE 0
                        END)
                        +
                        epic
                    ),
                    0
                )
             FROM levels
             WHERE account_id=:account
               AND is_deleted=0'
        );

        $q->execute([
            'account'=>$authorAccountId
        ]);

        $cp=(int)$q->fetchColumn();

        $q=$this->pdo->prepare(
            'UPDATE profiles
             SET creator_points=:cp
             WHERE account_id=:account'
        );

        $q->execute([
            'cp'=>$cp,
            'account'=>$authorAccountId
        ]);
    }

    public function logAction(
        int $moderatorAccountId,
        int $levelId,
        string $action,
        array $details
    ): void {
        $q=$this->pdo->prepare(
            'INSERT INTO moderation_logs
             (
                moderator_account_id,
                level_id,
                action,
                details
             )
             VALUES
             (
                :moderator,
                :level,
                :action,
                :details
             )'
        );

        $q->execute([
            'moderator'=>$moderatorAccountId,
            'level'=>$levelId,
            'action'=>$action,
            'details'=>json_encode(
                $details,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        ]);
    }

    private function author(
        int $levelId
    ): ?int {
        $q=$this->pdo->prepare(
            'SELECT account_id
             FROM levels
             WHERE level_id=:id
               AND is_deleted=0
             LIMIT 1'
        );

        $q->execute(['id'=>$levelId]);

        $id=$q->fetchColumn();

        return $id === false
            ? null
            : (int)$id;
    }

    private function starState(
        int $stars
    ): array {
        return match($stars){
            1 => [50,1,0],
            2 => [10,0,0],
            3 => [20,0,0],
            4,5 => [30,0,0],
            6,7 => [40,0,0],
            8,9 => [50,0,0],
            10 => [50,0,1],
            default => [0,0,0]
        };
    }
}
