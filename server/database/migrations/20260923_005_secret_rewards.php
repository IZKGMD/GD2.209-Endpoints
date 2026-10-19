<?php

declare(strict_types=1);

return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_secret_rewards (
    reward_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reward_key VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    chest_type TINYINT UNSIGNED NOT NULL DEFAULT 1,
    rewards VARCHAR(512) NOT NULL,
    uses INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mucho_secret_reward_key (reward_key),
    KEY idx_mucho_secret_reward_active (active, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    <<<'SQL'
CREATE TABLE IF NOT EXISTS mucho_secret_reward_claims (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reward_id INT UNSIGNED NOT NULL,
    claim_key VARCHAR(128) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mucho_secret_reward_claim (reward_id, claim_key),
    KEY idx_mucho_secret_reward_claim_key (claim_key),
    CONSTRAINT fk_mucho_secret_reward_claim_reward
        FOREIGN KEY (reward_id)
        REFERENCES mucho_secret_rewards(reward_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
