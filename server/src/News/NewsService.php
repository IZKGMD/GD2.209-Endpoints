<?php

declare(strict_types=1);

namespace MuchoCore\News;

use PDO;
use RuntimeException;

/**
 * Owner-managed news source of truth.
 *
 * Public readers receive published items only, without editor-only fields.
 * The native 2.209 /news/news.dat wire format is intentionally NOT guessed here.
 */
final class NewsService
{
    public function __construct(private readonly PDO $db) {}

    public static function validate(string $title, string $body, string $url, bool $hasMedia=false): array
    {
        $title = trim(str_replace(["\r", "\n", "\0"], ' ', $title));
        $body = trim(str_replace("\0", '', $body));
        $url = trim($url);

        if ($title === '' || mb_strlen($title, 'UTF-8') > 140) {
            throw new NewsValidationException('News title must contain 1–140 characters.');
        }
        if (($body === '' && !$hasMedia) || mb_strlen($body, 'UTF-8') > 5000) {
            throw new NewsValidationException('News body must contain 1–5000 characters.');
        }
        if (strlen($url) > 512 || ($url !== '' && !self::isSafeLink($url))) {
            throw new NewsValidationException('News link must be an HTTPS URL (up to 512 bytes).');
        }

        return ['title' => $title, 'body' => $body, 'external_url' => $url !== '' ? $url : null];
    }

    public static function isSafeLink(string $url): bool
    {
        if (preg_match('~^https://~i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        return !preg_match('/[\x00-\x20\x7f]/', $url);
    }

    public function allForOwner(): array
    {
        return $this->db->query(
            'SELECT id,title,body,external_url,is_published,is_pinned,author_admin_id,
                    created_at,updated_at,published_at
             FROM mucho_news_posts ORDER BY is_pinned DESC, updated_at DESC, id DESC LIMIT 150'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function published(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            'SELECT id,title,body,external_url,is_pinned,published_at
             FROM mucho_news_posts WHERE is_published=1
             ORDER BY is_pinned DESC, published_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'body' => (string)$row['body'],
                'url' => $row['external_url'] !== null ? (string)$row['external_url'] : null,
                'pinned' => (int)$row['is_pinned'] === 1,
                'published_at' => (string)$row['published_at'],
            ];
        }
        $media=(new NewsMediaService($this->db))->forPosts(array_column($rows,'id'));
        foreach ($rows as &$row) $row['media']=$media[$row['id']] ?? [];
        unset($row);
        return $rows;
    }

    /**
     * A persistent monotonic version avoids stale clients when a news update
     * happens to produce a smaller content hash, or the last post is removed.
     */
    public function nativeVersion(): string
    {
        $version = $this->db->query(
            'SELECT version FROM mucho_news_state WHERE id=1'
        )->fetchColumn();
        if ($version === false) {
            throw new RuntimeException('Native news version state is missing.');
        }
        return (string)$version;
    }

    private function bumpNativeVersion(): void
    {
        $updated = $this->db->exec(
            'UPDATE mucho_news_state SET version=version+1 WHERE id=1'
        );
        if ($updated !== 1) {
            throw new RuntimeException('Unable to increment native news version.');
        }
    }

    public function save(
        int $id,
        string $title,
        string $body,
        string $url,
        bool $publish,
        bool $pin,
        int $adminId,
        ?array $mediaChanges=null
    ): int {
        $media=new NewsMediaService($this->db);
        $hasMedia=$mediaChanges!==null || ($id>0 && $media->forOwner($id)!==[]);
        $fields = self::validate($title, $body, $url, $hasMedia);
        $this->db->beginTransaction();
        try {
        $fields['published'] = $publish ? 1 : 0;
        $fields['pinned'] = $pin ? 1 : 0;
        if ($id > 0) {
            $fields['id'] = $id;
            $stmt = $this->db->prepare(
                'UPDATE mucho_news_posts
                 SET title=:title,body=:body,external_url=:external_url,
                     is_published=:published,is_pinned=:pinned,
                     published_at=CASE
                         WHEN :publish_now=1 THEN COALESCE(published_at,UTC_TIMESTAMP())
                         ELSE NULL END
                 WHERE id=:id'
            );
            // Separate marker avoids duplicated PDO named placeholders.
            $fields['publish_now'] = $publish ? 1 : 0;
            $stmt->execute($fields);
            if ($stmt->rowCount() === 0) {
                $exists = $this->db->prepare('SELECT 1 FROM mucho_news_posts WHERE id=:id');
                $exists->execute(['id' => $id]);
                if ($exists->fetchColumn() === false) {
                    throw new RuntimeException('News post not found.');
                }
            }
            if ($mediaChanges!==null) $media->apply($id,$mediaChanges);
            if ($fields['body']==='' && $media->forOwner($id)===[]) throw new NewsValidationException('Add news text, an image or a YouTube video.');
            $this->bumpNativeVersion();
            $this->db->commit();
            return $id;
        }

        $fields['author_id'] = $adminId;
        $stmt = $this->db->prepare(
            'INSERT INTO mucho_news_posts
             (title,body,external_url,is_published,is_pinned,author_admin_id,published_at)
             VALUES (:title,:body,:external_url,:published,:pinned,:author_id,
                     CASE WHEN :publish_now=1 THEN UTC_TIMESTAMP() ELSE NULL END)'
        );
        $fields['publish_now'] = $publish ? 1 : 0;
        $stmt->execute($fields);
        $createdId = (int)$this->db->lastInsertId();
        if ($mediaChanges!==null) $media->apply($createdId,$mediaChanges);
        if ($fields['body']==='' && $media->forOwner($createdId)===[]) throw new NewsValidationException('Add news text, an image or a YouTube video.');
        $this->bumpNativeVersion();
        $this->db->commit();
        return $createdId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function togglePublished(int $id): void
    {
        if ($id < 1) {
            throw new RuntimeException('Invalid news ID.');
        }
        $this->db->beginTransaction();
        try {
        $stmt = $this->db->prepare(
            'UPDATE mucho_news_posts
             SET published_at=IF(is_published=1,NULL,UTC_TIMESTAMP()),
                 is_published=IF(is_published=1,0,1)
             WHERE id=:id'
        );
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('News post not found.');
        }
        $this->bumpNativeVersion();
        $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        if ($id < 1) {
            throw new RuntimeException('Invalid news ID.');
        }
        $this->db->beginTransaction();
        try {
        $stmt = $this->db->prepare('DELETE FROM mucho_news_posts WHERE id=:id');
        $stmt->execute(['id' => $id]);
        $deleted = $stmt->rowCount() === 1;
        if ($deleted) {
            $this->bumpNativeVersion();
        }
        $this->db->commit();
        return $deleted;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
