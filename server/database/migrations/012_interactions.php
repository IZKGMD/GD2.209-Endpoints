<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    likes INT NOT NULL DEFAULT 0,
    is_spam TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_comments_level_id (level_id),
    KEY idx_comments_account_id (account_id),
    KEY idx_comments_likes (likes),
    KEY idx_comments_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS account_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    likes INT NOT NULL DEFAULT 0,
    is_spam TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_acc_comments_account_id (account_id),
    KEY idx_acc_comments_likes (likes),
    KEY idx_acc_comments_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS likes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id INT UNSIGNED NOT NULL,
    type TINYINT UNSIGNED NOT NULL COMMENT '1=level, 2=comment, 3=acc_comment',
    account_id INT UNSIGNED NOT NULL DEFAULT 0,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    is_like TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_like_item_user (item_id, type, account_id),
    KEY idx_likes_item (item_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS level_ratings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_level_rating_user (level_id, account_id),
    KEY idx_level_ratings_level (level_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
