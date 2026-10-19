<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Music\EditorSoundService;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('GD 2.209 audio tests require isolated local CI MariaDB.');
}
$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host='.$host.';port='.$port.($db ? ';dbname='.$db : '').';charset=utf8mb4',
    'root', $password,
    [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]
);
$check = static function(bool $ok, string $why): void {
    if (!$ok) {
        throw new RuntimeException('2.209 editor sounds: '.$why);
    }
};
$dbname = 'mc_audio209_' . bin2hex(random_bytes(5));
$server = $connect(null);
try {
    $server->exec('CREATE DATABASE '.$dbname.' CHARACTER SET utf8mb4');
    $db = $connect($dbname);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB');
    foreach (require dirname(__DIR__, 2) . '/database/migrations/20261016_007_gd2209_editor_sounds.php' as $sql) {
        $db->exec($sql);
    }
    foreach ([1,2] as $id) {
        $db->prepare(
            'INSERT INTO accounts(account_id,username,password_hash,gjp2_hash)
             VALUES (:id,:user,:pass,:gjp)'
        )->execute([
            'id'=>$id,'user'=>'AudioUser'.$id,
            'pass'=>password_hash('pass-'.$id,PASSWORD_DEFAULT),
            'gjp'=>password_hash('gjp-'.$id,PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (:id)')
            ->execute(['id'=>$id]);
    }

    $service = new EditorSoundService($db, new AccountAuthenticator($db));
    $wav = 'RIFF'.pack('V',36).'WAVEfmt '.pack('V',16).
        pack('v',1).pack('v',1).pack('V',8000).pack('V',8000).
        pack('v',1).pack('v',8).'data'.pack('V',0);
    $encoded = base64_encode($wav);
    $check(EditorSoundService::mime($wav) === 'audio/wav','detect WAV data');
    $check(EditorSoundService::mime('MZ'.str_repeat('0',30)) === null,
        'reject executable');
    $check(EditorSoundService::decodeAudio('!!!') === null,'reject corrupt base64');
    $check(EditorSoundService::decodeAudio(base64_encode('<?php echo 1;')) === null,
        'reject non-audio media');
    $check(EditorSoundService::validText("bad\0title",120) === null,
        'reject NUL in upload metadata');
    $id = $service->upload(1,'pass-1','SFX One','A test sound',$encoded);
    $check($id > 0,'valid creator upload');
    $check($service->upload(1,'pass-1','Second name','Duplicate file',$encoded) === $id,
        'hash-identical reuploads idempotently reuse file');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_editor_sounds')->fetchColumn() === 1,
        'duplicate upload does not consume more quota'
    );
    $check(count($service->search(0)) === 1,'audio catalog exposes metadata');
    $check(count($service->search(0,1)) === 1,'creator filter');
    $check($service->search(0,2) === [], 'other account has no uploads');
    $check($service->download($id) === $wav,'audio download matches original bytes');
    $check($service->download(99999) === null,'missing sound not downloaded');
    $check(
        $service->upload(1,'pass-1','Wrong','',base64_encode('MZ'.str_repeat('A',50))) === 0,
        'executable not accepted as editor audio'
    );
    $check(
        $service->upload(1,'pass-1','Too large','',
            base64_encode('RIFF'.str_repeat('A',2097153))) === 0,
        'oversized uploads rejected'
    );
    $check(!$service->updateDescription(2,'pass-2',$id,'Hijacked'),
        'another user cannot change description');
    $check(!$service->delete(2,'pass-2',$id),
        'another user cannot delete sound');
    $check($service->updateDescription(1,'pass-1',$id,'Updated description'),
        'owner can update description');
    $check($service->search(0)[0]['description'] === 'Updated description',
        'description mutation persists');
    foreach ([
        static fn() => $service->upload(1,'pass-2','Bad','',$encoded),
        static fn() => $service->delete(1,'pass-2',$id),
        static fn() => $service->updateDescription(1,'pass-2',$id,'Bad'),
    ] as $attempt) {
        try {
            $attempt();
            throw new RuntimeException('invalid credential accepted');
        } catch (RuntimeException $e) {
            $check($e->getMessage() === 'Unauthorized.',
                'owner authorization requires real credential');
        }
    }

    $db->exec('UPDATE accounts SET is_banned=1 WHERE account_id=1');
    $check($service->download($id) === null,
        'banned author media not publicly available');
    $check($service->search(0) === [],
        'banned author entries not listed');
    $db->exec('UPDATE accounts SET is_banned=0 WHERE account_id=1');

    $check($service->delete(1,'pass-1',$id),'owner deletes uploaded sound');
    $check($service->download($id) === null,'deleted audio inaccessible');
    $check(!$service->delete(1,'pass-1',$id),'double delete refused');
    $id2 = $service->upload(1,'pass-1','Another sound','',$encoded);
    $check($id2 > 0,'new upload after delete succeeds');
    $db->exec('DELETE FROM accounts WHERE account_id=1');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_editor_sounds')->fetchColumn() === 0,
        'deleting account cascades editor sounds'
    );
    echo "MUCHOCORE_GD_2209_AUDIO_INTEGRATION_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS '.$dbname);
}
