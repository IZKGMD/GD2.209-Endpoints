<?php

declare(strict_types=1);

namespace MuchoCore\Interaction;

use PDO;

final class RatingRepository
{
    public function __construct(
        private readonly PDO $db
    ) {}

    /**
     * Фиксирует голос за сложность (звезды).
     * Если пользователь уже голосовал, обновляет его старый голос.
     */
    public function rateStars(int $levelId, int $accountId, int $stars): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO level_ratings (level_id, account_id, rating) 
             VALUES (:level_id, :account_id, :rating)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), created_at = CURRENT_TIMESTAMP'
        );

        $stmt->execute([
            ':level_id' => $levelId,
            ':account_id' => $accountId,
            ':rating' => $stars
        ]);
    }
}
