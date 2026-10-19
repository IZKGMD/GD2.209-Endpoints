<?php

declare(strict_types=1);

namespace MuchoCore\Protocol;

final class GdXor
{
    public static function cipher(
        string $text,
        string $key
    ): string {
        if ($key === '') {
            return $text;
        }

        $result = '';
        $keyLength = strlen($key);

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $result .= chr(
                ord($text[$i])
                ^ ord($key[$i % $keyLength])
            );
        }

        return $result;
    }

    public static function copyPassword(string $password): string
    {
        if ($password === '0' || $password === '') {
            return '0';
        }

        return base64_encode(
            self::cipher($password, '26364')
        );
    }
}
