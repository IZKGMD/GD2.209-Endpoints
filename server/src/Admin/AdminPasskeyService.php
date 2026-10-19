<?php
declare(strict_types=1);

namespace MuchoCore\Admin;

use PDO;
use RuntimeException;
use ReportUri\Passkeys\WebAuthn;

final class AdminPasskeyService
{
    private const SESSION_CHALLENGE_TTL = 300;

    public function __construct(
        private readonly PDO $db,
        private readonly string $rpName,
    ) {
    }

    public function rpId(): string
    {
        $configured=(string)(
            getenv('MUCHO_ADMIN_PASSKEY_RP_ID')
            ?: ($_ENV['MUCHO_ADMIN_PASSKEY_RP_ID'] ?? '')
        );

        if($configured!==''){
            return $this->normalizeRpId($configured);
        }

        foreach(['MUCHO_PUBLIC_URL','MUCHO_ACCOUNT_URL'] as $key){
            $url=(string)(getenv($key) ?: ($_ENV[$key] ?? ''));
            if($url!==''){
                $host=(string)parse_url($url,PHP_URL_HOST);
                if($host!==''){
                    return $this->normalizeRpId($host);
                }
            }
        }

        $domain=(string)(getenv('DOMAIN') ?: ($_ENV['DOMAIN'] ?? ''));
        if($domain!==''){
            return $this->normalizeRpId($domain);
        }

        throw new RuntimeException(
            'Passkey RP ID is not configured. Set MUCHO_ADMIN_PASSKEY_RP_ID.'
        );
    }

    public function loginOptions(): object
    {
        $server=$this->server();
        $args=$server->getGetArgs(
            [],
            60,
            true,
            true,
            true,
            true,
            true,
            true
        );

        $this->storeChallenge(
            'passkey_login',
            $server->getChallenge()->getBinaryString()
        );

        return $args;
    }

    public function registrationOptions(int $adminId,string $username): object
    {
        $handle=$this->getOrCreateUserHandle($adminId);
        $credentials=$this->credentialIdsForAdmin($adminId);

        $server=$this->server();
        $args=$server->getCreateArgs(
            $handle,
            $username,
            $username,
            60,
            'required',
            'required',
            null,
            $credentials
        );

        $this->storeChallenge(
            'passkey_register',
            $server->getChallenge()->getBinaryString(),
            [
                'admin_id'=>$adminId,
                'user_handle'=>self::b64($handle),
            ]
        );

        return $args;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function verifyRegistration(int $adminId,array $payload): array
    {
        $pending=$this->takeChallenge('passkey_register');
        if((int)($pending['admin_id'] ?? 0)!==$adminId){
            throw new RuntimeException('Passkey registration session is invalid.');
        }

        $userHandle=$this->decodeB64(
            (string)($pending['user_handle'] ?? ''),
            'registration user handle'
        );

        $server=$this->server();
        $record=$server->processCreate(
            $this->decodePayload($payload,'clientDataJSON'),
            $this->decodePayload($payload,'attestationObject'),
            $this->decodeB64(
                (string)$pending['challenge'],
                'registration challenge'
            ),
            true,
            true
        );

        $credentialId=(string)$record->credentialId;
        $credentialIdB64=self::b64($credentialId);
        if($credentialId==='' || strlen($credentialId)>1023){
            throw new RuntimeException('Invalid passkey credential ID.');
        }

        $exists=$this->db->prepare(
            'SELECT 1 FROM admin_passkeys
             WHERE credential_id=:credential
             LIMIT 1'
        );
        $exists->execute(['credential'=>$credentialIdB64]);
        if($exists->fetchColumn()!==false){
            throw new RuntimeException('This passkey is already registered.');
        }

        $label=trim((string)($payload['label'] ?? ''));
        if($label===''){
            $label='Admin Passkey';
        }
        $label=$this->limitLabel($label);

        $insert=$this->db->prepare(
            'INSERT INTO admin_passkeys
             (admin_user_id,credential_id,user_handle,credential_public_key,
              signature_counter,aaguid,is_backup_eligible,is_backed_up,label)
             VALUES
             (:admin,:credential,:handle,:public_key,:counter,:aaguid,
              :backup_eligible,:backed_up,:label)'
        );
        $insert->execute([
            'admin'=>$adminId,
            'credential'=>$credentialIdB64,
            'handle'=>$userHandle,
            'public_key'=>(string)$record->credentialPublicKey,
            'counter'=>max(0,(int)($record->signatureCounter ?? 0)),
            'aaguid'=>self::hex((string)($record->AAGUID ?? '')),
            'backup_eligible'=>isset($record->isBackupEligible)
                ? (int)(bool)$record->isBackupEligible
                : null,
            'backed_up'=>isset($record->isBackedUp)
                ? (int)(bool)$record->isBackedUp
                : null,
            'label'=>$label,
        ]);

        return [
            'id'=>(int)$this->db->lastInsertId(),
            'credential_id'=>$credentialIdB64,
            'label'=>$label,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function verifyLogin(array $payload): array
    {
        $pending=$this->takeChallenge('passkey_login');
        $rawId=$this->decodeB64(
            (string)($payload['rawId'] ?? $payload['id'] ?? ''),
            'credential ID'
        );

        if(strlen($rawId)>1023){
            throw new RuntimeException('Invalid passkey credential ID.');
        }

        $credentialId=self::b64($rawId);

        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare(
                'SELECT
                    p.*,
                    a.username,
                    a.role,
                    a.is_active,
                    a.totp_secret
                 FROM admin_passkeys p
                 INNER JOIN admin_users a
                    ON a.id=p.admin_user_id
                 WHERE p.credential_id=:credential
                   AND p.is_active=1
                   AND a.is_active=1
                 LIMIT 1
                 FOR UPDATE'
            );
            $q->execute(['credential'=>$credentialId]);
            $row=$q->fetch(PDO::FETCH_ASSOC) ?: null;

            if($row===null){
                throw new RuntimeException('Unknown passkey.');
            }

            $responseUserHandle=$this->decodeB64(
                (string)($payload['userHandle'] ?? ''),
                'user handle'
            );
            $storedUserHandle=(string)$row['user_handle'];

            if($responseUserHandle==='' || !hash_equals($storedUserHandle,$responseUserHandle)){
                throw new RuntimeException('Passkey user handle mismatch.');
            }

            $server=$this->server();
            $server->processGet(
                $this->decodePayload($payload,'clientDataJSON'),
                $this->decodePayload($payload,'authenticatorData'),
                $this->decodePayload($payload,'signature'),
                (string)$row['credential_public_key'],
                $this->decodeB64((string)$pending['challenge'],'login challenge'),
                (int)$row['signature_counter'],
                true,
                true
            );

            $newCounter=$server->getSignatureCounter();
            if(is_int($newCounter) && $newCounter>0){
                $update=$this->db->prepare(
                    'UPDATE admin_passkeys
                     SET signature_counter=:counter,
                         last_used_at=UTC_TIMESTAMP()
                     WHERE id=:id'
                );
                $update->execute([
                    'counter'=>$newCounter,
                    'id'=>(int)$row['id'],
                ]);
            }else{
                $update=$this->db->prepare(
                    'UPDATE admin_passkeys
                     SET last_used_at=UTC_TIMESTAMP()
                     WHERE id=:id'
                );
                $update->execute(['id'=>(int)$row['id']]);
            }

            $this->db->commit();

            return [
                'admin'=>[
                    'id'=>(int)$row['admin_user_id'],
                    'username'=>(string)$row['username'],
                    'role'=>(string)$row['role'],
                ],
                'requires_totp'=>!empty($row['totp_secret']),
            ];
        }catch(\Throwable $e){
            if($this->db->inTransaction()){
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function listForAdmin(int $adminId): array
    {
        $q=$this->db->prepare(
            'SELECT id,credential_id,label,signature_counter,
                    aaguid,is_backup_eligible,is_backed_up,
                    created_at,last_used_at
             FROM admin_passkeys
             WHERE admin_user_id=:admin
               AND is_active=1
             ORDER BY id DESC'
        );
        $q->execute(['admin'=>$adminId]);

        $rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $credential=(string)$row['credential_id'];
            $rows[]=[
                'id'=>(int)$row['id'],
                'credential_id'=>$this->shortCredential($credential),
                'label'=>(string)$row['label'],
                'signature_counter'=>(int)$row['signature_counter'],
                'aaguid'=>(string)($row['aaguid'] ?? ''),
                'is_backup_eligible'=>$row['is_backup_eligible']===null
                    ? null : (bool)$row['is_backup_eligible'],
                'is_backed_up'=>$row['is_backed_up']===null
                    ? null : (bool)$row['is_backed_up'],
                'created_at'=>(string)$row['created_at'],
                'last_used_at'=>$row['last_used_at']===null
                    ? null : (string)$row['last_used_at'],
            ];
        }

        return $rows;
    }

    public function deleteForAdmin(int $adminId,int $passkeyId): bool
    {
        $q=$this->db->prepare(
            'DELETE FROM admin_passkeys
             WHERE id=:id
               AND admin_user_id=:admin'
        );
        $q->execute([
            'id'=>$passkeyId,
            'admin'=>$adminId,
        ]);

        return $q->rowCount()===1;
    }

    private function server(): WebAuthn
    {
        return new WebAuthn(
            $this->rpName,
            $this->rpId(),
            true
        );
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function storeChallenge(
        string $key,
        string $challenge,
        array $extra=[],
    ): void {
        $_SESSION[$key]=array_merge(
            [
                'challenge'=>self::b64($challenge),
                'created_at'=>time(),
            ],
            $extra
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function takeChallenge(string $key): array
    {
        $pending=$_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        if(!is_array($pending)){
            throw new RuntimeException('Passkey challenge is missing. Start again.');
        }

        $created=(int)($pending['created_at'] ?? 0);
        if($created<=0 || (time()-$created)>self::SESSION_CHALLENGE_TTL){
            throw new RuntimeException('Passkey challenge expired. Start again.');
        }

        if(!isset($pending['challenge']) || !is_string($pending['challenge'])){
            throw new RuntimeException('Passkey challenge is invalid.');
        }

        return $pending;
    }

    private function getOrCreateUserHandle(int $adminId): string
    {
        $q=$this->db->prepare(
            'SELECT passkey_user_handle
             FROM admin_users
             WHERE id=:id AND is_active=1
             LIMIT 1'
        );
        $q->execute(['id'=>$adminId]);
        $existing=$q->fetchColumn();

        if(is_string($existing) && $existing!==''){
            return $existing;
        }

        $candidate=random_bytes(32);

        $update=$this->db->prepare(
            'UPDATE admin_users
             SET passkey_user_handle=:handle
             WHERE id=:id
               AND is_active=1
               AND passkey_user_handle IS NULL'
        );
        $update->execute([
            'handle'=>$candidate,
            'id'=>$adminId,
        ]);

        $q->execute(['id'=>$adminId]);
        $stored=$q->fetchColumn();

        if(!is_string($stored) || $stored===''){
            throw new RuntimeException('Unable to initialize passkey user handle.');
        }

        return $stored;
    }

    /**
     * @return list<string>
     */
    private function credentialIdsForAdmin(int $adminId): array
    {
        $q=$this->db->prepare(
            'SELECT credential_id
             FROM admin_passkeys
             WHERE admin_user_id=:admin
               AND is_active=1
             ORDER BY id'
        );
        $q->execute(['admin'=>$adminId]);

        $ids=[];
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
            try{
                $ids[]=$this->decodeB64((string)$id,'credential ID');
            }catch(\Throwable){
                continue;
            }
        }

        return $ids;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function decodePayload(array $payload,string $field): string
    {
        $value=$payload[$field] ?? null;
        if(!is_string($value) || $value===''){
            throw new RuntimeException($field.' is required.');
        }

        return $this->decodeB64($value,$field);
    }

    private function decodeB64(string $value,string $field): string
    {
        if(
            $value==='' ||
            strlen($value)>4096 ||
            preg_match('/^[A-Za-z0-9_-]+$/D',$value)!==
            1
        ){
            throw new RuntimeException('Invalid '.$field.'.');
        }

        $remainder=strlen($value)%4;
        $padded=$remainder===0 ? $value : $value.str_repeat('=',4-$remainder);
        $decoded=base64_decode(
            strtr($padded,'-_','+/'),
            true
        );

        if($decoded===false){
            throw new RuntimeException('Invalid '.$field.'.');
        }

        return $decoded;
    }

    private static function b64(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value),'+/','-_'),
            '='
        );
    }

    private static function hex(string $value): ?string
    {
        return $value==='' ? null : bin2hex($value);
    }

    private function normalizeRpId(string $value): string
    {
        $value=trim(strtolower($value));
        $value=preg_replace('~^[a-z][a-z0-9+.-]*://~i','',$value) ?? $value;
        $value=explode('/',$value,2)[0];
        $value=explode('?', $value,2)[0];
        $value=explode('#', $value,2)[0];

        if(str_contains($value,':')){
            $value=(string)strtok($value,':');
        }

        $value=trim($value,'.');

        if(
            $value==='' ||
            strlen($value)>253 ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)$/D',$value)
        ){
            throw new RuntimeException('Invalid WebAuthn RP ID.');
        }

        return $value;
    }

    private function limitLabel(string $label): string
    {
        $label=trim(preg_replace('/\s+/u',' ',$label) ?? $label);
        if($label===''){
            return 'Admin Passkey';
        }

        return mb_substr($label,0,120,'UTF-8');
    }

    private function shortCredential(string $credential): string
    {
        if(strlen($credential)<=24){
            return $credential;
        }

        return substr($credential,0,12).'…'.substr($credential,-8);
    }
}
