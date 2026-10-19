<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use MuchoCore\Account\AccountAuthenticator;
use PDO;
use Throwable;

/**
 * Bounded GD 2.209 editor-sound content service.
 * The native game wire format remains provisional until client captures.
 */
final readonly class EditorSoundService
{
    private const MAX_BYTES = 2097152;
    private const MAX_ACCOUNT_BYTES = 20971520;
    private const MAX_ACCOUNT_FILES = 50;

    public function __construct(
        private PDO $db,
        private AccountAuthenticator $auth,
    ) {}

    private function authenticate(int $accountId, string $credential): void
    {
        $this->auth->authenticate($accountId, $credential);
    }

    /**
     * Inspect basic file type without relying on uploader-supplied MIME.
     * Only limited playable audio signatures are allowed.
     */
    public static function mime(string $audio): ?string
    {
        if (strlen($audio) < 12) {
            return null;
        }
        if (substr($audio, 0, 4) === 'RIFF' &&
            substr($audio, 8, 4) === 'WAVE') {
            return 'audio/wav';
        }
        if (substr($audio, 0, 4) === 'OggS') {
            return 'audio/ogg';
        }
        if (substr($audio, 0, 3) === 'ID3' ||
            (ord($audio[0]) === 255 && (ord($audio[1]) & 224) === 224)) {
            return 'audio/mpeg';
        }
        return null;
    }

    public static function validText(string $value, int $max): ?string
    {
        $value = trim($value);
        if (strlen($value) > $max * 4 ||
            !mb_check_encoding($value,'UTF-8') ||
            mb_strlen($value,'UTF-8') > $max ||
            preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }
        return $value;
    }

    public static function decodeAudio(string $encoded): ?string
    {
        if ($encoded === '' || strlen($encoded) > 2800000 ||
            preg_match('/^[A-Za-z0-9+\/_=-]+$/D', $encoded) !== 1) {
            return null;
        }
        $normalized = strtr($encoded, '-_', '+/');
        $audio = base64_decode($normalized, true);
        if ($audio === false || strlen($audio) < 12 ||
            strlen($audio) > self::MAX_BYTES ||
            self::mime($audio) === null) {
            return null;
        }
        return $audio;
    }

    public function upload(
        int $accountId,
        string $credential,
        string $title,
        string $description,
        string $encoded
    ): int {
        $this->authenticate($accountId, $credential);
        $title = self::validText($title, 120);
        $description = self::validText($description, 512);
        $audio = self::decodeAudio($encoded);
        if ($title === null || $title === '' || $description === null ||
            $audio === null) {
            return 0;
        }

        $size = strlen($audio);
        $sha256 = hash('sha256', $audio);
        $mime = self::mime($audio);

        $this->db->beginTransaction();
        try {
            // Lock account to serialize quota decisions under parallel uploads.
            $lock = $this->db->prepare(
                'SELECT account_id FROM accounts WHERE account_id=:account FOR UPDATE'
            );
            $lock->execute(['account'=>$accountId]);
            if ($lock->fetchColumn() === false) {
                $this->db->rollBack();
                return 0;
            }

            $duplicate = $this->db->prepare(
                'SELECT id FROM mucho_editor_sounds
                 WHERE account_id=:account AND sha256=:hash LIMIT 1'
            );
            $duplicate->execute(['account'=>$accountId,'hash'=>$sha256]);
            $oldId = $duplicate->fetchColumn();
            if ($oldId !== false) {
                $this->db->commit();
                return (int)$oldId;
            }

            $usage = $this->db->prepare(
                'SELECT COUNT(*) AS files,COALESCE(SUM(size_bytes),0) AS bytes
                 FROM mucho_editor_sounds WHERE account_id=:account'
            );
            $usage->execute(['account'=>$accountId]);
            $stats = $usage->fetch(PDO::FETCH_ASSOC);
            if ((int)($stats['files'] ?? 0) >= self::MAX_ACCOUNT_FILES ||
                (int)($stats['bytes'] ?? 0) + $size > self::MAX_ACCOUNT_BYTES) {
                $this->db->rollBack();
                return 0;
            }

            $stmt = $this->db->prepare(
                'INSERT INTO mucho_editor_sounds
                 (account_id,title,description,mime_type,audio_bytes,size_bytes,sha256)
                 VALUES (:account,:title,:description,:mime,:audio,:size,:hash)'
            );
            $stmt->execute([
                'account'=>$accountId,
                'title'=>$title,
                'description'=>$description,
                'mime'=>$mime,
                'audio'=>$audio,
                'size'=>$size,
                'hash'=>$sha256,
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(int $page, int $ownerId = 0): array
    {
        $offset = max(0, min($page, 1000)) * 20;
        $sql = 'SELECT s.id,s.account_id,s.title,s.description,
                       s.mime_type,s.size_bytes,s.sha256,s.created_at,a.username
                FROM mucho_editor_sounds s
                INNER JOIN accounts a ON a.account_id=s.account_id
                WHERE a.is_active=1 AND a.is_banned=0';
        $params = [];
        if ($ownerId > 0) {
            $sql .= ' AND s.account_id=:owner';
            $params['owner'] = $ownerId;
        }
        $sql .= " ORDER BY s.id DESC LIMIT 20 OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function download(int $soundId): ?string
    {
        if ($soundId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT s.audio_bytes
             FROM mucho_editor_sounds s
             JOIN accounts a ON a.account_id=s.account_id
             WHERE s.id=:id AND a.is_active=1 AND a.is_banned=0 LIMIT 1'
        );
        $stmt->execute(['id'=>$soundId]);
        $data = $stmt->fetchColumn();
        return is_string($data) ? $data : null;
    }

    public function updateDescription(
        int $accountId,
        string $credential,
        int $soundId,
        string $description
    ): bool {
        $this->authenticate($accountId, $credential);
        $description = self::validText($description, 512);
        if ($description === null || $soundId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE mucho_editor_sounds SET description=:description
             WHERE id=:id AND account_id=:account'
        );
        $stmt->execute(['description'=>$description,'id'=>$soundId,'account'=>$accountId]);
        if ($stmt->rowCount() === 1) {
            return true;
        }
        $check = $this->db->prepare(
            'SELECT 1 FROM mucho_editor_sounds WHERE id=:id AND account_id=:account'
        );
        $check->execute(['id'=>$soundId,'account'=>$accountId]);
        return $check->fetchColumn() !== false;
    }

    public function delete(int $accountId, string $credential, int $soundId): bool
    {
        $this->authenticate($accountId, $credential);
        if ($soundId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM mucho_editor_sounds WHERE id=:id AND account_id=:account'
        );
        $stmt->execute(['id'=>$soundId,'account'=>$accountId]);
        return $stmt->rowCount() === 1;
    }
}
