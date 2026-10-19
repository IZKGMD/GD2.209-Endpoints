<?php
declare(strict_types=1);

/*
 * Retire the legacy administrator Access Key credential.
 *
 * Admin authentication is provided by password/TOTP and native
 * WebAuthn/FIDO2 passkeys. Remove the old columns from existing installs
 * so the retired credential cannot remain usable through legacy state.
 */
return static function(PDO $db): void {
    $columns=$db->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
    $drop=[];

    foreach($columns as $column){
        $name=(string)($column['Field'] ?? '');

        if($name==='access_key_hash' || $name==='access_key_created_at'){
            $drop[]='DROP COLUMN `'.$name.'`';
        }
    }

    if($drop){
        $db->exec('ALTER TABLE admin_users '.implode(', ',$drop));
    }
};
