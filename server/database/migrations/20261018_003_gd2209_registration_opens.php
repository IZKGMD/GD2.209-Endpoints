<?php

declare(strict_types=1);

/**
 * Bounded, anonymous registration-opening diagnostic.
 * Hashes and expiry metadata only; not a registration credential store.
 */
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_gd2209_registration_opens (
    token_hash BINARY(32) NOT NULL PRIMARY KEY,
    actor_hash BINARY(32) NOT NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    KEY idx_2209_registration_open_expires (expires_at),
    KEY idx_2209_registration_open_actor (actor_hash, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
