<?php

declare(strict_types=1);

namespace MuchoCore\Branding;

use MuchoCore\Core\Environment;
use PDO;
use Throwable;

final class BrandingService
{
    public const DEFAULT_SERVER_NAME = 'Mucho GDPS';
    public const MAX_SERVER_NAME_LENGTH = 64;
    public const MAX_SERVER_BY_NAME_LENGTH = 64;
    public const MAX_SOCIAL_URL_LENGTH = 512;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Return the public branding configuration.
     *
     * The fallback keeps the public site usable during first installation,
     * before the branding migration has been applied.
     *
     * @return array{server_name:string,server_by_name:string,social_url:string}
     */
    public function get(): array
    {
        try {
            $row = $this->db->query(
                'SELECT server_name,server_by_name,social_url
                 FROM mucho_branding
                 WHERE id=1
                 LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                $name = self::sanitize((string)($row['server_name'] ?? ''));
                $creditName = self::sanitizeServerByName(
                    (string)($row['server_by_name'] ?? '')
                );
                $socialUrl = self::sanitizeSocialUrl(
                    (string)($row['social_url'] ?? '')
                );

                return [
                    'server_name' => $name !== ''
                        ? $name
                        : self::DEFAULT_SERVER_NAME,
                    'server_by_name' => $creditName,
                    'social_url' => $socialUrl,
                ];
            }
        } catch (Throwable) {
            // Fall back to the built-in name when the table or new columns
            // are not available yet.
        }

        $configuredName = self::sanitize(
            (string)(Environment::get('MUCHO_SERVER_NAME') ?? '')
        );

        return [
            'server_name' => $configuredName !== ''
                ? $configuredName
                : self::DEFAULT_SERVER_NAME,
            'server_by_name' => '',
            'social_url' => '',
        ];
    }

    public function serverName(): string
    {
        return $this->get()['server_name'];
    }

    public function saveServerName(string $name): string
    {
        $name = self::sanitize($name);

        if ($name === '') {
            $name = self::DEFAULT_SERVER_NAME;
        }

        $q = $this->db->prepare(
            'INSERT INTO mucho_branding
                (id,server_name)
             VALUES
                (1,:server_name)
             ON DUPLICATE KEY UPDATE
                server_name=VALUES(server_name)'
        );

        $q->execute([
            'server_name' => $name,
        ]);

        return $name;
    }

    /**
     * @return array{server_name:string,server_by_name:string,social_url:string}
     */
    public function saveServerCredit(
        string $name,
        string $socialUrl
    ): array {
        $name = self::sanitizeServerByName($name);
        $socialUrl = self::sanitizeSocialUrl($socialUrl);

        if ($name === '' || $socialUrl === '') {
            $name = '';
            $socialUrl = '';
        }

        /*
         * Keep older installations functional until the optional branding
         * columns have been migrated. Server name itself must never depend on
         * the social-credit schema being present.
         */
        if (!$this->brandingCreditColumnsExist()) {
            return $this->get();
        }

        $q = $this->db->prepare(
            'INSERT INTO mucho_branding
                (id,server_name,server_by_name,social_url)
             VALUES
                (1,:server_name,:server_by_name,:social_url)
             ON DUPLICATE KEY UPDATE
                server_name=VALUES(server_name),
                server_by_name=VALUES(server_by_name),
                social_url=VALUES(social_url)'
        );

        $q->execute([
            'server_name' => $this->serverName(),
            'server_by_name' => $name !== '' ? $name : null,
            'social_url' => $socialUrl !== '' ? $socialUrl : null,
        ]);

        return $this->get();
    }

    private function brandingCreditColumnsExist(): bool
    {
        try {
            $stmt = $this->db->query(
                "SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'mucho_branding'
                   AND column_name IN ('server_by_name','social_url')"
            );

            return (int)$stmt->fetchColumn() === 2;
        } catch (Throwable) {
            return false;
        }
    }

    public static function sanitize(string $name): string
    {
        $name = trim($name);

        $name = preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            $name
        ) ?? '';

        if (mb_strlen($name, 'UTF-8') > self::MAX_SERVER_NAME_LENGTH) {
            $name = mb_substr(
                $name,
                0,
                self::MAX_SERVER_NAME_LENGTH,
                'UTF-8'
            );
        }

        return trim($name);
    }

    public static function sanitizeServerByName(string $name): string
    {
        $name = self::sanitize($name);

        if (mb_strlen($name, 'UTF-8') > self::MAX_SERVER_BY_NAME_LENGTH) {
            $name = mb_substr(
                $name,
                0,
                self::MAX_SERVER_BY_NAME_LENGTH,
                'UTF-8'
            );
        }

        return trim($name);
    }

    public static function sanitizeSocialUrl(string $url): string
    {
        $url = trim($url);
        $url = preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            $url
        ) ?? '';

        if ($url === '') {
            return '';
        }

        if (
            strlen($url) > self::MAX_SOCIAL_URL_LENGTH ||
            !filter_var($url, FILTER_VALIDATE_URL)
        ) {
            throw new \InvalidArgumentException('Invalid social/profile URL.');
        }

        $parsed = parse_url($url);
        if (
            !is_array($parsed) ||
            !in_array(
                strtolower((string)($parsed['scheme'] ?? '')),
                ['http', 'https'],
                true
            ) ||
            (string)($parsed['host'] ?? '') === ''
        ) {
            throw new \InvalidArgumentException(
                'Social/profile URL must use http:// or https://.'
            );
        }

        return $url;
    }
}
