<?php

declare(strict_types=1);

/*
 * MuchoCore 1.0 integrity hardening.
 *
 * Authenticated votes remain unique per account, while anonymous votes are
 * uniquely identified by source IP. Existing authenticated rows are normalized
 * before the unique key is replaced.
 */
return static function(PDO $db): void {
    $exists = (int)$db->query(
        "SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = 'likes'"
    )->fetchColumn();

    if ($exists === 0) {
        return;
    }

    $db->exec(
        "UPDATE likes
         SET ip = ''
         WHERE account_id > 0"
    );

    $indexes = $db->query('SHOW INDEX FROM likes')->fetchAll(PDO::FETCH_ASSOC);

    $legacy = false;
    $current = false;

    foreach ($indexes as $index) {
        $name = (string)($index['Key_name'] ?? '');
        $unique = (int)($index['Non_unique'] ?? 1) === 0;

        if ($name === 'uq_like_item_user' && $unique) {
            $legacy = true;
        }

        if ($name === 'uq_like_item_actor' && $unique) {
            $current = true;
        }
    }

    if ($current) {
        return;
    }

    if ($legacy) {
        $db->exec('ALTER TABLE likes DROP INDEX uq_like_item_user');
    }

    /*
     * Older installations can contain duplicate anonymous/authenticated
     * rows from before the actor key was enforced. Keep the newest row for
     * each logical actor before adding the unique constraint.
     */
    $db->exec(<<<'SQL'
DELETE old_like
FROM likes old_like
INNER JOIN likes keep_like
    ON keep_like.item_id = old_like.item_id
   AND keep_like.type = old_like.type
   AND keep_like.account_id = old_like.account_id
   AND keep_like.ip = old_like.ip
   AND keep_like.id > old_like.id
SQL);

    $db->exec(
        'ALTER TABLE likes
         ADD UNIQUE KEY uq_like_item_actor
         (item_id, type, account_id, ip)'
    );
};
