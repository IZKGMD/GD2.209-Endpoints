<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdHash
{
    private const SALT = 'xI25fpAapCQg';
    private const REWARDS_SALT = 'pC26fpYaQCtg';

    public static function level(string $data): string
    {
        $length = strlen($data);

        if ($length < 41) {
            return sha1($data . self::SALT);
        }

        $step = intdiv($length, 40);
        $sample = '';

        for ($i = 0; $i < 40; $i++) {
            $sample .= $data[$i * $step];
        }

        return sha1($sample . self::SALT);
    }

    public static function metadata(string $data): string
    {
        return sha1($data . self::SALT);
    }

    public static function rewards(string $data): string
    {
        return sha1($data . self::REWARDS_SALT);
    }
}
