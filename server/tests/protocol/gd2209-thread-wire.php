<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Protocol\Gd2209ThreadWire;
use MuchoCore\Protocol\GdRelationshipEncoder;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('GD2.209 thread wire: ' . $message);
    }
};
$assert(Gd2209ThreadWire::relativeTime(0, 10000) === '',
    'unknown last-message timestamp must stay empty');
$assert(Gd2209ThreadWire::relativeTime(9940, 10000) === '1 minute',
    'native last-message label displays minute units');
$assert(Gd2209ThreadWire::relativeTime(2800, 10000) === '2 hours',
    'native last-message label displays hour units');
$assert(Gd2209ThreadWire::relativeTime(7000, 10000) === '50 minutes',
    'native last-message relative time is based on actual elapsed seconds');

$rows = [[
    'id'=>589952, 'thread_id'=>9, 'sender_account_id'=>14054365,
    'body'=>'hello', 'created_at'=>'2026-10-18 12:00:00 UTC'
]];
$expected = '589952:14054365:aGVsbG8=:1792324800:0';
$assert(Gd2209ThreadWire::message($rows[0]) === $expected,
    'must emit five positional fields, not key/value fields');
$assert(Gd2209ThreadWire::messages([]) === 'none',
    'native empty message list marker is none');
$assert(Gd2209ThreadWire::messages($rows) === $expected, 'one real message');
$reference = [
    'thread'=>['id'=>9,'owner_account_id'=>11,'title'=>'My group'],
    'messages'=>$rows,
    'members'=>[[
        'account_id'=>11, 'username'=>'Owner', 'user_id'=>100,
        'cube'=>2,'color1'=>1,'color2'=>3,'special'=>0
    ]],
    'invites'=>[]
];
$envelope = Gd2209ThreadWire::snapshot($reference,new GdRelationshipEncoder());
$segments = explode('#',$envelope);
$assert(count($segments) === 7, 'exactly seven native segments');
$assert($segments[0] === $expected, 'messages segment uses native object');
$assert(str_contains($segments[1], '1:Owner:2:100'), 'real member segment');
$assert($segments[2] === '' && $segments[3] === '',
    'unknown historical and empty pending invites have empty sections');
$meta = explode(':', $segments[4]);
$assert(count($meta) === 11, 'native metadata has eleven positions');
$assert($meta[0] === '9' && $meta[1] === '0' && $meta[2] === '1',
    'metadata starts with thread ID, zero and actual member count');
$assert($meta[8] === base64_encode('My group'),
    'metadata slot 9 is the group title, never the emote icon');
$assert($meta[9] === '0', 'open threads have zero lock flag');
$assert($segments[5] === '0' && $segments[6] === '3,1,10,50',
    'native last-message and tail constants');
$metadata = Gd2209ThreadWire::metadata([
    'id'=>45, 'owner_account_id'=>99, 'member_count'=>3,
    'title'=>'Another group', 'emote'=>'shocked',
    'description'=>'Some text', 'is_closed'=>1
]);
$fields = explode(':', $metadata);
$assert($fields[0] === '45' && $fields[0] !== '99',
    'native metadata thread ID must not be owner account ID');
$assert($fields[2] === '3' && $fields[3] === base64_encode('Some text'),
    'native metadata reads actual group size and description');
$assert($fields[8] === base64_encode('Another group')
    && $fields[8] !== base64_encode('shocked') && $fields[9] === '1',
    'native metadata title is in slot nine and closed state in slot ten');

$inviteUser = Gd2209ThreadWire::invites([[
    'invitee_account_id'=>23, 'user_id'=>57, 'username'=>'Join me',
    'inviter_account_id'=>11,'cube'=>2,'color1'=>3,'color2'=>4,'special'=>1,
]]);
$inviteFields = explode(':',$inviteUser);
$assert(count($inviteFields) === 17, 'native invited user has 17 positional values');
$assert($inviteFields[0] === '23' && $inviteFields[1] === '57'
    && $inviteFields[2] === base64_encode('Join me')
    && $inviteFields[3] === '11', 'identity fields for invited player');

$inbox = Gd2209ThreadWire::inboxInvites([[
    'thread_id'=>57,'inviter_account_id'=>81,'inviter_username'=>'Alice',
    'title'=>'A group','description'=>'Join us','emote'=>'globe',
    'is_dm'=>0,'created_at'=>'2026-10-18 12:00:00 UTC',
    'inviter_cube'=>4,'inviter_color1'=>2,
    'inviter_color2'=>3,'inviter_special'=>2
]]);
$inboxFields = explode(':',$inbox);
$assert(count($inboxFields) === 19,
    'native inbox invite has nineteen positional fields');
$assert($inboxFields[0] === '57' && $inboxFields[1] === '81'
    && $inboxFields[2] === base64_encode('Alice')
    && $inboxFields[3] === base64_encode('A group'),
    'inbox invite has actual thread and inviter identities');
$assert(Gd2209ThreadWire::inboxInvites([]) === 'none',
    'native empty invite inbox returns literal none');

$reference['messages'] = [];
$assert(str_starts_with(Gd2209ThreadWire::snapshot(
    $reference, new GdRelationshipEncoder()
), 'none#'), 'zero messages is a valid seven-section envelope');

echo "MUCHOCORE_GD2209_THREAD_WIRE_OK\n";
