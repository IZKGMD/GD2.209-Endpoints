<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Moderation\ModerationRepository;
use MuchoCore\Moderation\Gd2209SanctionService;
use MuchoCore\Moderation\Gd2209SanctionController;
use MuchoCore\Moderation\Gd2209Restrictions;
use MuchoCore\Compatibility\Gd2209UtilityService;
use MuchoCore\Compatibility\Gd2209UtilityController;
use MuchoCore\Http\Request;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('2.209 sanction tests refuse non-isolated databases');
}
$port = getenv('TEST_DB_PORT') ?: '3306';
$connect = static fn(?string $name): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port
    . ($name ? ';dbname=' . $name : '') . ';charset=utf8mb4',
    'root',$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES=>false,
     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$assert = static function (bool $condition, string $reason): void {
    if (!$condition) {
        throw new RuntimeException('GD 2.209 sanctions test failed: '.$reason);
    }
};
$dbname = 'mc_sanctions209_'.bin2hex(random_bytes(5));
$admin = $connect(null);
try {
    $admin->exec('CREATE DATABASE '.$dbname.' CHARACTER SET utf8mb4');
    $db = $connect($dbname);
    $db->exec('CREATE TABLE roles(id INT PRIMARY KEY,code VARCHAR(32) NOT NULL)');
    $db->exec("INSERT INTO roles VALUES
        (1,'user'),(2,'moderator'),(3,'elder_moderator'),(4,'owner')");
    $db->exec('CREATE TABLE accounts(
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        role_id INT NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles(
        user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB');
    foreach ([1=>1,2=>2,3=>3,4=>4,5=>1] as $id=>$role) {
        $s = $db->prepare('INSERT INTO accounts(
            account_id,username,role_id,password_hash,gjp2_hash)
            VALUES (:id,:name,:role,:pass,:gjp)');
        $s->execute([
            'id'=>$id,'name'=>'User'.$id,'role'=>$role,
            'pass'=>password_hash('pw'.$id,PASSWORD_DEFAULT),
            'gjp'=>password_hash('gjp'.$id,PASSWORD_DEFAULT)
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES(:id)')
            ->execute(['id'=>$id]);
    }

    foreach (['20261018_001_gd2209_case_actions.php',
              '20261018_002_gd2209_restrictions.php',
              '20261018_003_gd2209_registration_opens.php'] as $file) {
        foreach (require dirname(__DIR__,2).'/database/migrations/'.$file as $sql) {
            $db->exec($sql);
        }
    }

    $db->exec('CREATE TABLE songs(
        id BIGINT UNSIGNED PRIMARY KEY,
        size DECIMAL(10,2) NOT NULL,
        is_verified TINYINT NOT NULL
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE mucho_editor_sounds(
        id BIGINT UNSIGNED PRIMARY KEY,
        size_bytes INT NOT NULL
    ) ENGINE=InnoDB');
    $db->exec('INSERT INTO songs VALUES (7,1.50,1),(8,0,1)');
    $db->exec('INSERT INTO mucho_editor_sounds VALUES (23,1200)');

    $auth = new AccountAuthenticator($db);
    $sanctions = new Gd2209SanctionController(
        new Gd2209SanctionService($db,$auth,new ModerationRepository($db))
    );
    $utilities = new Gd2209UtilityController(
        new Gd2209UtilityService($db,$auth)
    );
    $request = static fn(int $actor,string $pw,array $extra=[]): Request =>
        new Request('POST','/database/adminCommentBan.php',[],
            array_merge(['accountID'=>(string)$actor,'gjp2'=>$pw,
                'targetAccountID'=>'5','durationMinutes'=>'60',
                'gameVersion'=>'22','binaryVersion'=>'51'], $extra),[]);

    $assert($sanctions->apply($request(1,'pw1'),'comment',true)->body === '-1',
        'ordinary user cannot comment ban');
    $assert($sanctions->apply($request(2,'pw2'),'comment',true)->body === '-1',
        'advisor cannot comment ban');
    $assert($sanctions->apply($request(3,'wrong'),'comment',true)->body === '-1',
        'invalid moderator credential denied');
    $assert($sanctions->apply($request(3,'pw3',
        ['targetAccountID'=>'4']),'comment',true)->body === '-1',
        'moderator cannot ban owner');
    $assert($sanctions->apply($request(3,'pw3'),'comment',true)->body === '1',
        'valid mod ban accepted');
    $assert(Gd2209Restrictions::active($db,5,'comment'),
        'comment ban must actually block comment creation');
    $assert($sanctions->apply($request(3,'pw3'),'comment',false)->body === '1',
        'valid mod unban accepted');
    $assert(!Gd2209Restrictions::active($db,5,'comment'),
        'unban releases restriction');
    $assert($sanctions->apply($request(3,'pw3'),'comment',false)->body === '-1',
        'cannot fake unban success when no ban exists');

    $assert($sanctions->apply($request(4,'pw4',
        ['durationMinutes'=>'0']),'freeze',true)->body === '1',
        'owner freezes ordinary account');
    $assert((int)$db->query('SELECT is_active FROM accounts
        WHERE account_id=5')->fetchColumn() === 0,
        'frozen account cannot authenticate');
    $assert($auth->authenticateGjp2(5,'pw5') === null,
        'frozen account credential denied');
    $assert($sanctions->apply($request(4,'pw4',
        ['durationMinutes'=>'0']),'freeze',false)->body === '1',
        'owner unfreezes account');
    $assert((int)$db->query('SELECT is_active FROM accounts
        WHERE account_id=5')->fetchColumn() === 1,
        'account prior-active state restored');
    $assert((int)$db->query('SELECT COUNT(*) FROM mucho_gd2209_case_actions')
        ->fetchColumn() === 4, 'audit retained all four mutations');

    $assert($utilities->audioSize($request(1,'pw1',
        ['songIDs'=>'7','sfxIDs'=>'23']))->body === '7:1572864#23:1200',
        'real DB sizes, no invented values');
    $assert($utilities->audioSize($request(1,'pw1',
        ['songIDs'=>'8','sfxIDs'=>'']))->body === '-1',
        'song with unknown/nonpositive file size denied');
    $assert($utilities->contentStatus($request(5,'pw5',
        ['special'=>'1']))->body === '1:5:2:0:3:0:4:0',
        'content status matches persisted restriction state');
    $open = $request(1,'pw1',['secret'=>'Wmfv3899gc9']);
    $token1 = $utilities->openedRegister($open)->body;
    $token2 = $utilities->openedRegister($open)->body;
    $assert(preg_match('/^[a-f0-9]{32}$/D', $token1) === 1,
        'native openedRegister must issue a 32-char hexadecimal session');
    $assert(preg_match('/^[a-f0-9]{32}$/D', $token2) === 1
        && $token1 !== $token2,
        'repeated register opening issues fresh unpredictable sessions');
    $assert($utilities->openedRegister(
        $request(1,'pw1',['secret'=>'wrong'])
    )->body === '-1', 'invalid account secret denied');
    $assert((int)$db->query('SELECT COUNT(*) FROM mucho_gd2209_registration_opens')
        ->fetchColumn() === 2, 'token hashes stored without plaintext values');
    $lookup = $db->prepare('SELECT 1 FROM mucho_gd2209_registration_opens
        WHERE token_hash=:hash');
    $lookup->execute(['hash'=>hash('sha256', $token1, true)]);
    $assert($lookup->fetchColumn() !== false,
        'server retained hash of issued native session token');
    echo "MUCHOCORE_GD2209_SANCTIONS_UTILITIES_OK\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS '.$dbname);
}
