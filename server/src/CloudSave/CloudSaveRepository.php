<?php

declare(strict_types=1);

namespace MuchoCore\CloudSave;

use MuchoCore\Core\Environment;
use PDO;
use RuntimeException;
use Throwable;

/*
 * MuchoCore Secure Cloud Save Repository
 * Copyright (C) 2026 IZK
 */
final readonly class CloudSaveRepository
{
    private const KEY_FILE =
        '/var/lib/muchocore/cloudsave.key';

    private const HISTORY_LIMIT = 5;

    public function __construct(
        private PDO $db
    ) {}


    public function save(
        int $accountId,
        string $saveData
    ): int {

        $hash=hash('sha256',$saveData);
        $size=strlen($saveData);

        $encrypted=$this->encrypt($saveData);

        $this->db->beginTransaction();

        try {

            $q=$this->db->prepare("
                SELECT
                    account_id,
                    ciphertext,
                    nonce,
                    auth_tag,
                    sha256,
                    size_bytes,
                    revision,
                    backed_up_at

                FROM mucho_cloud_saves

                WHERE account_id=:account

                FOR UPDATE
            ");

            $q->execute([
                'account'=>$accountId
            ]);

            $old=$q->fetch(PDO::FETCH_ASSOC);


            /*
             * Identical cloud save:
             * don't create useless history revision.
             */
            if(
                $old &&
                hash_equals(
                    (string)$old['sha256'],
                    $hash
                )
            ){

                $touch=$this->db->prepare("
                    UPDATE mucho_cloud_saves

                    SET backed_up_at=CURRENT_TIMESTAMP

                    WHERE account_id=:account
                ");

                $touch->execute([
                    'account'=>$accountId
                ]);

                $revision=(int)$old['revision'];

                $this->db->commit();

                return $revision;
            }


            $revision=1;


            if($old){

                $revision=
                    (int)$old['revision']+1;


                $archive=$this->db->prepare("
                    INSERT IGNORE INTO
                    mucho_cloud_save_revisions
                    (
                        account_id,
                        revision,
                        ciphertext,
                        nonce,
                        auth_tag,
                        sha256,
                        size_bytes,
                        original_backed_up_at
                    )
                    VALUES
                    (
                        :account,
                        :revision,
                        :ciphertext,
                        :nonce,
                        :tag,
                        :sha256,
                        :size,
                        :backed_up
                    )
                ");


                $archive->execute([
                    'account'=>$accountId,
                    'revision'=>(int)$old['revision'],
                    'ciphertext'=>$old['ciphertext'],
                    'nonce'=>$old['nonce'],
                    'tag'=>$old['auth_tag'],
                    'sha256'=>$old['sha256'],
                    'size'=>(int)$old['size_bytes'],
                    'backed_up'=>$old['backed_up_at'],
                ]);
            }


            $upsert=$this->db->prepare("
                INSERT INTO mucho_cloud_saves
                (
                    account_id,
                    ciphertext,
                    nonce,
                    auth_tag,
                    sha256,
                    size_bytes,
                    revision,
                    backed_up_at
                )
                VALUES
                (
                    :account,
                    :ciphertext,
                    :nonce,
                    :tag,
                    :sha256,
                    :size,
                    :revision,
                    CURRENT_TIMESTAMP
                )

                ON DUPLICATE KEY UPDATE

                    ciphertext=VALUES(ciphertext),
                    nonce=VALUES(nonce),
                    auth_tag=VALUES(auth_tag),
                    sha256=VALUES(sha256),
                    size_bytes=VALUES(size_bytes),
                    revision=VALUES(revision),
                    backed_up_at=CURRENT_TIMESTAMP
            ");


            $upsert->bindValue(
                ':account',
                $accountId,
                PDO::PARAM_INT
            );

            $upsert->bindValue(
                ':ciphertext',
                $encrypted['ciphertext'],
                PDO::PARAM_LOB
            );

            $upsert->bindValue(
                ':nonce',
                $encrypted['nonce'],
                PDO::PARAM_LOB
            );

            $upsert->bindValue(
                ':tag',
                $encrypted['tag'],
                PDO::PARAM_LOB
            );

            $upsert->bindValue(
                ':sha256',
                $hash
            );

            $upsert->bindValue(
                ':size',
                $size,
                PDO::PARAM_INT
            );

            $upsert->bindValue(
                ':revision',
                $revision,
                PDO::PARAM_INT
            );

            $upsert->execute();


            /*
             * Keep last five previous revisions.
             */
            $history=$this->db->prepare("
                SELECT history_id

                FROM mucho_cloud_save_revisions

                WHERE account_id=:account

                ORDER BY revision DESC

                LIMIT 100
            ");

            $history->execute([
                'account'=>$accountId
            ]);

            $ids=array_map(
                'intval',
                $history->fetchAll(PDO::FETCH_COLUMN)
            );


            if(count($ids)>self::HISTORY_LIMIT){

                foreach(
                    array_slice(
                        $ids,
                        self::HISTORY_LIMIT
                    )
                    as $historyId
                ){

                    $delete=$this->db->prepare("
                        DELETE FROM
                        mucho_cloud_save_revisions

                        WHERE history_id=:id
                          AND account_id=:account
                    ");

                    $delete->execute([
                        'id'=>$historyId,
                        'account'=>$accountId,
                    ]);
                }
            }


            $this->db->commit();

            return $revision;

        } catch(Throwable $e) {

            if($this->db->inTransaction()){
                $this->db->rollBack();
            }

            throw $e;
        }
    }


    public function load(
        int $accountId
    ): ?string {

        $q=$this->db->prepare("
            SELECT
                ciphertext,
                nonce,
                auth_tag,
                sha256,
                size_bytes

            FROM mucho_cloud_saves

            WHERE account_id=:account

            LIMIT 1
        ");

        $q->execute([
            'account'=>$accountId
        ]);

        $row=$q->fetch(PDO::FETCH_ASSOC);

        if(!$row){
            return null;
        }


        $plain=$this->decrypt(
            (string)$row['ciphertext'],
            (string)$row['nonce'],
            (string)$row['auth_tag']
        );


        if(
            strlen($plain)!==(int)$row['size_bytes']
        ){
            throw new RuntimeException(
                'Cloud save size integrity failure.'
            );
        }


        if(
            !hash_equals(
                (string)$row['sha256'],
                hash('sha256',$plain)
            )
        ){
            throw new RuntimeException(
                'Cloud save checksum failure.'
            );
        }


        return $plain;
    }


    private function encrypt(
        string $plain
    ): array {

        $key=$this->key();

        $nonce=random_bytes(12);
        $tag='';


        $ciphertext=openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );


        if(
            $ciphertext===false ||
            strlen($tag)!==16
        ){
            throw new RuntimeException(
                'Cloud save encryption failed.'
            );
        }


        return [
            'ciphertext'=>$ciphertext,
            'nonce'=>$nonce,
            'tag'=>$tag,
        ];
    }


    private function decrypt(
        string $ciphertext,
        string $nonce,
        string $tag
    ): string {

        if(
            strlen($nonce)!==12 ||
            strlen($tag)!==16
        ){
            throw new RuntimeException(
                'Invalid cloud save cryptographic metadata.'
            );
        }


        $plain=openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );


        if($plain===false){
            throw new RuntimeException(
                'Cloud save decryption failed.'
            );
        }


        return $plain;
    }


    /**
     * VPS deployments keep the key in a protected Docker volume. The
     * no-Docker shared-hosting installer instead creates config/cloudsave.key.
     * Never generate a new key here: rotating it would make old saves unreadable.
     */
    private function keyPath(): string
    {
        $configured = trim((string)(
            Environment::get('MUCHO_CLOUDSAVE_KEY_FILE', '') ?? ''
        ));

        if ($configured !== '') {
            if (
                !str_starts_with($configured, '/') ||
                preg_match('/[\x00-\x1F\x7F]/', $configured) === 1
            ) {
                throw new RuntimeException(
                    'Cloud save key path must be an absolute private filesystem path.'
                );
            }

            return $configured;
        }

        if (Environment::get('MUCHO_SHARED_HOSTING', '0') === '1') {
            return dirname(__DIR__, 2) . '/config/cloudsave.key';
        }

        return self::KEY_FILE;
    }

    private function key(): string
    {
        $raw = @file_get_contents($this->keyPath());

        if($raw===false){
            throw new RuntimeException(
                'Cloud save key unavailable.'
            );
        }


        $key=base64_decode(
            trim($raw),
            true
        );


        if(
            $key===false ||
            strlen($key)!==32
        ){
            throw new RuntimeException(
                'Invalid cloud save key.'
            );
        }


        return $key;
    }
}
