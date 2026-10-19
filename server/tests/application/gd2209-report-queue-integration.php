<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Moderation\ModerationRepository;
use MuchoCore\Moderation\ReportQueueController;
use MuchoCore\Moderation\ReportQueueRepository;
use MuchoCore\Moderation\ReportQueueService;
use MuchoCore\Http\Request;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Refusing moderation queue tests outside isolated CI MariaDB');
}
$connect = static fn(?string $name): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port . ($name ? ';dbname=' . $name : '') . ';charset=utf8mb4',
    'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$dbName = 'mc_reports209_' . bin2hex(random_bytes(5));
$admin = $connect(null);
$assert = static function (bool $condition, string $msg): void {
    if (!$condition) {
        throw new RuntimeException('GD 2.209 report queue test: ' . $msg);
    }
};

try {
    $admin->exec('CREATE DATABASE ' . $dbName . ' CHARACTER SET utf8mb4');
    $db = $connect($dbName);
    $db->exec("CREATE TABLE roles (id INT PRIMARY KEY, code VARCHAR(32) NOT NULL)");
    $db->exec("INSERT INTO roles VALUES
        (1,'user'),(2,'moderator'),(3,'elder_moderator'),(4,'owner')");
    $db->exec("CREATE TABLE accounts (
        account_id BIGINT UNSIGNED PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        role_id INT NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE profiles (
        user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE
    ) ENGINE=InnoDB");
    $db->exec("CREATE TABLE levels (
        level_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL,
        is_deleted TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $db->exec('INSERT INTO levels(level_id,account_id) VALUES (91,5), (92,5)');

    foreach ([1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 3] as $id => $role) {
        $stmt = $db->prepare('INSERT INTO accounts
            (account_id,username,role_id,password_hash,gjp2_hash)
            VALUES (:id,:name,:role,:password,:gjp)');
        $stmt->execute([
            'id' => $id, 'name' => 'U' . $id,
            'role' => $role,
            'password' => password_hash('pass-' . $id, PASSWORD_DEFAULT),
            'gjp' => password_hash('gjp-' . $id, PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (:id)')
            ->execute(['id' => $id]);
    }
    $migration = require dirname(__DIR__, 2)
        . '/database/migrations/20261017_002_gd2209_report_queue.php';
    foreach ($migration as $sql) {
        $db->exec($sql);
    }
    // Existing 2.209 reports are safely backfilled by the migration.
    $db->exec("INSERT INTO mucho_content_reports
        (reporter_account_id,content_type,content_id,reason,details)
        VALUES (3,0,91,3,'pre-migration report')");
    $accountMigration = require dirname(__DIR__, 2)
        . '/database/migrations/20261017_003_gd2209_account_report_cases.php';
    foreach ($accountMigration as $sql) {
        $db->exec($sql);
    }
    $assert((int)$db->query('SELECT target_account_id
        FROM mucho_content_reports WHERE reporter_account_id=3')->fetchColumn() === 5,
        'pre-migration level report must resolve target account');
    $db->exec("UPDATE mucho_content_reports SET status='closed' WHERE reporter_account_id=3");
    $auth = new AccountAuthenticator($db);
    $repo = new ReportQueueRepository($db);
    $service = new ReportQueueService($auth, new ModerationRepository($db), $repo);
    $ctrl = new ReportQueueController($service);

    $req = static fn(int $id, string $pass, string $sort='0'): Request =>
        new Request('POST', '/database/adminGetReportQueue.php', [], [
            'accountID' => (string)$id,
            'gjp2' => $pass,
            'gameVersion' => '22',
            'binaryVersion' => '51',
            'mode' => '0',
            'flags' => '1',
            'sort' => $sort,
            'page' => '0',
        ], []);

    // All roles are verified server-side, never from player-supplied metadata.
    $assert($ctrl->queue($req(1, 'pass-1'))->body === '-1',
        'normal player cannot access report queue');
    $assert($ctrl->queue($req(2, 'pass-2'))->body === '-1',
        'rating advisor cannot access administrative report queue');
    $assert($ctrl->queue($req(3, 'wrong'))->body === '-1',
        'wrong credential cannot impersonate moderator');
    $assert($ctrl->queue($req(3, 'pass-3'))->body === '0:0:20#',
        '2.209 native parser requires total:offset:pageSize# even for empty queue');

    $db->exec("UPDATE mucho_content_reports SET status='pending' WHERE reporter_account_id=3");
    $assert($service->reportLevel(1, 'pass-1', 91, 3, 'spam'),
        'regular player can report existing level');
    $assert(!$service->reportLevel(1, 'pass-1', 999, 3, 'invalid'),
        'nonexistent content cannot be reported');
    $assert($service->reportLevel(1, 'pass-1', 91, 3, 'spam'),
        'duplicate submission is idempotent');
    $assert($service->reportLevel(2, 'pass-2', 91, 3, 'spam'),
        'another account may independently report');
    $assert($service->reportLevel(1, 'pass-1', 92, 5, 'abuse'),
        'same account may report another level');

    $assert((int)$db->query('SELECT COUNT(*) FROM mucho_content_reports')->fetchColumn() === 4,
        'repeated report did not create duplicates');

    $assert(!$service->reportLevel(5, 'pass-5', 91, 2, 'self report'),
        'users cannot inflate their own abuse score');
    $group = $repo->pending(0, 2);
    $assert($group['total'] === 1 && count($group['rows']) === 1,
        'multiple levels by one account create one moderation case');
    $assert((int)$group['rows'][0]['target_account_id'] === 5 &&
        (int)$group['rows'][0]['report_count'] === 4 &&
        (int)$group['rows'][0]['report_points'] === 12,
        'verified 2.209 report score is three points per report');
    $queue = $ctrl->queue($req(3, 'pass-3'))->body;
    $assert(str_starts_with($queue, '1:0:20#5:U5:2:0:0:-1:'),
        'native pagination comes before positional account case entries');
    $records = explode('|', explode('#', $queue, 2)[1]);
    $fields = explode(':', $records[0]);
    $assert(count($fields) === 20 && (int)$fields[0] === 5
        && $fields[1] === 'U5',
        'native parser supports twenty positional case fields');
    $assert((int)$fields[2] === 2 && (int)$fields[8] === 0
        && (int)$fields[12] === 12 && (int)$fields[19] === 3,
        'distinct items, no invented direct reports, abuse score and reporters');
    $assert((int)$fields[13] === 0 && (int)$fields[16] === 0,
        'no fabricated text-filter flags or hits');
    $assert(substr_count($queue, '#') === 1,
        'native queue has exactly one pagination separator');
    $assert(!str_contains($queue,'spam') && !str_contains($queue,'pass-1'),
        'sensitive report text/credentials not serialized');

    $assert($ctrl->queue($req(4,'pass-4'))->body !== '-1',
        'owner can view queue');
    $assert($ctrl->queue($req(3,'pass-3','1'))->body !== '-1',
        'alternate sort accepted');
    $assert($ctrl->queue($req(3,'pass-3','-99'))->body === '-1',
        'invalid sort rejected');

    $db->exec("UPDATE accounts SET is_banned=1 WHERE account_id=3");
    $assert($ctrl->queue($req(3,'pass-3'))->body === '-1',
        'banned moderator cannot inspect queue');
    echo "MUCHOCORE_GD_2209_REPORT_QUEUE_OK\n";
} finally {
    $admin->exec('DROP DATABASE IF EXISTS ' . $dbName);
}
