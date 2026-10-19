<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Http\Request;
use MuchoCore\Moderation\Gd2209CaseController;
use MuchoCore\Moderation\Gd2209CaseService;
use MuchoCore\Moderation\ModerationRepository;
use MuchoCore\Moderation\ReportQueueRepository;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('GD 2.209 abuse tests require isolated MariaDB at 127.0.0.1');
}
$connect = static fn(?string $name): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port .
    ($name ? ';dbname=' . $name : '') . ';charset=utf8mb4',
    'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES => false,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$assert = static function (bool $condition, string $reason): void {
    if (!$condition) {
        throw new RuntimeException('GD 2.209 account-case test: ' . $reason);
    }
};
$name = 'mc_cases209_' . bin2hex(random_bytes(5));
$admin = $connect(null);
try {
    $admin->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = $connect($name);
    $db->exec('CREATE TABLE roles (id INT PRIMARY KEY, code VARCHAR(32) NOT NULL)');
    $db->exec("INSERT INTO roles VALUES
        (1,'user'),(2,'moderator'),(3,'elder_moderator'),(4,'owner')");
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        role_id INT NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE levels (
        level_id BIGINT UNSIGNED PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        is_deleted TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 1] as $id => $role) {
        $q = $db->prepare('INSERT INTO accounts
            (account_id,username,role_id,password_hash,gjp2_hash)
            VALUES (:id,:name,:role,:pass,:gjp)');
        $q->execute([
            'id'=>$id, 'name'=>'User'.$id, 'role'=>$role,
            'pass'=>password_hash('pass'.$id, PASSWORD_DEFAULT),
            'gjp'=>password_hash('gjp'.$id, PASSWORD_DEFAULT)
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (:id)')
            ->execute(['id'=>$id]);
    }
    $db->exec('INSERT INTO levels (level_id,account_id) VALUES (50,5)');
    foreach ([
        '20261017_002_gd2209_report_queue.php',
        '20261017_003_gd2209_account_report_cases.php',
        '20261018_001_gd2209_case_actions.php',
    ] as $migration) {
        $queries = require dirname(__DIR__, 2) . '/database/migrations/' . $migration;
        foreach ($queries as $sql) {
            $db->exec($sql);
        }
    }

    $controller = new Gd2209CaseController(new Gd2209CaseService(
        $db, new AccountAuthenticator($db), new ModerationRepository($db),
        new ReportQueueRepository($db)
    ));

    $r = static function(int $id, string $pw, array $data=[]): Request {
        return new Request('POST', '/database/adminGetAccountReview.php', [], array_merge([
            'accountID'=>(string)$id, 'gjp2'=>$pw,
            'gameVersion'=>'22','binaryVersion'=>'51',
            'targetAccountID'=>'5', 'page'=>'0',
        ], $data), []);
    };

    $assert($controller->reportContent($r(1,'pass1',[
        'type'=>'1','special'=>'0','targetID'=>'50','reasons'=>'2,6'
    ]))->body === '1', 'authenticated native type=1 level report must be persisted');
    $assert($controller->reportContent($r(1,'pass1',[
        'type'=>'0','special'=>'0','targetID'=>'50','reasons'=>'2'
    ]))->body === '-1', 'native type=0 means user, not level, must be denied');
    $assert($controller->reportContent($r(1,'pass1',[
        'type'=>'1','special'=>'0','targetID'=>'50','reasons'=>'4'
    ]))->body === '-1', 'cheating reason is user-only, not valid for a level');
    $assert($controller->reportContent($r(1,'pass1',[
        'type'=>'88','special'=>'0','targetID'=>'50','reasons'=>'2'
    ]))->body === '-1', 'unknown native content type denied');
    $assert($controller->reportContent($r(1,'wrong',[
        'type'=>'0','special'=>'0','targetID'=>'50','reasons'=>'2'
    ]))->body === '-1', 'wrong reporter credential denied');
    $assert($controller->reportedContent($r(1,'pass1'))->body === '-1',
        'regular account cannot inspect reports');
    $assert($controller->reportedContent($r(2,'pass2'))->body === '-1',
        'rating advisor cannot inspect reports');
    $assert($controller->reportedContent($r(3,'wrong'))->body === '-1',
        'invalid moderator credential denied');
    $body = $controller->reportedContent($r(3,'pass3'))->body;
    $assert(str_starts_with($body,'1:0:20#'), 'server report inventory');
    $assert($controller->abuseInfo($r(3,'pass3'))->body === '1:5:2:1:3:3:4:1:5:1',
        'exact stored report counters');
    $assert(str_contains($controller->accountReview($r(3,'pass3'))->body, 'User5'),
        'profile review returns target public username');

    // Cannot modify a higher-ranked or same-ranked account.
    $assert($controller->closeCase($r(3,'pass3',[
        'targetAccountID'=>'4','outcome'=>'1'
    ]))->body === '-1', 'mod cannot close owner case');
    $assert($controller->closeCase($r(3,'pass3',[
        'outcome'=>'9'
    ]))->body === '-1', 'invalid case outcome denied');
    $assert($controller->closeCase($r(3,'pass3',[
        'outcome'=>'1'
    ]))->body === '1', 'moderator closes actual pending case');
    $assert($controller->closeCase($r(3,'pass3',[
        'outcome'=>'1'
    ]))->body === '-1', 'cannot fake success when no case remains');
    $assert((int)$db->query("SELECT COUNT(*) FROM mucho_content_reports
        WHERE status='closed'")->fetchColumn() === 1,
        'case closure persists report status');
    $assert((int)$db->query('SELECT COUNT(*) FROM mucho_gd2209_case_actions')
        ->fetchColumn() === 1, 'moderation action audit persisted');
    $assert(str_starts_with($controller->userHistory($r(3,'pass3'))->body,'1:0:20#'),
        'history reads persisted case-action events');
    echo "MUCHOCORE_GD2209_CASE_ACTIONS_OK\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS ' . $name);
}
