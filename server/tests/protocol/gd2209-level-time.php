<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MuchoCore\Protocol\GdLevelListEncoder;
use MuchoCore\Protocol\GdLevelDownloadEncoder;

$check = static function(bool $okay, string $message): void {
    if (!$okay) {
        throw new RuntimeException('GD 2.209 time wire: ' . $message);
    }
};

$upload = '2026-10-15 12:00:00';
$update = '2026-10-17 10:30:00';
$epochUpload = (new DateTimeImmutable($upload, new DateTimeZone('UTC')))->getTimestamp();
$epochUpdate = (new DateTimeImmutable($update, new DateTimeZone('UTC')))->getTimestamp();

$level = [
    'level_id' => 123, 'account_id' => 1, 'user_id' => 1, 'username' => 'Builder',
    'name' => 'TimestampTest', 'description' => 'desc', 'level_data' => 'abc',
    'level_version' => 1, 'game_version' => 22,
    'downloads' => 12, 'likes' => 4, 'difficulty' => 30,
    'audio_track' => 0, 'demon' => 0, 'demon_difficulty' => 0,
    'auto_level' => 0, 'stars' => 5, 'featured' => 0,
    'epic' => 0, 'object_count' => 100, 'length' => 2,
    'original_level_id' => 0, 'two_player' => 0, 'coins' => 0,
    'coins_verified' => 0, 'requested_stars' => 5, 'ldm' => 0,
    'song_id' => 0, 'copy_password' => '0', 'extra_string' => '',
    'wt' => 0, 'wt2' => 0, 'settings_string' => '',
    'song_ids' => '', 'sfx_ids' => '', 'ts' => 0,
    'created_at' => $upload, 'updated_at' => $update,
];
$list = new GdLevelListEncoder();
$download = new GdLevelDownloadEncoder();

$list209 = $list->encode([$level], 1, 0, 10, 22, 51);
$list208 = $list->encode([$level], 1, 0, 10, 22, 47);
$download209 = $download->encode($level, 22, false, 51);
$download208 = $download->encode($level, 22, false, 47);

$fragment = ':62:' . $epochUpload . ':63:' . $epochUpdate;
$check(str_contains($list209, $fragment), 'list includes exact timestamps for 2.209');
$check(str_contains($download209, $fragment), 'download includes exact timestamps for 2.209');
$check(!str_contains($list208, ':62:'), 'legacy list response unchanged');
$check(!str_contains($download208, ':62:'), 'legacy download response unchanged');
$check(!str_contains($list->encode([$level], 1, 0, 10, 21, 51), ':62:'),
    'older game protocol does not acquire 2.209-only fields');
$check(!str_contains($download->encode($level, 21, false, 51), ':62:'),
    'older download protocol unchanged');

echo "MUCHOCORE_GD_2209_TIME_WIRE_OK\n";
