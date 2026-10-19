<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use PDO;
use Throwable;

final readonly class LevelRevisionService
{
    public function __construct(private PDO $pdo) {}

    public function record(
        array $level,
        int $changedByAccountId,
        string $reason = 'save'
    ): ?int {
        $levelId = (int)($level['level_id'] ?? 0);
        $payload = (string)($level['level_data'] ?? '');

        if ($levelId <= 0 || $payload === '') {
            return null;
        }

        try {
            $hash = hash('sha256', $payload);

            $latest = $this->pdo->prepare(
                'SELECT revision_no,payload_sha256
                 FROM mucho_level_revisions
                 WHERE level_id=:level_id
                 ORDER BY revision_no DESC
                 LIMIT 1'
            );
            $latest->execute(['level_id' => $levelId]);
            $row = $latest->fetch(PDO::FETCH_ASSOC);

            if ($row && hash_equals((string)$row['payload_sha256'], $hash)) {
                return (int)$row['revision_no'];
            }

            $revision = $row ? (int)$row['revision_no'] + 1 : 1;
            $gzip = function_exists('gzencode')
                ? gzencode($payload, 6)
                : false;

            $stmt = $this->pdo->prepare(
                'INSERT INTO mucho_level_revisions
                    (level_id,revision_no,payload_sha256,payload_gzip,
                     snapshot_json,changed_by_account_id,reason)
                 VALUES
                    (:level_id,:revision_no,:hash,:gzip,:snapshot,
                     :account_id,:reason)'
            );

            $stmt->bindValue(':level_id', $levelId, PDO::PARAM_INT);
            $stmt->bindValue(':revision_no', $revision, PDO::PARAM_INT);
            $stmt->bindValue(':hash', $hash);
            if ($gzip === false) {
                $stmt->bindValue(':gzip', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':gzip', $gzip, PDO::PARAM_LOB);
            }
            $stmt->bindValue(
                ':snapshot',
                json_encode(self::snapshot($level), JSON_THROW_ON_ERROR)
            );
            $stmt->bindValue(
                ':account_id',
                $changedByAccountId > 0 ? $changedByAccountId : null,
                $changedByAccountId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL
            );
            $stmt->bindValue(':reason', substr($reason, 0, 64));
            $stmt->execute();

            return $revision;
        } catch (Throwable) {
            return null;
        }
    }

    public function list(int $levelId, int $limit = 50): array
    {
        if ($levelId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT id,level_id,revision_no,payload_sha256,
                    OCTET_LENGTH(payload_gzip) AS payload_bytes,
                    changed_by_account_id,reason,created_at
             FROM mucho_level_revisions
             WHERE level_id=:level_id
             ORDER BY revision_no DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['level_id' => $levelId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function restore(int $levelId, int $revisionNo): bool
    {
        if ($levelId <= 0 || $revisionNo <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM mucho_level_revisions
             WHERE level_id=:level_id AND revision_no=:revision_no
             LIMIT 1'
        );
        $stmt->execute([
            'level_id' => $levelId,
            'revision_no' => $revisionNo,
        ]);
        $revision = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$revision || $revision['payload_gzip'] === null) {
            return false;
        }

        $payload = function_exists('gzdecode')
            ? @gzdecode((string)$revision['payload_gzip'])
            : false;
        if ($payload === false) {
            return false;
        }

        $snapshot = json_decode((string)$revision['snapshot_json'], true);
        if (!is_array($snapshot)) {
            return false;
        }

        $fields = [
            'name','description','level_version','game_version',
            'binary_version','length','audio_track','song_id',
            'copy_password','original_level_id','two_player',
            'object_count','coins','requested_stars','is_unlisted',
            'unlisted2','wt','wt2','extra_string','level_info',
            'settings_string','song_ids','sfx_ids','ldm','ts'
        ];

        $sets = ['level_data=:level_data','updated_at=CURRENT_TIMESTAMP'];
        $params = ['level_data' => $payload, 'level_id' => $levelId];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $snapshot)) {
                continue;
            }
            $sets[] = $field . '=:' . $field;
            $params[$field] = $snapshot[$field];
        }

        $this->pdo->beginTransaction();

        try {
            $update = $this->pdo->prepare(
                'UPDATE levels
                 SET ' . implode(',', $sets) . '
                 WHERE level_id=:level_id AND is_deleted=0'
            );
            $update->execute($params);

            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }

    private static function snapshot(array $level): array
    {
        $fields = [
            'name','description','level_version','game_version',
            'binary_version','length','audio_track','song_id',
            'copy_password','original_level_id','two_player',
            'object_count','coins','requested_stars','is_unlisted',
            'unlisted2','wt','wt2','extra_string','level_info',
            'settings_string','song_ids','sfx_ids','ldm','ts'
        ];

        $snapshot = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $level)) {
                $snapshot[$field] = $level[$field];
            }
        }

        return $snapshot;
    }
}
