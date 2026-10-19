<?php

declare(strict_types=1);

namespace MuchoCore\Core\Validation;

final class Validator
{
    public static function int(
        mixed $value,
        int $default = 0
    ): int {
        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value) &&
            preg_match('/^-?\d+$/', $value) === 1
        ) {
            return (int) $value;
        }

        return $default;
    }

    public static function bool(mixed $value): bool
    {
        return self::int($value) !== 0;
    }

    public static function string(
        mixed $value,
        int $maxLength = 65535
    ): string {
        if (!is_string($value)) {
            return '';
        }

        if (strlen($value) > $maxLength) {
            return substr($value, 0, $maxLength);
        }

        return $value;
    }

    public static function username(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (
            $value === '' ||
            strlen($value) > 20
        ) {
            return null;
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]+$/',
                $value
            ) !== 1
        ) {
            return null;
        }

        return $value;
    }
}
