<?php
declare(strict_types=1);

/*
 * Native administrator passkeys (WebAuthn / FIDO2).
 *
 * Private keys never reach the server. MuchoCore stores only the credential
 * identifier, public key, opaque user handle and verification metadata.
 */
return static function(PDO $db): void {
    $columns=$db->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
    $hasHandle=false;
    foreach($columns as $column){
        if((string)($column['Field'] ?? '')==='passkey_user_handle'){
            $hasHandle=true;
            break;
        }
    }

    if(!$hasHandle){
        $db->exec(
            "ALTER TABLE admin_users
             ADD COLUMN passkey_user_handle VARBINARY(64) NULL
             AFTER access_key_created_at"
        );
    }

    $db->exec(
        "CREATE TABLE IF NOT EXISTS admin_passkeys (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_user_id BIGINT UNSIGNED NOT NULL,
            credential_id VARCHAR(1400) NOT NULL,
            user_handle VARBINARY(64) NOT NULL,
            credential_public_key TEXT NOT NULL,
            signature_counter BIGINT UNSIGNED NOT NULL DEFAULT 0,
            aaguid VARCHAR(64) NULL,
            label VARCHAR(120) NOT NULL DEFAULT 'Admin Passkey',
            is_backup_eligible TINYINT(1) NULL,
            is_backed_up TINYINT(1) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL,
            UNIQUE KEY uq_admin_passkey_credential (credential_id),
            KEY idx_admin_passkeys_user (admin_user_id,is_active),
            CONSTRAINT fk_admin_passkeys_admin
                FOREIGN KEY (admin_user_id) REFERENCES admin_users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
};
