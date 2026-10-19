<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Level\LevelTelemetryService;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('GD 2.209 level test requires isolated local CI MariaDB.');
}
$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port . ($db ? ';dbname=' . $db : '') . ';charset=utf8mb4',
    'root', $password,
    [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]
);
$check = static function(bool $ok,string $why): void {
    if (!$ok) {
        throw new RuntimeException('2.209 level telemetry: ' . $why);
    }
};
$name = 'mc_level209_' . bin2hex(random_bytes(5));
$server = $connect(null);
try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = $connect($name);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE levels (
        level_id BIGINT UNSIGNED PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        level_version INT UNSIGNED NOT NULL DEFAULT 1,
        is_deleted TINYINT NOT NULL DEFAULT 0,
        is_unlisted TINYINT NOT NULL DEFAULT 0,
        FOREIGN KEY(account_id) REFERENCES accounts(account_id)
    ) ENGINE=InnoDB');
    foreach (require dirname(__DIR__, 2) . '/database/migrations/20261016_006_gd2209_level_state.php' as $sql) {
        $db->exec($sql);
    }
    foreach ([1,2] as $id) {
        $stmt = $db->prepare(
            'INSERT INTO accounts(account_id,username,password_hash,gjp2_hash)
             VALUES (:id,:user,:pass,:gjp)'
        );
        $stmt->execute([
            'id'=>$id,'user'=>'User'.$id,
            'pass'=>password_hash('pass-'.$id,PASSWORD_DEFAULT),
            'gjp'=>password_hash('gjp-'.$id,PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (:id)')
            ->execute(['id'=>$id]);
    }
    $db->exec('INSERT INTO levels(level_id,account_id,level_version) VALUES (100,1,1),(200,2,3)');
    $service = new LevelTelemetryService($db, new AccountAuthenticator($db));

    $check(LevelTelemetryService::parsePosition('5.25') === 5.25,'decimal coordinate');
    $check(LevelTelemetryService::parsePosition('-3.1') === -3.1,'negative coordinate');
    $check(LevelTelemetryService::parsePosition('1000001') === null,'large coordinate rejected');
    $check(LevelTelemetryService::parsePosition('NaN') === null,'NaN rejected');
    $check(LevelTelemetryService::parsePosition('1e5') === null,'scientific notation rejected');
    $check(LevelTelemetryService::parsePosition('1;DROP') === null,'SQL payload rejected');

    $check($service->uploadDeath(2,'pass-2',100,66,'200.1','-33.3'),
        'authenticated player uploads position');
    $check($service->uploadDeath(2,'pass-2',100,66,'200.1','-33.3'),
        'repeated position aggregated');
    $check($service->uploadDeath(1,'pass-1',100,66,'200.1','-33.3'),
        'multiple players contribute data');
    $deaths = $service->deaths(100);
    $check(count($deaths) === 1 && $deaths[0]['count'] === 3,
        'level death aggregation counts real samples');
    $check($deaths[0]['percent'] === 6600 && $deaths[0]['x'] === 2001,
        'percent basis points and coordinates normalized');
    $check(!$service->uploadDeath(2,'pass-2',100,101,'1','1'),
        'invalid percent rejected');
    $check(!$service->uploadDeath(2,'pass-2',100,50,'1000001','0'),
        'overflow position rejected');
    $check(!$service->uploadDeath(2,'pass-2',999,50,'0','0'),
        'unknown level rejected');
    $check(!$service->uploadDeath(2,'pass-2',100,-1,'0','0'),
        'negative percent rejected');

    $check(!$service->uploadControls(2,'pass-2',100,'owner-only'),
        'cannot overwrite someone else level controls');
    $check($service->uploadControls(1,'pass-1',100,'settings:a;b'),
        'owner can upload controls');
    $check($service->controls(100) === 'settings:a;b',
        'download stored controls');
    $check($service->uploadControls(1,'pass-1',100,'updated'),
        'owner updates controls');
    $check($service->controls(100) === 'updated','control update persisted');
    $check(!$service->uploadControls(1,'pass-1',100,str_repeat('a',16385)),
        'oversized control data refused');
    $check(!$service->deleteControls(2,'pass-2',100),
        'other account cannot delete controls');
    $check($service->deleteControls(1,'pass-1',100),
        'owner can delete control data');
    $check($service->controls(100) === null,'delete removed contents');
    $check(!$service->deleteControls(1,'pass-1',100),'duplicate delete refused');

    $db->exec('UPDATE levels SET level_version=2 WHERE level_id=100');
    $check($service->deaths(100) === [],
        'new level version hides previous version heatmap');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_level_deaths WHERE level_id=100')->fetchColumn() === 2,
        'old version samples preserved for historical analysis'
    );
    $check($service->uploadDeath(2,'pass-2',100,5,'10','10'),
        'new revision gets new samples');
    $check(count($service->deaths(100)) === 1,'new revision shows its own samples');

    foreach ([
        static fn() => $service->uploadDeath(1,'pass-2',100,3,'1','1'),
        static fn() => $service->uploadControls(1,'pass-2',100,'bad'),
        static fn() => $service->deleteControls(1,'pass-2',100),
    ] as $attack) {
        try {
            $attack();
            throw new RuntimeException('invalid credentials accepted');
        } catch (RuntimeException $e) {
            $check($e->getMessage() === 'Unauthorized.','account credentials required');
        }
    }

    $db->exec('UPDATE levels SET is_unlisted=1 WHERE level_id=100');
    $check($service->deaths(100) === [],'unlisted level heatmap not public');
    $db->exec('UPDATE levels SET is_unlisted=0,is_deleted=1 WHERE level_id=100');
    $check($service->controls(100) === null,'deleted level controls not readable');
    $check(!$service->uploadDeath(2,'pass-2',100,1,'1','1'),
        'deleted level rejects new telemetry');
    $db->exec('DELETE FROM levels WHERE level_id=100');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_level_deaths')->fetchColumn() === 0,
        'level deletion cascades to death data'
    );
    echo "MUCHOCORE_GD_2209_LEVEL_INTEGRATION_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
