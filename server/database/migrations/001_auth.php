<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE roles (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(64) NOT NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
INSERT INTO roles (code, name, priority) VALUES
('user', 'User', 0),
('moderator', 'Moderator', 50),
('elder_moderator', 'Elder Moderator', 75),
('owner', 'Owner', 100)
SQL,

    <<<'SQL'
CREATE TABLE accounts (
    account_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(20) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    role_id SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_banned TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (account_id),

    UNIQUE KEY uq_accounts_username (username),
    UNIQUE KEY uq_accounts_email (email),

    KEY idx_accounts_role (role_id),

    CONSTRAINT fk_accounts_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
