<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use MuchoCore\Core\Environment;

use RuntimeException;

final class DeploymentClientPack
{
    public static function token(string $jobDir): string
    {
        $path = $jobDir . '/client-pack-token';

        if (is_file($path)) {
            $token = trim((string)file_get_contents($path));
            if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
                return $token;
            }
        }

        $token = bin2hex(random_bytes(32));
        if (@file_put_contents($path, $token, LOCK_EX) === false) {
            throw new RuntimeException('Cannot create client pack token.');
        }

        @chmod($path, 0600);
        return $token;
    }

    public static function prepare(
        string $rootDir,
        string $jobDir,
        string $serverUrl,
        string $serverName = '',
        ?callable $heartbeat = null
    ): array {
        $server = WindowsClientPatcher::validateServerUrl($serverUrl);
        $serverName = trim($serverName);
        if ($serverName === '') {
            $serverName = trim((string)(Environment::get('MUCHO_SERVER_NAME', 'Mucho GDPS') ?? 'Mucho GDPS'));
        }
        /*
         * Prefer a persistent GD 2.209 base executable when supplied on the
         * VPS. Do not overwrite the tracked legacy GeometryDash.exe file.
         * The binary patcher selects exact-build 2.209 handling by SHA-256.
         */
        $windows2209Base = $rootDir . '/storage/client-sources/GeometryDash-2.209.exe';
        if (is_file($windows2209Base)) {
            if (!is_readable($windows2209Base)) {
                throw new RuntimeException('GD 2.209 base EXE exists but is not readable.');
            }
            if (!Gd2209Binary::supports($windows2209Base)) {
                throw new RuntimeException(
                    'GD 2.209 base EXE has an unexpected SHA-256. Refusing to silently use the older executable.'
                );
            }
            $sourceExe = $windows2209Base;
        } else {
            $sourceExe = $rootDir . '/GeometryDash.exe';
        }
        $sourceApk = self::androidSource($rootDir, $jobDir);

        if (!is_file($sourceExe) || !is_readable($sourceExe)) {
            throw new RuntimeException(
                'Built-in Geometry Dash Windows client is unavailable on this control server.'
            );
        }

        if (!is_file($sourceApk) || !is_readable($sourceApk)) {
            $candidates = self::androidSourceCandidates($rootDir);
            throw new RuntimeException(
                'Built-in Geometry Dash Android APK is unavailable. Checked: ' .
                implode(', ', $candidates) .
                '. Upload the base APK to patched/apk/ or set MUCHO_ANDROID_BASE_APK to a readable path inside the MuchoCore container.'
            );
        }

        $windowsSourceSha = hash_file('sha256', $sourceExe);
        $androidSourceSha = hash_file('sha256', $sourceApk);
        if (!is_string($windowsSourceSha) || !is_string($androidSourceSha)) {
            throw new RuntimeException('Cannot calculate the input client SHA-256 hashes.');
        }
        $sourceSha256 = [
            'windows' => $windowsSourceSha,
            'android' => $androidSourceSha,
        ];

        $lockPath = $jobDir . '/client-pack.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot create client pack lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock client pack generation.');
            }

            $manifestPath = $jobDir . '/client-pack.json';
            $existing = is_file($manifestPath)
                ? json_decode((string)file_get_contents($manifestPath), true)
                : null;

            if (self::canReuseClientPack($existing, $server, $sourceSha256)) {
                return $existing;
            }

            $windowsPath = $jobDir . '/GeometryDash-MuchoGDPS.exe';
            $androidPath = $jobDir . '/GeometryDash-MuchoGDPS.apk';
            $archivePath = $jobDir . '/MuchoGDPS-Client-Pack.zip';

            @unlink($windowsPath);
            @unlink($androidPath);
            @unlink($archivePath);

            $windows = WindowsClientPatcher::patchFile(
                $sourceExe,
                $windowsPath,
                $server
            );

            $android = AndroidClientPatcher::patchFile(
                $sourceApk,
                $androidPath,
                $server,
                $heartbeat
            );

            $createdAt = gmdate('c');
            $publicManifest = [
                'patch_engine' => '2.0',
                'server_url' => $server,
                'server_name' => $serverName,
                'created_at' => $createdAt,
                'files' => [
                    [
                        'name' => 'windows/GeometryDash-MuchoGDPS.exe',
                        'size' => (int)$windows['output_size'],
                        'sha256' => (string)$windows['output_sha256'],
                    ],
                    [
                        'name' => 'android/GeometryDash-MuchoGDPS.apk',
                        'size' => (int)$android['output_size'],
                        'sha256' => (string)$android['output_sha256'],
                    ],
                ],
            ];

            $zip = new \ZipArchive();
            if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the client pack ZIP archive.');
            }

            try {
                if (!$zip->addFile($windowsPath, 'windows/GeometryDash-MuchoGDPS.exe')) {
                    throw new RuntimeException('Cannot add the Windows client to the client pack ZIP.');
                }
                if (!$zip->addFile($androidPath, 'android/GeometryDash-MuchoGDPS.apk')) {
                    throw new RuntimeException('Cannot add the Android client to the client pack ZIP.');
                }
                if (!$zip->addFromString(
                    'client-pack.json',
                    json_encode($publicManifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                )) {
                    throw new RuntimeException('Cannot add the client pack manifest to the ZIP archive.');
                }
            } finally {
                $zip->close();
            }

            $archiveSize = filesize($archivePath);
            $archiveSha256 = hash_file('sha256', $archivePath);
            if (
                $archiveSize === false ||
                $archiveSize < 1024 ||
                !is_string($archiveSha256) ||
                $archiveSha256 === ''
            ) {
                throw new RuntimeException('Generated client pack ZIP archive is invalid.');
            }

            $manifest = [
                'patch_engine' => '2.0',
                'server_url' => $server,
                'server_name' => $serverName,
                'created_at' => $createdAt,
                // Key the cached pack to the actual input binaries, not only
                // the server URL. Existing manifests without hashes expire.
                'source_sha256' => $sourceSha256,
                'windows' => [
                    'path' => $windowsPath,
                    'name' => 'GeometryDash-MuchoGDPS.exe',
                    'size' => (int)$windows['output_size'],
                    'sha256' => (string)$windows['output_sha256'],
                    'replacement_count' => (int)$windows['replacement_count'],
                ],
                'android' => [
                    'path' => $androidPath,
                    'name' => 'GeometryDash-MuchoGDPS.apk',
                    'size' => (int)$android['output_size'],
                    'sha256' => (string)$android['output_sha256'],
                    'replacement_count' => (int)$android['replacement_count'],
                ],
                'archive' => [
                    'path' => $archivePath,
                    'name' => 'MuchoGDPS-Client-Pack.zip',
                    'size' => (int)$archiveSize,
                    'sha256' => $archiveSha256,
                ],
            ];

            file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                LOCK_EX
            );
            @chmod($manifestPath, 0600);

            return $manifest;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A cached pack must match both input clients. This prevents an EXE
     * upgrade from silently republishing the previous Windows build.
     *
     * @param array<string, mixed>|null $existing
     * @param array{windows:string,android:string} $sourceSha256
     */
    public static function canReuseClientPack(
        ?array $existing,
        string $server,
        array $sourceSha256
    ): bool {
        if (
            !is_array($existing) ||
            ($existing['server_url'] ?? null) !== $server ||
            ($existing['source_sha256']['windows'] ?? null) !== $sourceSha256['windows'] ||
            ($existing['source_sha256']['android'] ?? null) !== $sourceSha256['android']
        ) {
            return false;
        }

        foreach (['windows', 'android', 'archive'] as $type) {
            $path = $existing[$type]['path'] ?? null;
            if (!is_string($path) || $path === '' || !is_file($path)) {
                return false;
            }
        }

        return true;
    }

    public static function androidSource(string $rootDir, string $jobDir = ''): string
    {
        foreach (self::androidSourceCandidates($rootDir, $jobDir) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Return the ordered locations where the control plane may keep the
     * immutable Android base APK. Persistent control-storage locations survive
     * repository updates and are shared by VPS and shared-hosting workers.
     */
    public static function androidSourceCandidates(string $rootDir, string $jobDir = ''): array
    {
        $configured = trim((string)(Environment::get('MUCHO_ANDROID_BASE_APK', '') ?? ''));
        $filename = 'GeometryDash_2.2.13_MuchoGDPS_Unique_v2.apk';
        $candidates = [];

        if ($configured !== '') {
            $candidates[] = $configured;
        }

        if ($jobDir !== '') {
            $controlRoot = dirname(dirname($jobDir));
            $candidates[] = $controlRoot . '/client-sources/' . $filename;
        }

        $candidates[] = $rootDir . '/storage/client-sources/' . $filename;

        $dir = $rootDir . '/patched/apk';
        $candidates[] = $dir . '/' . $filename;

        foreach (glob($dir . '/*.apk') ?: [] as $path) {
            $candidates[] = (string)$path;
        }

        return array_values(array_unique($candidates));
    }

    public static function manifest(string $jobDir): array
    {
        $path = $jobDir . '/client-pack.json';
        $data = is_file($path)
            ? json_decode((string)file_get_contents($path), true)
            : null;

        if (!is_array($data)) {
            throw new RuntimeException('Client pack has not been prepared yet.');
        }

        return $data;
    }
}
