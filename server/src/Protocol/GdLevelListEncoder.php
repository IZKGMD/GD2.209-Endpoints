<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdLevelListEncoder
{
    private const HASH_SALT = 'xI25fpAapCQg';

    public function encode(
        array $levels,
        int $total,
        int $offset,
        int $limit,
        int $gameVersion = 22,
        int $binaryVersion = 0
    ): string {
        if (empty($levels) || $total === 0) {
            return '-2';
        }

        $levelStrings = [];
        $userStrings = [];
        $songStrings = [];
        $uniqueUsers = [];
        $uniqueSongs = [];
        $hashData = '';

        foreach ($levels as $level) {
            $levelId = (string)$level['level_id'];
            $userId = (string)($level['user_id'] ?? $level['account_id'] ?? 1);
            $accountId = (string)($level['account_id'] ?? 1);
            $username = ProtocolText::username(
                $level['username'] ?? 'Player'
            );
            /*
             * Preserve custom star values (15/30/50, etc.) in the multi-level
             * hash. Cvolton's genMulti hashes the stored star value verbatim.
             */
            $wireDifficulty = $this->wireDifficulty((int)($level['difficulty'] ?? 0));

            $protocolStars = max(
                0,
                (int)($level['stars'] ?? 0)
            );

            $levelStrings[] = implode(':', [
                1, $levelId,
                2, ProtocolText::field((string)$level['name']),
                5, (int)$level['level_version'],
                6, $userId,
                8, 10,
                9, $wireDifficulty,
                10, (int)$level['downloads'],
                12, (int)$level['audio_track'],
                13, (int)$level['game_version'],
                14, (int)$level['likes'],
                17, (int)$level['demon'],
                43, (int)$level['demon_difficulty'],
                25, (int)$level['auto_level'],
                18, (int)$level['stars'],
                19, (int)$level['featured'],
                42, (int)$level['epic'],
                45, (int)$level['object_count'],
                3, ProtocolText::field(
                    GdLegacyText::encodeDescriptionForResponse(
                        (string)($level['description'] ?? ''),
                        $gameVersion
                    )
                ),
                15, (int)$level['length'],
                30, (int)$level['original_level_id'],
                31, (int)$level['two_player'],
                37, (int)$level['coins'],
                38, (int)$level['coins_verified'],
                39, (int)$level['requested_stars'],
                46, 1,
                47, 2,
                40, (int)$level['ldm'],
                35, (int)$level['song_id'],
                ...($gameVersion >= 22 && $binaryVersion >= 51
                    ? [
                        62, $this->dateEpoch($level['created_at'] ?? null),
                        63, $this->dateEpoch($level['updated_at'] ?? null),
                    ]
                    : []),
            ]);

            if (!isset($uniqueUsers[$userId])) {
                $uniqueUsers[$userId] = true;
                $userStrings[] = implode(':', [
                    $userId,
                    $username,
                    $accountId,
                ]);
            }

            $songId = (int)($level['song_protocol_id'] ?? 0);

            if (
                $gameVersion > 18 &&
                $songId > 0 &&
                !isset($uniqueSongs[$songId])
            ) {
                $uniqueSongs[$songId] = true;

                $songStrings[] = implode('~|~', [
                    1, $songId,
                    2, ProtocolText::field((string)($level['song_name'] ?? '')),
                    3, (int)($level['song_author_id'] ?? 0),
                    4, ProtocolText::field((string)($level['song_author_name'] ?? '')),
                    5, round((float)($level['song_size'] ?? 0), 2),
                    6, (string)($level['song_youtube_video_id'] ?? ''),
                    10, (string)($level['song_download_url'] ?? ''),
                    7, (string)($level['song_youtube_channel_id'] ?? ''),
                    8, 1,
                ]);
            }

            $firstChar = $levelId[0];
            $lastChar = $levelId[strlen($levelId) - 1];
            $hashData .=
                $firstChar .
                $lastChar .
                $protocolStars .
                (int)$level['coins'];
        }

        $levelsPart = implode('|', $levelStrings);
        $usersPart = implode('|', $userStrings);
        $songsPart = implode('~:~', $songStrings);
        $pageInfo = $total . ':' . $offset . ':' . $limit;
        $hashPart = sha1($hashData . self::HASH_SALT);

        return $levelsPart
            . '#'
            . $usersPart
            . '#'
            . $songsPart
            . '#'
            . $pageInfo
            . '#'
            . $hashPart;
    }    
    private function dateEpoch(mixed $value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        try {
            return max(0, (new \DateTimeImmutable(
                $value, new \DateTimeZone('UTC')
            ))->getTimestamp());
        } catch (\Throwable) {
            return 0;
        }
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
