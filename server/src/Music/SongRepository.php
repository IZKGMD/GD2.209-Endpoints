<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use PDO;

final readonly class SongRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function findSong(int $songId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM songs
            WHERE id = :id
              AND is_verified = 1
            LIMIT 1');
        $stmt->execute(['id' => $songId]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
