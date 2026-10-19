<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

use DateTimeImmutable;

use MuchoCore\Protocol\GdLegacyText;

final class GdLevelDownloadEncoder
{
    public function encode(
        array $level,
        int $gameVersion,
        bool $extras = false,
        int $binaryVersion = 0
    ): string {
        $levelString = (string) $level['level_data'];
        $protocolStars = max(
            0,
            (int)($level['stars'] ?? 0)
        );

        if (
            $gameVersion > 18 &&
            str_starts_with($levelString, 'kS1')
        ) {
            $levelString = base64_encode(
                gzcompress($levelString)
            );

            $levelString = str_replace(
                ['/', '+'],
                ['_', '-'],
                $levelString
            );
        }

        $description = (string) $level['description'];

        if ($gameVersion < 20) {
            /*
             * GD 1.9 stores the description encoded, but the legacy
             * download response sends the decoded text.
             */
            $description = GdLegacyText::decodeDescriptionForStorage(
                $description
            );
        } else {
            $description = GdLegacyText::encodeDescriptionForResponse(
                $description,
                $gameVersion
            );
        }

        $wireDifficulty = $this->wireDifficulty((int)($level['difficulty'] ?? 0));

        $password = (string) $level['copy_password'];

        $encodedPassword = $gameVersion > 19
            ? GdXor::copyPassword($password)
            : $password;

        $uploadDate = (new DateTimeImmutable(
            (string) $level['created_at']
        ))->format('d-m-Y G-i');

        $updateDate = (new DateTimeImmutable(
            (string) $level['updated_at']
        ))->format('d-m-Y G-i');

        $fields = [
            1, $level['level_id'],
            2, ProtocolText::field($level['name'], 64),
            3, ProtocolText::field($description, 8192),
            4, $levelString,
            5, $level['level_version'],
            6, $level['user_id'],
            8, 10,
            9, $wireDifficulty,
            10, $level['downloads'],
            11, 1,
            12, $level['audio_track'],
            13, $level['game_version'],
            14, $level['likes'],
            17, $level['demon'],
            43, $level['demon_difficulty'],
            25, $level['auto_level'],
            18, $level['stars'],
            19, $level['featured'],
            42, $level['epic'],
            45, $level['object_count'],
            15, $level['length'],
            30, $level['original_level_id'],
            31, $level['two_player'],
            28, $uploadDate,
            29, $updateDate,
            35, $level['song_id'],
            36, $level['extra_string'],
            37, $level['coins'],
            38, $level['coins_verified'],
            39, $level['requested_stars'],
            46, $level['wt'],
            47, $level['wt2'],
            48, $level['settings_string'] ?? '',
            40, $level['ldm'],
            27, $encodedPassword,
            52, $level['song_ids'] ?? '',
            53, $level['sfx_ids'] ?? '',
            57, $level['ts'],
        ];

        if ($gameVersion >= 22 && $binaryVersion >= 51) {
            $fields[] = 62;
            $fields[] = (new DateTimeImmutable(
                (string)$level['created_at'], new \DateTimeZone('UTC')
            ))->getTimestamp();
            $fields[] = 63;
            $fields[] = (new DateTimeImmutable(
                (string)$level['updated_at'], new \DateTimeZone('UTC')
            ))->getTimestamp();
        }

        if ($extras) {
            $fields[] = 26;
            $fields[] = $level['level_info'] ?? '';
        }

        $response = implode(':', $fields);

        $firstHash = GdHash::level($levelString);

        $metadata =
            $level['user_id']
            . ','
            . $level['stars']
            . ','
            . $level['demon']
            . ','
            . $level['level_id']
            . ','
            . $level['coins']
            . ','
            . $level['featured']
            . ','
            . $password
            . ',0';

        return $response
            . '#'
            . $firstHash
            . '#'
            . GdHash::metadata($metadata);
    }    
    private function wireDifficulty(int $difficulty): int
    {
        /*
         * MuchoCore stores standard difficulties internally as 1..6,
         * while Geometry Dash expects 10..60 on the wire. Accept both
         * representations so legacy data remains compatible.
         */
        return ($difficulty >= 1 && $difficulty <= 6)
            ? $difficulty * 10
            : max(0, $difficulty);
    }

}
