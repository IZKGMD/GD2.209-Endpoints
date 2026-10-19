<?php

declare(strict_types=1);

namespace MuchoCore\Level;

final class LevelValidator
{
    public const MAX_LEVEL_DATA = 32 * 1024 * 1024;
    public const MAX_LEVEL_INFO = 1024 * 1024;
    public const MAX_EXTRA_STRING = 65536;

    public static function validate(array $data): array
    {
        $errors = [];
        $warnings = [];

        $accountId = (int)($data['account_id'] ?? 0);
        $name = (string)($data['name'] ?? '');
        $levelData = (string)($data['level_data'] ?? '');
        $gameVersion = (int)($data['game_version'] ?? 0);
        $binaryVersion = (int)($data['binary_version'] ?? 0);
        $objectCount = (int)($data['object_count'] ?? 0);

        if ($accountId <= 0) {
            $errors[] = 'invalid_account_id';
        }

        if ($name === '' || strlen($name) > 64) {
            $errors[] = 'invalid_level_name';
        } elseif (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $errors[] = 'level_name_contains_control_characters';
        } elseif (preg_match('//u', $name) !== 1) {
            $errors[] = 'level_name_invalid_utf8';
        }

        if ($levelData === '') {
            $errors[] = 'empty_level_data';
        } elseif (strlen($levelData) > self::MAX_LEVEL_DATA) {
            $errors[] = 'level_data_too_large';
        } elseif (strpos($levelData, "\0") !== false) {
            $errors[] = 'level_data_contains_nul';
        }

        if ($gameVersion < 1 || $gameVersion > 1000) {
            $errors[] = 'invalid_game_version';
        }

        if ($binaryVersion < 0 || $binaryVersion > 1000000) {
            $errors[] = 'invalid_binary_version';
        }

        if ($objectCount < 0 || $objectCount > 5000000) {
            $errors[] = 'invalid_object_count';
        }

        $limits = [
            'description' => [8192, 'description_too_large'],
            'level_info' => [self::MAX_LEVEL_INFO, 'level_info_too_large'],
            'settings_string' => [self::MAX_LEVEL_INFO, 'settings_string_too_large'],
            'extra_string' => [self::MAX_EXTRA_STRING, 'extra_string_too_large'],
            'song_ids' => [65536, 'song_ids_too_large'],
            'sfx_ids' => [65536, 'sfx_ids_too_large'],
        ];

        foreach ($limits as $field => [$max, $error]) {
            if (isset($data[$field]) && strlen((string)$data[$field]) > $max) {
                $errors[] = $error;
            }
        }

        if ($objectCount >= 1000000) {
            $warnings[] = 'very_large_object_count';
        }

        if (strlen($levelData) >= 16 * 1024 * 1024) {
            $warnings[] = 'very_large_level_payload';
        }

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'sha256' => hash('sha256', $levelData),
            'level_data_bytes' => strlen($levelData),
        ];
    }
}
