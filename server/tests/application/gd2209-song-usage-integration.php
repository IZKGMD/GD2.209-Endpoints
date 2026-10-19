<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Music\SongUsageController;
use MuchoCore\Music\SongUsageService;
use MuchoCore\Http\Request;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Song usage test requires isolated MariaDB.');
}
$connect = static fn(?string $name): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port . ($name ? ';dbname=' . $name : '') . ';charset=utf8mb4',
    'root', $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$dbName = 'mc_song209_' . bin2hex(random_bytes(5));
$server = $connect(null);
$check = static function(bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('GD 2.209 song usage: ' . $label);
    }
};
try {
    $server->exec('CREATE DATABASE ' . $dbName . ' CHARACTER SET utf8mb4');
    $db = $connect($dbName);
    $db->exec('CREATE TABLE levels (
        level_id INT PRIMARY KEY,
        song_id INT NOT NULL,
        is_deleted TINYINT NOT NULL DEFAULT 0,
        is_unlisted TINYINT NOT NULL DEFAULT 0,
        stars INT NOT NULL DEFAULT 0,
        downloads INT NOT NULL DEFAULT 0,
        featured INT NOT NULL DEFAULT 0,
        epic INT NOT NULL DEFAULT 0,
        demon INT NOT NULL DEFAULT 0,
        auto_level INT NOT NULL DEFAULT 0,
        difficulty INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('INSERT INTO levels
        (level_id,song_id,stars,downloads,featured,epic,demon,auto_level,difficulty,is_deleted,is_unlisted)
        VALUES
        (101,11,0,100,0,0,0,0,10,0,0),
        (102,11,3,50,0,0,0,0,20,0,0),
        (103,11,6,100,1,0,0,0,40,0,0),
        (104,11,10,200,1,1,1,0,50,0,0),
        (105,11,9,300,1,2,0,0,50,0,0),
        (106,11,2,400,1,3,0,0,10,0,0),
        (107,11,1,500,0,0,0,1,50,0,0),
        (108,11,2,800,0,0,0,0,20,1,0),
        (109,11,2,900,0,0,0,0,20,0,1),
        (110,12,2,1000,0,0,0,0,20,0,0)');

    $service = new SongUsageService($db);
    $expected = '7,6,1550#0:0:1|0:2:1|1:4:1|2:6:1|3:5:1|4:1:1';
    $check($service->usage(11) === $expected,
        'exact 2.209 comma/hash/pipe/colon wire, tier mapping and privacy');
    $check($service->usage(12345) === '0,0,0#', 'zero-usage semantics');
    $check($service->usage(0) === '-1', 'nonpositive song rejected');

    $controller = new SongUsageController($service);
    $response = $controller->get(new Request('POST','/database/getGJSongUsage.php',[],[
        'songID' => '11', 'binaryVersion' => '51', 'gameVersion' => '22',
    ], []));
    $check($response->body === $expected && $response->status === 200,
        'normal 2.209 controller request');
    $check($controller->get(new Request('POST','/database/getGJSongUsage.php',[],[
        'songID' => '0',
    ], []))->body === '-1', 'invalid request denied');
    echo "MUCHOCORE_GD_2209_SONG_USAGE_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $dbName);
}
