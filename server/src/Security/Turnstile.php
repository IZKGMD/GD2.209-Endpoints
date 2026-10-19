<?php

declare(strict_types=1);

namespace MuchoCore\Security;

use MuchoCore\Core\Environment;
use MuchoCore\Http\ClientIp;

final class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function siteKey(): string
    {
        return trim((string)(Environment::get('TURNSTILE_SITEKEY') ?? ''));
    }

    public static function enabled(): bool
    {
        return self::siteKey() !== '' && self::secret() !== '';
    }

    public static function verify(
        string $token,
        string $action,
        ?string $expectedHostname = null
    ): bool {
        $secret = self::secret();
        $token = trim($token);

        if ($secret === '' || $token === '' || strlen($token) > 2048) {
            return false;
        }

        $payload = [
            'secret' => $secret,
            'response' => $token,
        ];

        $remoteIp = ClientIp::resolve($_SERVER);

        if ($remoteIp !== '0.0.0.0') {
            $payload['remoteip'] = $remoteIp;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents(self::VERIFY_URL, false, $context);

        if (!is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $result = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            return false;
        }

        $returnedAction = trim((string)($result['action'] ?? ''));
        if ($action !== '' && $returnedAction !== $action) {
            return false;
        }

        if ($expectedHostname !== null && $expectedHostname !== '') {
            $returnedHostname = trim((string)($result['hostname'] ?? ''));

            if (
                $returnedHostname === '' ||
                strcasecmp($returnedHostname, trim($expectedHostname)) !== 0
            ) {
                return false;
            }
        }

        return true;
    }

    private static function secret(): string
    {
        return trim((string)(Environment::get('TURNSTILE_SECRET') ?? ''));
    }
}
