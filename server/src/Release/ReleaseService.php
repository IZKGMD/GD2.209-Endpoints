<?php
declare(strict_types=1);

namespace MuchoCore\Release;

final class ReleaseService
{
    private const REPOSITORY = 'IZKGMD/GMDmucho-core';
    private const RELEASE_API = 'https://api.github.com/repos/IZKGMD/GMDmucho-core/releases/latest';
    private const CACHE_TTL = 300;
    private const STALE_CACHE_TTL = 86400;
    private const MAX_RESPONSE_BYTES = 1048576;

    /**
     * @return array{
     *     current_version:string,
     *     latest_version:?string,
     *     update_available:bool,
     *     ahead_of_stable:bool,
     *     release_url:?string,
     *     release_name:?string,
     *     release_body:?string,
     *     published_at:?string,
     *     checked_at:?string,
     *     source:string,
     *     error:?string
     * }
     */
    public static function check(string $rootDir, string $cacheDir): array
    {
        $current = self::readCurrentVersion($rootDir);
        $cacheFile = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'release-check.json';

        @mkdir($cacheDir, 0770, true);

        $cached = self::readCache($cacheFile);
        $now = time();

        if (
            $cached !== null &&
            (($now - (int)($cached['checked_unix'] ?? 0)) < self::CACHE_TTL)
        ) {
            return self::buildStatus($current, $cached, 'cache', null);
        }

        try {
            $latest = self::fetchLatestRelease();
            $payload = [
                'checked_unix' => $now,
                'latest' => $latest,
            ];

            self::writeCache($cacheFile, $payload);

            return self::buildStatus($current, $payload, 'github', null);
        } catch (\Throwable $e) {
            if (
                $cached !== null &&
                (($now - (int)($cached['checked_unix'] ?? 0)) < self::STALE_CACHE_TTL)
            ) {
                return self::buildStatus(
                    $current,
                    $cached,
                    'stale-cache',
                    'GitHub release check failed; using the last successful check.'
                );
            }

            return [
                'current_version' => $current,
                'latest_version' => null,
                'update_available' => false,
                'ahead_of_stable' => false,
                'release_url' => null,
                'release_name' => null,
                'release_body' => null,
                'published_at' => null,
                'checked_at' => gmdate('c'),
                'source' => 'error',
                'error' => 'GitHub release check is temporarily unavailable.',
            ];
        }
    }

    public static function compareVersions(string $left, string $right): int
    {
        $a = self::parseVersion($left);
        $b = self::parseVersion($right);

        if ($a === null || $b === null) {
            return strcmp($left, $right);
        }

        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }

        return 0;
    }

    private static function readCurrentVersion(string $rootDir): string
    {
        $versionFile = rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . 'VERSION';
        $version = is_file($versionFile)
            ? trim((string)file_get_contents($versionFile))
            : '';

        return preg_match('/^v?(\d+\.\d+\.\d+)$/', $version, $m)
            ? $m[1]
            : '0.0.0';
    }

    /**
     * @return array{
     *     tag_name:string,
     *     name:string,
     *     html_url:string,
     *     body:string,
     *     published_at:string
     * }
     */
    private static function fetchLatestRelease(): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "Accept: application/vnd.github+json\r\n" .
                "User-Agent: MuchoCore-ReleaseChecker/1.0\r\n" .
                "X-GitHub-Api-Version: 2022-11-28\r\n",
        ]]);
        $handle = @fopen(self::RELEASE_API, 'rb', false, $context);
        if ($handle === false) throw new \RuntimeException('Unable to reach GitHub.');
        $body = stream_get_contents($handle, self::MAX_RESPONSE_BYTES + 1);
        fclose($handle);
        if ($body === false || strlen($body) > self::MAX_RESPONSE_BYTES) throw new \RuntimeException('GitHub response is too large.');
        $statusLine = (string)($http_response_header[0] ?? '');
        if (!preg_match('/\s(2\d\d)\s/', $statusLine)) throw new \RuntimeException('GitHub release request failed.');
        $release = json_decode($body, true);
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) throw new \RuntimeException('No stable semantic release was found.');
        $tag = trim((string)($release['tag_name'] ?? ''));
        if (self::parseVersion($tag) === null) throw new \RuntimeException('The latest stable release has an invalid version.');
        return [
            'tag_name' => ltrim($tag, 'vV'),
            'name' => trim((string)($release['name'] ?? '')),
            'html_url' => trim((string)($release['html_url'] ?? '')),
            'body' => trim((string)($release['body'] ?? '')),
            'published_at' => trim((string)($release['published_at'] ?? '')),
        ];
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private static function parseVersion(string $version): ?array
    {
        $version = trim($version);

        if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $version, $m)) {
            return null;
        }

        return [
            (int)$m[1],
            (int)$m[2],
            (int)$m[3],
        ];
    }

    private static function readCache(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode(
            (string)@file_get_contents($path),
            true
        );

        return is_array($decoded) && isset($decoded['latest'])
            ? $decoded
            : null;
    }

    private static function writeCache(string $path, array $payload): void
    {
        $tmp = $path . '.tmp';
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            return;
        }

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }

        @chmod($tmp, 0660);
        @rename($tmp, $path);
    }

    /**
     * @param array{checked_unix?:int,latest?:array<string,mixed>} $payload
     * @return array{
     *     current_version:string,
     *     latest_version:?string,
     *     update_available:bool,
     *     ahead_of_stable:bool,
     *     release_url:?string,
     *     release_name:?string,
     *     release_body:?string,
     *     published_at:?string,
     *     checked_at:?string,
     *     source:string,
     *     error:?string
     * }
     */
    private static function buildStatus(
        string $current,
        array $payload,
        string $source,
        ?string $error
    ): array {
        $latest = is_array($payload['latest'] ?? null)
            ? $payload['latest']
            : [];

        $latestVersion = trim((string)($latest['tag_name'] ?? ''));

        return [
            'current_version' => $current,
            'latest_version' => $latestVersion !== '' ? $latestVersion : null,
            'update_available' =>
                $latestVersion !== '' &&
                self::compareVersions($latestVersion, $current) > 0,
            'ahead_of_stable' =>
                $latestVersion !== '' &&
                self::compareVersions($current, $latestVersion) > 0,
            'release_url' => ($latest['html_url'] ?? '') !== ''
                ? (string)$latest['html_url']
                : null,
            'release_name' => ($latest['name'] ?? '') !== ''
                ? (string)$latest['name']
                : null,
            'release_body' => ($latest['body'] ?? '') !== ''
                ? (string)$latest['body']
                : null,
            'published_at' => ($latest['published_at'] ?? '') !== ''
                ? (string)$latest['published_at']
                : null,
            'checked_at' => isset($payload['checked_unix'])
                ? gmdate('c', (int)$payload['checked_unix'])
                : null,
            'source' => $source,
            'error' => $error,
        ];
    }
}
