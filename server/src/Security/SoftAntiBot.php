<?php

declare(strict_types=1);

namespace MuchoCore\Security;

final class SoftAntiBot
{
    private const MIN_FORM_AGE = 1.0;
    private const MAX_FORM_AGE = 1800.0;

    /**
     * Create a lightweight, invisible anti-bot token for a form.
     * No captcha, puzzle, or external service is required.
     */
    public static function issue(string $scope): array
    {
        if (!isset($_SESSION['mucho_antibot']) || !is_array($_SESSION['mucho_antibot'])) {
            $_SESSION['mucho_antibot'] = [];
        }

        $token = bin2hex(random_bytes(16));

        $_SESSION['mucho_antibot'][$scope][] = [
            'token' => $token,
            'started_at' => microtime(true),
        ];

        if (count($_SESSION['mucho_antibot'][$scope]) > 4) {
            array_shift($_SESSION['mucho_antibot'][$scope]);
        }

        return ['token' => $token];
    }

    public static function verify(
        string $scope,
        string $token,
        string $honeypot
    ): bool {
        if (trim($honeypot) !== '') {
            return false;
        }

        $states = $_SESSION['mucho_antibot'][$scope] ?? [];
        if (!is_array($states) || $token === '') {
            return false;
        }

        $now = microtime(true);

        foreach ($states as $index => $state) {
            if (!is_array($state)) {
                continue;
            }

            $expected = (string)($state['token'] ?? '');
            $startedAt = (float)($state['started_at'] ?? 0);

            if ($expected === '' || !hash_equals($expected, $token)) {
                continue;
            }

            unset($_SESSION['mucho_antibot'][$scope][$index]);

            $age = $now - $startedAt;
            return $age >= self::MIN_FORM_AGE && $age <= self::MAX_FORM_AGE;
        }

        return false;
    }
}
