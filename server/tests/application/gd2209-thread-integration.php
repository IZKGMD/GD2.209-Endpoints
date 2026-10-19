<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Account\AccountAuthenticator;
use MuchoCore\Social\ThreadService;
use MuchoCore\Social\RelationshipRepository;
use MuchoCore\Social\Gd2209NativeThreadOperations;
use MuchoCore\Social\Gd2209ThreadActionService;
use MuchoCore\Social\ThreadController;
use MuchoCore\Protocol\GdRelationshipEncoder;
use MuchoCore\Http\Request;

$host = getenv('TEST_DB_HOST');
$password = getenv('TEST_DB_ROOT_PASSWORD');
$port = getenv('TEST_DB_PORT') ?: '3306';
if ($host !== '127.0.0.1' || !is_string($password) || $password === '') {
    throw new RuntimeException('Refusing GD 2.209 thread tests outside isolated CI MariaDB.');
}
$connect = static fn(?string $db): PDO => new PDO(
    'mysql:host=' . $host . ';port=' . $port . ($db ? ';dbname=' . $db : '') . ';charset=utf8mb4',
    'root', $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$check = static function (bool $ok, string $why): void {
    if (!$ok) {
        throw new RuntimeException('2.209 thread test: ' . $why);
    }
};
$name = 'mc_threads_it_' . bin2hex(random_bytes(5));
$server = $connect(null);
try {
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4');
    $db = $connect($name);
    $db->exec('CREATE TABLE accounts (
        account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        gjp2_hash VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_banned TINYINT NOT NULL DEFAULT 0,
        messages_state TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE profiles (
        user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id BIGINT UNSIGNED NOT NULL UNIQUE,
        cube INT NOT NULL DEFAULT 1,
        color1 INT NOT NULL DEFAULT 0,
        color2 INT NOT NULL DEFAULT 3,
        special INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $db->exec('CREATE TABLE blocks (
        account_id BIGINT UNSIGNED NOT NULL,
        blocked_account_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY(account_id, blocked_account_id)
    ) ENGINE=InnoDB');
    $migration = require dirname(__DIR__, 2) . '/database/migrations/20261016_005_gd2209_threads.php';
    foreach ($migration as $sql) {
        $db->exec($sql);
    }
    foreach (require dirname(__DIR__, 2) .
        '/database/migrations/20261018_004_gd2209_native_threads.php' as $sql) {
        $db->exec($sql);
    }
    foreach ([1=>'Owner',2=>'Member',3=>'Stranger'] as $id=>$username) {
        $stmt = $db->prepare(
            'INSERT INTO accounts(account_id,username,password_hash,gjp2_hash)
             VALUES (:id,:username,:password,:gjp)'
        );
        $stmt->execute([
            'id'=>$id, 'username'=>$username,
            'password'=>password_hash('pass-'.$id, PASSWORD_DEFAULT),
            'gjp'=>password_hash('gjp-'.$id, PASSWORD_DEFAULT),
        ]);
        $db->prepare('INSERT INTO profiles(account_id) VALUES (:id)')
            ->execute(['id'=>$id]);
    }

    $service = new ThreadService($db, new AccountAuthenticator($db));
    $check($service->create(1,'pass-1','') === 0, 'empty title must be rejected');
    $thread = $service->create(1,'pass-1','GDPS Team');
    $check($thread > 0, 'thread should be created');
    $check(count($service->threads(1,'pass-1',0)) === 1, 'owner sees thread');
    $check(count($service->threads(2,'pass-2',0)) === 0, 'nonmembers cannot list thread');
    $check(count($service->members(1,'pass-1',$thread)) === 1, 'creator is first member');
    $check(count($service->members(2,'pass-2',$thread)) === 0, 'member list private');

    $http = new ThreadController(
        $service, new GdRelationshipEncoder(),
        new Gd2209NativeThreadOperations(
            $db, new AccountAuthenticator($db), new RelationshipRepository($db)
        ),
        new Gd2209ThreadActionService($db, new AccountAuthenticator($db))
    );
    $req = static fn(int $account,string $password,string $path,array $extra=[]): Request =>
        new Request('POST','/database/'.$path.'.php',[],array_merge([
            'accountID'=>(string)$account, 'gjp2'=>$password,
            'gameVersion'=>'22','binaryVersion'=>'51',
            'threadID'=>(string)$thread,
        ],$extra),[]);
    $check($http->getMembers($req(2,'pass-2','getGJThreadMembers'))->body === '-1',
        'outsider cannot enumerate the native roster');
    $check($http->getMessages($req(2,'pass-2','getGJThreadMessages'))->body === '-1',
        'outsider cannot receive a falsely successful seven-part thread');

    $check(!$service->send(2,'pass-2',$thread,'Hack'), 'nonmember cannot send');
    $check($service->messages(2,'pass-2',$thread,0) === [], 'nonmember cannot read');
    $check(!$service->modify(2,'pass-2',$thread,'rename','Hijacked'), 'non-owner cannot rename');
    $check(!$service->invite(2,'pass-2',$thread,3), 'non-owner cannot invite');
    $check($service->invite(1,'pass-1',$thread,2), 'owner invites member');
    $check($service->invite(1,'pass-1',$thread,2), 'repeat invite is idempotent');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_thread_invites')->fetchColumn() === 1,
        'duplicate invitation is not inserted'
    );
    $invitations = $service->invites(2,'pass-2');
    $check(count($invitations) === 1, 'recipient receives invite');

    $wireInvites = $http->getInvites(
        $req(2,'pass-2','getGJThreadInvites')
    )->body;
    $wireValues = explode(':',$wireInvites);
    $check(count($wireValues) === 19 &&
        $wireValues[0] === (string)$thread &&
        $wireValues[2] === base64_encode('Owner'),
        'native pending invites use 19 positional fields, including actual inviter');

    $memberPreview = $http->getMembers(
        $req(2,'pass-2','getGJThreadMembers')
    )->body;
    $previewSegments = explode('#',$memberPreview);
    $check(count($previewSegments) === 3 &&
        count(explode(':',$previewSegments[2])) === 17,
        'invited player may see roster and complete 17-slot invitation user');

    $check($service->invites(3,'pass-3') === [], 'other users cannot read invitation');
    $invite = (int)$invitations[0]['id'];
    $check(
        !$service->respondInvite(3,'pass-3',$invite,true),
        'third-party invite acceptance denied'
    );
    $check($http->update($req(2,'pass-2','updateGJThread',[
        'mode'=>'8'
    ]))->body === '1', 'native mode 8 accepts invitation using thread ID');
    $check(!$service->respondInvite(2,'pass-2',$invite,true), 'cannot double-accept');
    $check(count($service->members(2,'pass-2',$thread)) === 2, 'joined member visible');
    $check($service->send(2,'pass-2',$thread,'Hello there!'), 'joined member sends');
    $check(count($service->messages(1,'pass-1',$thread,0)) === 1, 'owner reads messages');
    $check(count($service->messages(2,'pass-2',$thread,0)) === 1, 'member reads messages');
    $check(count($service->search(2,'pass-2',$thread,'Hello',0)) === 1,
        'member searches own thread');
    $check($service->search(3,'pass-3',$thread,'Hello',0) === [],
        'outsider cannot search messages');
    $check($service->search(2,'pass-2',$thread,'there%xx',0) === [],
        'search wildcard must not disclose unrelated messages');

    $snapshot = $service->nativeSnapshot(1,'pass-1',$thread);
    $check(count($snapshot['messages']) === 1 && count($snapshot['members']) === 2,
        'only active members receive full native snapshot');
    $wire = $http->getMessages($req(1,'pass-1','getGJThreadMessages'))->body;
    $parts = explode('#',$wire);
    $check(count($parts) === 7 && str_contains($parts[0], base64_encode('Hello there!')),
        'real native thread reply has seven sections with positional messages');
    $check($parts[5] === '0' && $parts[6] === '3,1,10,50',
        'native thread trailing segments');

    $list = $http->getThreads($req(1,'pass-1','getGJThreads'))->body;
    $listParts = explode('#',$list);
    $check(count($listParts) === 2 && $listParts[1] === '1:0:10:0:60'
        && count(explode(':',$listParts[0])) === 26,
        'native thread list uses fixed positional record and page envelope');

    $sent = $http->send($req(2,'pass-2','sendGJThreadMessage',[
        'body'=>rtrim(strtr(base64_encode('Client ping'),'+/','-_'),'=')
    ]))->body;
    $check(preg_match('/^'.preg_quote((string)$thread,'/').':[1-9][0-9]*$/D',$sent) === 1,
        'native send responds with persisted threadID:messageID');
    $sentId = (int)explode(':',$sent)[1];
    $check((int)$db->query('SELECT COUNT(*) FROM mucho_thread_messages
        WHERE id='.$sentId.' AND body="Client ping"')->fetchColumn() === 1,
        'native Base64 body must be decoded before storing actual message');
    $check($http->send($req(2,'pass-2','sendGJThreadMessage',[
        'body'=>'**not-valid**'
    ]))->body === '-1', 'malformed Base64 content rejected');

    $aroundWire = $http->getMessages($req(1,'pass-1','getGJThreadMessages',[
        'aroundID'=>(string)$sentId
    ]))->body;
    $check(str_contains(explode('#',$aroundWire)[0],base64_encode('Client ping')),
        'native aroundID window includes selected persisted message');
    $olderWire = $http->getMessages($req(1,'pass-1','getGJThreadMessages',[
        'beforeID'=>(string)$sentId
    ]))->body;
    $check(str_contains(explode('#',$olderWire)[0],base64_encode('Hello there!')) &&
        !str_contains(explode('#',$olderWire)[0],base64_encode('Client ping')),
        'beforeID excludes newer message but includes older one');
    $newerWire = $http->getMessages($req(1,'pass-1','getGJThreadMessages',[
        'afterID'=>(string)($sentId-1)
    ]))->body;
    $check(str_contains(explode('#',$newerWire)[0],base64_encode('Client ping')),
        'afterID includes only the requested newer messages');
    $invalidCursor = $http->getMessages($req(1,'pass-1','getGJThreadMessages',[
        'aroundID'=>(string)$sentId, 'beforeID'=>(string)$sentId
    ]))->body;
    $check($invalidCursor === '-1', 'ambiguous multiple cursors refused');

    $searchWire = $http->search($req(1,'pass-1','searchGJThreadMessages',[
        'query'=>base64_encode('Client')
    ]))->body;
    $check(str_ends_with($searchWire,'#0') &&
        str_contains($searchWire,base64_encode('Client ping')),
        'native search uses decoded query and real remaining page count');
    $check($http->search($req(3,'pass-3','searchGJThreadMessages',[
        'query'=>base64_encode('Client')
    ]))->body === '-1', 'outsider cannot search private native messages');

    $check($http->update($req(1,'pass-1','updateGJThread',[
        'mode'=>'2','value'=>base64_encode('Native rename')
    ]))->body === '1', 'native numeric rename is accepted');
    $check($service->threads(1,'pass-1',0)[0]['title'] === 'Native rename',
        'native mode 2 decodes Base64 before persisting title');
    $check($http->update($req(2,'pass-2','updateGJThread',[
        'mode'=>'12','value'=>base64_encode((string)$sentId)
    ]))->body === '1', 'only sender can delete their own thread message');
    $check((int)$db->query('SELECT COUNT(*) FROM mucho_thread_messages
        WHERE id='.$sentId)->fetchColumn() === 0, 'mode 12 deletion persisted');
    $check($http->update($req(2,'pass-2','updateGJThread',[
        'mode'=>'6','value'=>base64_encode('1')
    ]))->body === '-1', 'unimplemented kick mode fails closed');



    $check($service->modify(1,'pass-1',$thread,'rename','Renamed'), 'owner renames');
    $check(
        $service->threads(1,'pass-1',0)[0]['title'] === 'Renamed',
        'rename persisted'
    );
    $db->exec('INSERT INTO blocks(account_id,blocked_account_id) VALUES (3,1)');
    $check(!$service->invite(1,'pass-1',$thread,3), 'blocked user cannot be invited');
    $db->exec('DELETE FROM blocks');
    $check($service->invite(1,'pass-1',$thread,3), 'owner can invite third user');
    $otherInvite = (int)$service->invites(3,'pass-3')[0]['id'];
    $check($service->respondInvite(3,'pass-3',$otherInvite,false), 'recipient declines');
    $check($service->members(3,'pass-3',$thread) === [], 'declined recipient remains out');
    $check($service->leave(2,'pass-2',$thread), 'member can leave');
    $check(!$service->leave(1,'pass-1',$thread), 'owner cannot orphan thread by leaving');
    $check(!$service->send(2,'pass-2',$thread,'After leaving'), 'left member cannot send');
    $check($service->modify(1,'pass-1',$thread,'close'), 'owner closes thread');
    $check(!$service->send(1,'pass-1',$thread,'Closed'), 'closed thread rejects messages');
    $check(!$service->invite(1,'pass-1',$thread,3), 'closed thread rejects invites');

    foreach ([
        static fn() => $service->threads(1,'pass-2',0),
        static fn() => $service->create(1,'pass-2','No'),
        static fn() => $service->invite(1,'pass-2',$thread,3),
        static fn() => $service->messages(1,'pass-2',$thread,0),
        static fn() => $service->send(1,'pass-2',$thread,'No'),
    ] as $attack) {
        try {
            $attack();
            throw new RuntimeException('Invalid credential was not rejected');
        } catch (RuntimeException $e) {
            $check($e->getMessage() === 'Unauthorized.', 'account credentials enforced');
        }
    }

    // Starting native DM and group chats must create real messages and
    // auto-enter DM recipient, while preserving group invite consent.
    $dmCreate = $http->send($req(1,'pass-1','sendGJThreadMessage',[
        'threadID'=>'0','isDM'=>'1','toAccountID'=>'2',
        'body'=>base64_encode('Private hello')
    ]))->body;
    $check(preg_match('/^[1-9][0-9]*:[1-9][0-9]*:0$/D',$dmCreate) === 1,
        'new DM wire response is threadID:messageID:0');
    $dm = (int)explode(':',$dmCreate)[0];
    $check(count($service->messages(2,'pass-2',$dm,0)) === 1,
        'DM recipient can read message as member without invitation');
    $dmCreate2 = $http->send($req(2,'pass-2','sendGJThreadMessage',[
        'threadID'=>'0','isDM'=>'1','toAccountID'=>'1',
        'body'=>base64_encode('Reply')
    ]))->body;
    $check((int)explode(':',$dmCreate2)[0] === $dm,
        'same unordered DM pair shares one database conversation');
    $onlyDms = $http->getThreads($req(1,'pass-1','getGJThreads',[
        'filter'=>'1'
    ]))->body;
    $dmParts = explode('#',$onlyDms);
    $check($dmParts[1] === '1:0:10:0:60' &&
        explode(':',$dmParts[0])[12] === '1',
        'native DM filter and type flag are real');

    $filteredDm = $http->getThreads($req(1,'pass-1','getGJThreads',[
        'filter'=>'1','user'=>'2'
    ]))->body;
    $check(str_ends_with($filteredDm,'#1:0:10:0:60'),
        'native user filter only returns own threads containing that account');
    $unrelated = $http->getThreads($req(1,'pass-1','getGJThreads',[
        'filter'=>'1','user'=>'3'
    ]))->body;
    $check($unrelated === '#0:0:10:0:60',
        'native user filtering does not return unrelated private DMs');
    $invalidFiltered = $http->getThreads($req(1,'pass-1','getGJThreads',[
        'user'=>'2,not-a-number'
    ]))->body;
    $check($invalidFiltered === '-1', 'malformed member filter denied');


    $groupCreate = $http->send($req(1,'pass-1','sendGJThreadMessage',[
        'threadID'=>'0','isDM'=>'0','toAccountID'=>'0',
        'body'=>base64_encode('Hello group'),
        'subject'=>base64_encode('New GD 2.209 group'),
        'description'=>base64_encode('Private test group'),
        'emote'=>base64_encode('globe'),
        'targets'=>base64_encode('3')
    ]))->body;
    $check(preg_match('/^[1-9][0-9]*:[1-9][0-9]*:1:0$/D',$groupCreate)===1,
        'new group response has real invite count and tail zero');
    $newGroup = (int)explode(':',$groupCreate)[0];
    $check($service->messages(3,'pass-3',$newGroup,0) === [],
        'group invitee cannot read messages before accepting invitation');
    $check(count($service->invites(3,'pass-3')) === 1,
        'new native group issued one pending invitation');


    $mode = static fn(int $who,string $pw,int $tid,int $action,string $raw='') =>
        $http->update($req($who,$pw,'updateGJThread',[
            'threadID'=>(string)$tid,
            'mode'=>(string)$action,
            'value'=>$raw === '' ? '' : base64_encode($raw)
        ]))->body;

    $check($mode(1,'pass-1',$newGroup,14,'New description') === '1',
        'owner changes persisted group description');
    $check($mode(1,'pass-1',$newGroup,16,'globe') === '1',
        'owner changes persisted group icon');
    $check($mode(1,'pass-1',$newGroup,13,'0') === '1',
        'owner can enable member invites');
    $check($mode(1,'pass-1',$newGroup,4,'2') === '1',
        'native group mode 4 atomically invites member 2');
    $check($mode(2,'pass-2',$newGroup,8) === '1',
        'native mode 8 accepts invitation to new group');
    $check($mode(1,'pass-1',$newGroup,7,'2') === '1',
        'owner can transfer group ownership to active member');
    $check($mode(1,'pass-1',$newGroup,6,'2') === '-1',
        'former owner may not kick new owner');
    $check($mode(2,'pass-2',$newGroup,7,'1') === '1',
        'new owner can hand ownership back');
    $check($mode(1,'pass-1',$newGroup,6,'2') === '1',
        'restored owner can kick a non-owner member');
    $check($http->getMessages($req(2,'pass-2','getGJThreadMessages',[
        'threadID'=>(string)$newGroup
    ]))->body === '-1', 'kicked member cannot read group history');
    $check($mode(2,'pass-2',$newGroup,18) === '1',
        'kicked player can dismiss own former-thread record');
    $check($mode(1,'pass-1',$newGroup,11,'3') === '1',
        'owner can revoke an outstanding invite');
    $check($service->invites(3,'pass-3') === [],
        'revoked invited user no longer sees this group');
    $check($mode(1,'pass-1',$dm,10,'1') === '1',
        'member can mute DM for themselves');
    $check($mode(1,'pass-1',$dm,15,'1') === '1',
        'member can pin DM for themselves');
    $check($mode(1,'pass-1',0,3,(string)$dm) === '1',
        'threadID 0 mark-read mode acknowledges a real member thread');
    $check($mode(1,'pass-1',$dm,1) === '1',
        'clear-history only changes the requesting member view');
    $cleared = $http->getMessages($req(1,'pass-1','getGJThreadMessages',[
        'threadID'=>(string)$dm
    ]))->body;
    $check(str_starts_with($cleared,'none#'), 'cleared member does not see old DMs');
    $otherHistory = $http->getMessages($req(2,'pass-2','getGJThreadMessages',[
        'threadID'=>(string)$dm
    ]))->body;
    $check(str_contains($otherHistory,base64_encode('Private hello')),
        'clearing own history cannot delete the peer conversation');
    $check($mode(1,'pass-1',$newGroup,6,'1') === '-1',
        'cannot kick oneself');
    $check($mode(1,'pass-1',$newGroup,16,"bad\nicon") === '-1',
        'invalid group metadata refused');
    // Exercise the native pagination hint with more than 50 messages.
    // Inserts are fixtures, not normal post requests (which are rate limited).
    $fixture = $db->prepare(
        'INSERT INTO mucho_thread_messages(thread_id,sender_account_id,body)
         VALUES (:thread,:actor,:body)'
    );
    for ($i = 0; $i < 52; $i++) {
        $fixture->execute([
            'thread'=>$newGroup, 'actor'=>1, 'body'=>'archive-'.$i
        ]);
    }
    $pageWire = $http->getMessages($req(1,'pass-1','getGJThreadMessages',[
        'threadID'=>(string)$newGroup
    ]))->body;
    $pageSegments = explode('#', $pageWire);
    $pageMetadata = explode(':', $pageSegments[4]);
    $check(count($pageSegments) === 7 &&
        count(explode('|', $pageSegments[0])) === 50 &&
        $pageMetadata[0] === (string)$newGroup &&
        $pageMetadata[2] === '1' &&
        $pageMetadata[8] === base64_encode('New GD 2.209 group') &&
        $pageMetadata[5] === '1',
        'native group snapshot uses actual id/title/members and Show Earlier=1');
    $visibleRows = explode('|', $pageSegments[0]);
    $oldest = (int)explode(':', end($visibleRows))[0];
    $earlierWire = $http->getMessages($req(1,'pass-1','getGJThreadMessages',[
        'threadID'=>(string)$newGroup, 'beforeID'=>(string)$oldest
    ]))->body;
    $earlierSegments = explode('#', $earlierWire);
    $earlierMetadata = explode(':', $earlierSegments[4]);
    $check($earlierMetadata[5] === '0' &&
        str_contains($earlierSegments[0], base64_encode('Hello group')),
        'native Show Earlier becomes zero on the actual oldest page');

    $db->exec('DELETE FROM accounts WHERE account_id=1');
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_threads')->fetchColumn() === 0,
        'deleting owner cascades thread deletion'
    );
    $check(
        (int)$db->query('SELECT COUNT(*) FROM mucho_thread_messages')->fetchColumn() === 0,
        'deleting thread cascades messages'
    );
    echo "MUCHOCORE_GD_2209_THREAD_INTEGRATION_OK\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $name);
}
