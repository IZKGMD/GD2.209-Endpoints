<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use PDO;
use PDOException;

/** Shared check for actual, non-expired 2.209 account restrictions. */
final class Gd2209Restrictions
{
    public static function schemaAvailable(PDO $db): bool
    {
        // Legacy isolated tests and old, not-yet-migrated environments may
        // predate this feature. No query referencing the new table is added
        // to a legacy level search when the table does not exist.
        $q = $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
             AND TABLE_NAME='mucho_gd2209_restrictions'"
        );
        return (int)$q->fetchColumn() > 0;
    }

    public static function active(PDO $db, int $accountId, string $kind): bool
    {
        if ($accountId <= 0 || !in_array($kind, ['comment','upload','message','trending','name'], true)) {
            return false;
        }
        try {
            $q = $db->prepare(
                'SELECT 1 FROM mucho_gd2209_restrictions
                 WHERE account_id=:id AND kind=:kind
                   AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                 LIMIT 1'
            );
            $q->execute(['id'=>$accountId, 'kind'=>$kind]);
            return $q->fetchColumn() !== false;
        } catch (PDOException $e) {
            // Legacy isolated tests built prior to the 2.209 restrictions migration
            // lack this table. On a correctly updated production install it exists.
            if ($e->getCode() === '42S02') {
                return false;
            }
            throw $e;
        }
    }
}
