<?php
declare(strict_types=1);

namespace MuchoCore\Artist;

use PDO;
use MuchoCore\V71\SchemaInspector;
use RuntimeException;

final class TopArtistRepository
{
    private SchemaInspector $schema;

    public function __construct(private readonly PDO $db)
    {
        $this->schema = new SchemaInspector($db);
    }

    public function page(int $offset, int $limit): array
    {
        if (
            !$this->schema->tableExists('songs') ||
            !$this->schema->columnExists('songs', 'author_name')
        ) {
            throw new RuntimeException(
                'songs.author_name missing'
            );
        }

        $stmt = $this->db->prepare(
            "SELECT
                author_name AS authorName,
                MIN(download_url) AS download,
                COUNT(*) AS song_count

             FROM songs

             WHERE author_name IS NOT NULL
               AND author_name <> ''
               AND author_name NOT LIKE '%Reupload%'
               AND LOWER(author_name) <> 'unknown'

             GROUP BY author_name

             ORDER BY
                song_count DESC,
                author_name ASC

             LIMIT :limit OFFSET :offset"
        );

        $stmt->bindValue(
            ':limit',
            $limit,
            PDO::PARAM_INT
        );

        $stmt->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $count = $this->db->query(
            "SELECT COUNT(DISTINCT author_name)
             FROM songs
             WHERE author_name IS NOT NULL
               AND author_name <> ''
               AND author_name NOT LIKE '%Reupload%'
               AND LOWER(author_name) <> 'unknown'"
        );

        return [
            'rows' => $stmt->fetchAll() ?: [],
            'total' => (int)$count->fetchColumn()
        ];
    }
}
