<?php

declare(strict_types=1);

/*
 * MuchoCore 1.0 comment integrity repair.
 *
 * This migration is intentionally idempotent because older installations may
 * already have a partially-created or older comment schema.
 */
return static function (PDO $db): void {
    $tableExists = static function (PDO $db, string $table): bool {
        $q = $db->prepare(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table"
        );
        $q->execute(['table' => $table]);

        return (int)$q->fetchColumn() > 0;
    };

    $columnExists = static function (
        PDO $db,
        string $table,
        string $column
    ): bool {
        $q = $db->prepare(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column"
        );
        $q->execute([
            'table' => $table,
            'column' => $column,
        ]);

        return (int)$q->fetchColumn() > 0;
    };

    $indexExists = static function (
        PDO $db,
        string $table,
        string $index
    ): bool {
        $q = $db->prepare(
            "SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND index_name = :index"
        );
        $q->execute([
            'table' => $table,
            'index' => $index,
        ]);

        return (int)$q->fetchColumn() > 0;
    };

    if (!$tableExists($db, 'comments')) {
        $db->exec(<<<'SQL'
CREATE TABLE comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_id INT UNSIGNED NOT NULL DEFAULT 0,
    account_id INT UNSIGNED NOT NULL DEFAULT 0,
    content TEXT NOT NULL,
    percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    likes INT NOT NULL DEFAULT 0,
    is_spam TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_comments_level_id (level_id),
    KEY idx_comments_account_id (account_id),
    KEY idx_comments_likes (likes),
    KEY idx_comments_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    } else {
        if (!$columnExists($db, 'comments', 'level_id')) {
            $db->exec(
                'ALTER TABLE comments
                 ADD COLUMN level_id INT UNSIGNED NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'comments', 'account_id')) {
            $db->exec(
                'ALTER TABLE comments
                 ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'comments', 'content')) {
            $db->exec(
                "ALTER TABLE comments
                 ADD COLUMN content TEXT NOT NULL"
            );
        }

        if (!$columnExists($db, 'comments', 'percent')) {
            $db->exec(
                'ALTER TABLE comments
                 ADD COLUMN percent TINYINT UNSIGNED NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'comments', 'likes')) {
            $db->exec(
                'ALTER TABLE comments
                 ADD COLUMN likes INT NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'comments', 'is_spam')) {
            $db->exec(
                'ALTER TABLE comments
                 ADD COLUMN is_spam TINYINT(1) NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'comments', 'created_at')) {
            $db->exec(
                'ALTER TABLE comments
                 ADD COLUMN created_at TIMESTAMP NOT NULL
                 DEFAULT CURRENT_TIMESTAMP'
            );
        }
    }

    foreach ([
        'idx_comments_level_id' =>
            'ALTER TABLE comments ADD KEY idx_comments_level_id (level_id)',
        'idx_comments_account_id' =>
            'ALTER TABLE comments ADD KEY idx_comments_account_id (account_id)',
        'idx_comments_likes' =>
            'ALTER TABLE comments ADD KEY idx_comments_likes (likes)',
        'idx_comments_created_at' =>
            'ALTER TABLE comments ADD KEY idx_comments_created_at (created_at)',
    ] as $index => $sql) {
        if (!$indexExists($db, 'comments', $index)) {
            $db->exec($sql);
        }
    }

    if (!$tableExists($db, 'account_comments')) {
        $db->exec(<<<'SQL'
CREATE TABLE account_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL DEFAULT 0,
    content TEXT NOT NULL,
    likes INT NOT NULL DEFAULT 0,
    is_spam TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_acc_comments_account_id (account_id),
    KEY idx_acc_comments_likes (likes),
    KEY idx_acc_comments_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    } else {
        if (!$columnExists($db, 'account_comments', 'account_id')) {
            $db->exec(
                'ALTER TABLE account_comments
                 ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'account_comments', 'content')) {
            $db->exec(
                'ALTER TABLE account_comments
                 ADD COLUMN content TEXT NOT NULL'
            );
        }

        if (!$columnExists($db, 'account_comments', 'likes')) {
            $db->exec(
                'ALTER TABLE account_comments
                 ADD COLUMN likes INT NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'account_comments', 'is_spam')) {
            $db->exec(
                'ALTER TABLE account_comments
                 ADD COLUMN is_spam TINYINT(1) NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'account_comments', 'created_at')) {
            $db->exec(
                'ALTER TABLE account_comments
                 ADD COLUMN created_at TIMESTAMP NOT NULL
                 DEFAULT CURRENT_TIMESTAMP'
            );
        }
    }

    foreach ([
        'idx_acc_comments_account_id' =>
            'ALTER TABLE account_comments ADD KEY idx_acc_comments_account_id (account_id)',
        'idx_acc_comments_likes' =>
            'ALTER TABLE account_comments ADD KEY idx_acc_comments_likes (likes)',
        'idx_acc_comments_created_at' =>
            'ALTER TABLE account_comments ADD KEY idx_acc_comments_created_at (created_at)',
    ] as $index => $sql) {
        if (!$indexExists($db, 'account_comments', $index)) {
            $db->exec($sql);
        }
    }

    if (!$tableExists($db, 'likes')) {
        $db->exec(<<<'SQL'
CREATE TABLE likes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    type TINYINT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL DEFAULT 0,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    is_like TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_like_item_actor (item_id, type, account_id, ip),
    KEY idx_likes_item (item_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    } else {
        if (!$columnExists($db, 'likes', 'item_id')) {
            throw new RuntimeException(
                'Comment integrity migration cannot repair likes.item_id safely.'
            );
        }

        if (!$columnExists($db, 'likes', 'type')) {
            throw new RuntimeException(
                'Comment integrity migration cannot repair likes.type safely.'
            );
        }

        if (!$columnExists($db, 'likes', 'account_id')) {
            $db->exec(
                'ALTER TABLE likes
                 ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT 0'
            );
        }

        if (!$columnExists($db, 'likes', 'ip')) {
            $db->exec(
                "ALTER TABLE likes
                 ADD COLUMN ip VARCHAR(45) NOT NULL DEFAULT ''"
            );
        }

        if (!$columnExists($db, 'likes', 'is_like')) {
            $db->exec(
                'ALTER TABLE likes
                 ADD COLUMN is_like TINYINT(1) NOT NULL DEFAULT 1'
            );
        }

        if (!$columnExists($db, 'likes', 'created_at')) {
            $db->exec(
                'ALTER TABLE likes
                 ADD COLUMN created_at TIMESTAMP NOT NULL
                 DEFAULT CURRENT_TIMESTAMP'
            );
        }

        /*
         * Authenticated likes are identified by account, not by source IP.
         * Normalize their IP before rebuilding the uniqueness guarantee.
         */
        $db->exec(
            "UPDATE likes
             SET ip = ''
             WHERE account_id > 0"
        );

        if ($indexExists($db, 'likes', 'uq_like_item_user')) {
            $db->exec(
                'ALTER TABLE likes DROP INDEX uq_like_item_user'
            );
        }

        if (!$indexExists($db, 'likes', 'uq_like_item_actor')) {
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
        }

        if (!$indexExists($db, 'likes', 'idx_likes_item')) {
            $db->exec(
                'ALTER TABLE likes
                 ADD KEY idx_likes_item (item_id, type)'
            );
        }
    }

    /*
     * Comments must always have a profile row for their author so profile
     * metadata and legacy user sections cannot fall back to a phantom user.
     */
    if (
        $tableExists($db, 'profiles') &&
        $tableExists($db, 'accounts')
    ) {
        $db->exec(<<<'SQL'
INSERT IGNORE INTO profiles
    (account_id, created_at, updated_at)
SELECT DISTINCT source.account_id,
       NOW(),
       NOW()
FROM (
    SELECT account_id
    FROM comments
    WHERE account_id > 0

    UNION

    SELECT account_id
    FROM account_comments
    WHERE account_id > 0
) source
INNER JOIN accounts a
    ON a.account_id = source.account_id
LEFT JOIN profiles p
    ON p.account_id = source.account_id
WHERE p.account_id IS NULL
SQL);
    }
};
