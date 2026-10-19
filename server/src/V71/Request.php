<?php
declare(strict_types=1);

namespace MuchoCore\V71;

final class Request
{
    public static function string(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = $_POST[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || preg_match('/^-?\d+$/D', $value) !== 1) {
            return $default;
        }

        return (int)$value;
    }

    public static function boolInt(string $key, int $default = 0): int
    {
        return self::int($key, $default) === 1 ? 1 : 0;
    }

    public static function protocolText(string $value, int $maxLength): string
    {
        $value = str_replace(["\0", "\r"], ['', ''], $value);
        $value = str_replace(['#', '|'], ['', ''], $value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') > $maxLength) {
                $value = mb_substr($value, 0, $maxLength, 'UTF-8');
            }
        } elseif (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return $value;
    }

    /** @return list<int> */
    public static function idList(string $value, int $maxItems = 1000): array
    {
        $ids = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item === '' || !ctype_digit($item)) {
                continue;
            }
            $id = (int)$item;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            if (count($ids) >= $maxItems) {
                break;
            }
        }
        return $ids;
    }
}
