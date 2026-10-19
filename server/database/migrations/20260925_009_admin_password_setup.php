<?php
declare(strict_types=1);

/*
 * First-login administrator password setup.
 *
 * Newly invited administrators receive a single-use setup link and create
 * their own password. The raw setup token is never stored in the database.
 */
return static function(PDO $db): void {
    $columns=$db->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
    $fields=[];
    foreach($columns as $column){
        $fields[(string)($column['Field'] ?? '')]=true;
    }

    if(!isset($fields['password_must_set'])){
        $db->exec(
            "ALTER TABLE admin_users
             ADD COLUMN password_must_set TINYINT(1) NOT NULL DEFAULT 0
             AFTER password_hash"
        );
    }

    if(!isset($fields['password_setup_token_hash'])){
        $db->exec(
            "ALTER TABLE admin_users
             ADD COLUMN password_setup_token_hash VARCHAR(64) NULL
             AFTER password_must_set"
        );
    }

    if(!isset($fields['password_setup_expires_at'])){
        $db->exec(
            "ALTER TABLE admin_users
             ADD COLUMN password_setup_expires_at TIMESTAMP NULL
             AFTER password_setup_token_hash"
        );
    }

    $indexes=$db->query('SHOW INDEX FROM admin_users')->fetchAll(PDO::FETCH_ASSOC);
    $hasSetupIndex=false;
    foreach($indexes as $index){
        if((string)($index['Key_name'] ?? '')==='uq_admin_password_setup_token'){
            $hasSetupIndex=true;
            break;
        }
    }

    if(!$hasSetupIndex){
        $db->exec(
            "ALTER TABLE admin_users
             ADD UNIQUE KEY uq_admin_password_setup_token (password_setup_token_hash)"
        );
    }
};
