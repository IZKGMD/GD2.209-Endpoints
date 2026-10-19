<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;
use ZipArchive;

final class AndroidClientPatcher
{
    private const SEGMENTS = [
        'a',
        'api',
        'database',
        'accounts',
    ];

    private const KNOWN_HOSTS = [
        'c92935bj.beget.tech',
        'www.boomlings.com',
        'boomlings.com',
        'www.geometrydash.com',
        'geometrydash.com',
        'www.gdserver.net',
        'gdserver.net',
        'muchogdps.space',
        'gdpsapi.page.gd',
    ];

    public static function patchFile(
        string $inputPath,
        string $outputPath,
        string $serverUrl,
        ?callable $heartbeat = null
    ): array {
        $server = WindowsClientPatcher::validateServerUrl($serverUrl);

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'PHP ZipArchive is required for the Android APK patcher.'
            );
        }

        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException('Uploaded APK cannot be read.');
        }

        $size = filesize($inputPath);
        if ($size === false || $size < 1024) {
            throw new RuntimeException(
                'Uploaded file is too small to be a valid Android APK.'
            );
        }

        if ($outputPath === $inputPath) {
            throw new RuntimeException('Output path must be different from input path.');
        }

        $source = new ZipArchive();
        $openResult = $source->open($inputPath);
        if ($openResult !== true) {
            throw new RuntimeException('The uploaded file is not a readable APK/ZIP archive.');
        }

        $hasManifest = false;
        $replacements = self::buildReplacements($server);
        $replacementCount = 0;
        $patchedEntries = [];
        $lastHeartbeat = microtime(true);

        $heartbeatNow = static function () use (&$lastHeartbeat, $heartbeat): void {
            if ($heartbeat === null) {
                return;
            }
            $now = microtime(true);
            if (($now - $lastHeartbeat) >= 5.0) {
                $heartbeat();
                $lastHeartbeat = $now;
            }
        };
        $heartbeatNow();

        $rebuiltPath = $outputPath . '.rebuilt';
        $alignedPath = $outputPath . '.aligned';

        @unlink($rebuiltPath);
        @unlink($alignedPath);
        @unlink($outputPath);

        $out = new ZipArchive();
        if ($out->open($rebuiltPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();
            throw new RuntimeException('Cannot create the rebuilt APK output file.');
        }

        for ($index = 0; $index < $source->numFiles; $index++) {
            $heartbeatNow();
            $stat = $source->statIndex($index);
            $name = is_array($stat) ? (string)($stat['name'] ?? '') : '';

            if ($name === '') {
                continue;
            }

            if ($name === 'AndroidManifest.xml') {
                $hasManifest = true;
            }

            if (self::isSignatureEntry($name)) {
                continue;
            }

            if (str_ends_with($name, '/')) {
                $out->addEmptyDir($name);
                continue;
            }

            $data = $source->getFromIndex($index);
            if ($data === false) {
                $out->close();
                $source->close();
                @unlink($rebuiltPath);
                throw new RuntimeException('Failed to read APK entry: ' . $name);
            }

            if (self::shouldPatchEntry($name)) {
                $count = 0;
                $data = self::replaceBuffer($data, $replacements, $count);
                $hostCount = 0;
                $data = self::replaceKnownHosts(
                    $data,
                    $server,
                    $hostCount
                );
                if ($count > 0 || $hostCount > 0) {
                    $replacementCount += $count + $hostCount;
                    $patchedEntries[] = [
                        'name' => $name,
                        'replacements' => $count + $hostCount,
                    ];
                }
            }

            if ($out->addFromString($name, $data) === false) {
                $out->close();
                $source->close();
                @unlink($rebuiltPath);
                throw new RuntimeException('Failed to write APK entry: ' . $name);
            }

            $method = (int)($stat['comp_method'] ?? -1);
            if (in_array($method, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                $out->setCompressionName($name, $method);
            }

            unset($data);
        }

        $source->close();

        if (!$hasManifest) {
            $out->close();
            @unlink($rebuiltPath);
            throw new RuntimeException('The uploaded archive does not contain AndroidManifest.xml.');
        }

        if ($replacementCount <= 0) {
            $out->close();
            @unlink($rebuiltPath);
            throw new RuntimeException(
                'No supported Geometry Dash server URL was found in the APK.'
            );
        }

        if (!$out->close()) {
            @unlink($rebuiltPath);
            throw new RuntimeException('Failed to finalize the rebuilt APK.');
        }

        $heartbeatNow();
        self::runTool(
            ['zipalign', '-p', '-f', '4', $rebuiltPath, $alignedPath],
            'zipalign'
        );

        $heartbeatNow();
        self::signApk($alignedPath, $outputPath);
        $heartbeatNow();

        @unlink($rebuiltPath);
        @unlink($alignedPath);

        $outputSize = filesize($outputPath);
        if ($outputSize === false || $outputSize < 1024) {
            @unlink($outputPath);
            throw new RuntimeException('Signed APK output is invalid.');
        }

        self::verifyApk($outputPath);

        return [
            'server_url' => $server,
            'input_size' => $size,
            'output_size' => $outputSize,
            'replacement_count' => $replacementCount,
            'patched_entries' => $patchedEntries,
            'input_sha256' => hash_file('sha256', $inputPath) ?: '',
            'output_sha256' => hash_file('sha256', $outputPath) ?: '',
            'signed' => true,
        ];
    }

    private static function runTool(array $arguments, string $label): void
    {
        $binary = (string)array_shift($arguments);

        $command = $binary;
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg((string)$argument);
        }
        $command .= ' 2>&1';

        $output = [];
        $code = 0;
        exec($command, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException(
                $label . ' failed: ' . trim(implode("\n", $output))
            );
        }
    }

    private static function signerDirectory(): string
    {
        $configured = trim(
            (string)(
                getenv('MUCHO_ANDROID_SIGNER_DIR')
                ?: '/var/lib/muchocore/android-signer'
            )
        );

        if ($configured === '') {
            throw new RuntimeException('Android signer directory is not configured.');
        }

        if (
            (!is_dir($configured) && !@mkdir($configured, 0700, true)) ||
            !is_dir($configured)
        ) {
            throw new RuntimeException('Cannot create Android signer directory.');
        }

        @chmod($configured, 0700);

        return $configured;
    }

    /**
     * apksigner expects --key to be an unencrypted PKCS#8 key.
     * Android's own documentation uses a DER-encoded key.pk8 for this input.
     */
    private static function ensureSigner(): array
    {
        $dir = self::signerDirectory();
        $key = $dir . '/muchocore-android.key.pk8';
        $legacyKey = $dir . '/muchocore-android.key.pem';
        $cert = $dir . '/muchocore-android.cert.pem';

        if (!is_file($key)) {
            if (is_file($legacyKey)) {
                if (!is_file($cert)) {
                    self::runTool(
                        [
                            'openssl',
                            'req',
                            '-new',
                            '-x509',
                            '-sha256',
                            '-key',
                            $legacyKey,
                            '-out',
                            $cert,
                            '-days',
                            '10000',
                            '-subj',
                            '/CN=MuchoCore Android/O=MuchoCore/C=US',
                        ],
                        'Android signer certificate generation'
                    );
                }

                self::runTool(
                    [
                        'openssl',
                        'pkcs8',
                        '-topk8',
                        '-nocrypt',
                        '-inform',
                        'PEM',
                        '-outform',
                        'DER',
                        '-in',
                        $legacyKey,
                        '-out',
                        $key,
                    ],
                    'Android signer PKCS#8 migration'
                );

                @unlink($legacyKey);
            } else {
                $pemKey = $key . '.pem';

                self::runTool(
                    [
                        'openssl',
                        'genpkey',
                        '-algorithm',
                        'RSA',
                        '-pkeyopt',
                        'rsa_keygen_bits:2048',
                        '-out',
                        $pemKey,
                    ],
                    'Android signer key generation'
                );

                self::runTool(
                    [
                        'openssl',
                        'req',
                        '-new',
                        '-x509',
                        '-sha256',
                        '-key',
                        $pemKey,
                        '-out',
                        $cert,
                        '-days',
                        '10000',
                        '-subj',
                        '/CN=MuchoCore Android/O=MuchoCore/C=US',
                    ],
                    'Android signer certificate generation'
                );

                self::runTool(
                    [
                        'openssl',
                        'pkcs8',
                        '-topk8',
                        '-nocrypt',
                        '-inform',
                        'PEM',
                        '-outform',
                        'DER',
                        '-in',
                        $pemKey,
                        '-out',
                        $key,
                    ],
                    'Android signer PKCS#8 key generation'
                );

                @unlink($pemKey);
            }
        } elseif (!is_file($cert)) {
            $pemKey = $key . '.pem';

            self::runTool(
                [
                    'openssl',
                    'pkcs8',
                    '-inform',
                    'DER',
                    '-nocrypt',
                    '-in',
                    $key,
                    '-out',
                    $pemKey,
                ],
                'Android signer PEM conversion'
            );

            self::runTool(
                [
                    'openssl',
                    'req',
                    '-new',
                    '-x509',
                    '-sha256',
                    '-key',
                    $pemKey,
                    '-out',
                    $cert,
                    '-days',
                    '10000',
                    '-subj',
                    '/CN=MuchoCore Android/O=MuchoCore/C=US',
                ],
                'Android signer certificate generation'
            );

            @unlink($pemKey);
        }

        self::runTool(
            [
                'openssl',
                'pkcs8',
                '-inform',
                'DER',
                '-nocrypt',
                '-in',
                $key,
                '-out',
                '/dev/null',
            ],
            'Android signer PKCS#8 validation'
        );

        if (!is_readable($key) || !is_readable($cert)) {
            throw new RuntimeException('Android signing credentials are not readable.');
        }

        return [
            'key' => $key,
            'cert' => $cert,
        ];
    }

    private static function signApk(string $inputPath, string $outputPath): void
    {
        $signer = self::ensureSigner();

        self::runTool(
            [
                'apksigner',
                'sign',
                '--min-sdk-version',
                '7',
                '--v1-signing-enabled',
                'true',
                '--v2-signing-enabled',
                'true',
                '--v3-signing-enabled',
                'true',
                '--v4-signing-enabled',
                'false',
                '--key',
                $signer['key'],
                '--cert',
                $signer['cert'],
                '--out',
                $outputPath,
                $inputPath,
            ],
            'APK signing'
        );
    }

    private static function verifyApk(string $path): void
    {
        self::runTool(
            [
                'apksigner',
                'verify',
                '--verbose',
                $path,
            ],
            'APK signature verification'
        );

        $verify = new ZipArchive();
        if ($verify->open($path) !== true) {
            throw new RuntimeException(
                'Signed APK could not be reopened after signing.'
            );
        }

        if ($verify->locateName('AndroidManifest.xml') === false) {
            $verify->close();
            throw new RuntimeException(
                'Signed APK is missing AndroidManifest.xml.'
            );
        }

        $verify->close();
    }

    private static function shouldPatchEntry(string $name): bool
    {
        $lower = strtolower($name);

        return str_starts_with($lower, 'lib/')
            || str_starts_with($lower, 'assets/')
            || preg_match('~(^|/)classes(?:[0-9]+)?\.dex$~', $lower) === 1
            || preg_match('~\.(json|xml|txt|ini|cfg|dat)$~', $lower) === 1;
    }

    private static function isSignatureEntry(string $name): bool
    {
        $upper = strtoupper($name);

        return str_starts_with($upper, 'META-INF/')
            && (
                $upper === 'META-INF/MANIFEST.MF'
                || str_ends_with($upper, '.SF')
                || str_ends_with($upper, '.RSA')
                || str_ends_with($upper, '.DSA')
                || str_ends_with($upper, '.EC')
            );
    }

    /**
     * @return array<string,string>
     */
    private static function buildReplacements(string $server): array
    {
        $parsed = parse_url($server);
        if (!is_array($parsed)) {
            throw new RuntimeException('Invalid server URL.');
        }

        $base = (string)$parsed['scheme'] . '://' .
            (string)$parsed['host'] .
            (isset($parsed['port']) ? ':' . (int)$parsed['port'] : '');

        $forms = [
            'https://www.boomlings.com/database' => 34,
            'http://www.boomlings.com/database' => 33,
            'https://www.boomlings.com/' => 26,
            'http://www.boomlings.com/' => 25,
            'www.boomlings.com/database' => 26,
        ];

        foreach (self::KNOWN_HOSTS as $host) {
            if ($host === 'www.boomlings.com') {
                continue;
            }

            $forms['https://' . $host . '/database'] = null;
            $forms['http://' . $host . '/database'] = null;
        }

        $map = [];

        foreach ($forms as $old => $fixedLength) {
            $length = $fixedLength ?? strlen($old);

            /*
             * Android 9+ blocks cleartext HTTP for applications that do not
             * explicitly opt in. A modern 2.2 client should therefore keep
             * HTTPS whenever the configured GDPS address uses HTTPS, even
             * when the original fixed-size field was the older HTTP form.
             *
             * The compatibility URL builder can still produce a same-length
             * HTTPS value for the fixed-size fields by adding path segments.
             */
            $targetBase = $base;

            $new = self::compatibleUrl($targetBase, $length, false);

            if ($new === null || strlen($old) !== strlen($new)) {
                continue;
            }

            $map[$old] = $new;
            $map[base64_encode($old)] = base64_encode($new);
            $map[self::asciiToUtf16Le($old)] = self::asciiToUtf16Le($new);
        }

        $bareNew = self::compatibleUrl($base, 26, true);
        if ($bareNew !== null) {
            $old = 'www.boomlings.com/database';
            $map[$old] = $bareNew;
            $map[base64_encode($old)] = base64_encode($bareNew);
        }

        if ($map === []) {
            throw new RuntimeException(
                'The supplied server URL is too long for the supported APK URL layouts.'
            );
        }

        return $map;
    }

    private static function replaceKnownHosts(
        string $data,
        string $server,
        int &$count
    ): string {
        $count = 0;
        $parsed = parse_url($server);

        if (!is_array($parsed) || !isset($parsed['host'])) {
            return $data;
        }

        $targetHost = (string)$parsed['host'];

        foreach (self::KNOWN_HOSTS as $oldHost) {
            if ($oldHost === $targetHost || strlen($targetHost) > strlen($oldHost)) {
                continue;
            }

            $count += self::replaceNullTerminatedUrlHost($data, $oldHost, $targetHost);
            $count += self::replaceNullTerminatedUtf16Host($data, $oldHost, $targetHost);
            $count += self::replaceBase64EmbeddedHost($data, $oldHost, $targetHost);
        }

        $prefix = strtolower((string)$parsed['scheme']) . '://' . $targetHost;
        $oldMalformed = $prefix . '/databas/checkIfServerOnline.php' . "\0\0";
        $newMalformed = $prefix . '/database/checkIfServerOnline.php' . "\0";
        $matches = substr_count($data, $oldMalformed);
        if ($matches > 0) {
            $data = str_replace($oldMalformed, $newMalformed, $data);
            $count += $matches;
        }

        return $data;
    }

    private static function replaceNullTerminatedUrlHost(
        string &$data,
        string $oldHost,
        string $newHost
    ): int {
        $count = 0;
        $offset = 0;

        while (($position = strpos($data, $oldHost, $offset)) !== false) {
            $start = strrpos(substr($data, 0, $position), "\0");
            $start = $start === false ? 0 : $start + 1;
            $end = strpos($data, "\0", $position);

            if ($end === false) {
                $end = strlen($data);
            }

            $prefix = substr($data, $start, $position - $start);
            if (!str_ends_with($prefix, 'http://') && !str_ends_with($prefix, 'https://')) {
                $offset = $position + strlen($oldHost);
                continue;
            }

            $url = substr($data, $start, $end - $start);
            $patched = substr_replace($url, $newHost, $position - $start, strlen($oldHost));
            if (strlen($patched) > strlen($url)) {
                $offset = $position + strlen($oldHost);
                continue;
            }

            $patched .= str_repeat("\0", strlen($url) - strlen($patched));
            $data = substr_replace($data, $patched, $start, $end - $start);
            $count++;
            $offset = $start + strlen($patched);
        }

        return $count;
    }

    private static function replaceNullTerminatedUtf16Host(
        string &$data,
        string $oldHost,
        string $newHost
    ): int {
        $old = self::asciiToUtf16Le($oldHost);
        $new = self::asciiToUtf16Le($newHost);
        $http = self::asciiToUtf16Le('http://');
        $https = self::asciiToUtf16Le('https://');
        $count = 0;
        $offset = 0;

        while (($position = strpos($data, $old, $offset)) !== false) {
            $start = null;

            if (
                $position >= strlen($https) &&
                substr($data, $position - strlen($https), strlen($https)) === $https
            ) {
                $start = $position - strlen($https);
            } elseif (
                $position >= strlen($http) &&
                substr($data, $position - strlen($http), strlen($http)) === $http
            ) {
                $start = $position - strlen($http);
            }

            if ($start === null) {
                $offset = $position + strlen($old);
                continue;
            }

            $end = self::findUtf16LeTerminator($data, $start);
            if ($end === null || $end < $position + strlen($old)) {
                $offset = $position + strlen($old);
                continue;
            }

            $url = substr($data, $start, $end - $start);
            $patched = substr_replace(
                $url,
                $new,
                $position - $start,
                strlen($old)
            );

            if (strlen($patched) > strlen($url)) {
                $offset = $position + strlen($old);
                continue;
            }

            $patched .= str_repeat("\0", strlen($url) - strlen($patched));
            $data = substr_replace($data, $patched, $start, $end - $start);
            $count++;
            $offset = $start + strlen($patched) + 2;
        }

        return $count;
    }

    private static function findUtf16LeTerminator(
        string $data,
        int $start
    ): ?int {
        $length = strlen($data);

        for ($position = $start; $position + 1 < $length; $position += 2) {
            if ($data[$position] === "\0" && $data[$position + 1] === "\0") {
                return $position;
            }
        }

        return null;
    }

    private static function replaceBase64EmbeddedHost(
        string &$data,
        string $oldHost,
        string $newHost
    ): int {
        $count = 0;
        $cursor = 0;
        $pattern = '~[A-Za-z0-9+/]{24,}={0,2}~';

        while (preg_match($pattern, $data, $match, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $raw = (string)$match[0][0];
            $offset = (int)$match[0][1];
            $decoded = base64_decode($raw, true);

            if (
                $decoded === false ||
                (!str_starts_with($decoded, 'http://') && !str_starts_with($decoded, 'https://')) ||
                !str_contains($decoded, $oldHost)
            ) {
                $cursor = $offset + strlen($raw);
                continue;
            }

            $patchedDecoded = str_replace($oldHost, $newHost, $decoded, $replaced);
            $encoded = base64_encode($patchedDecoded);

            if ($replaced > 0 && strlen($encoded) <= strlen($raw)) {
                $data = substr_replace(
                    $data,
                    $encoded . str_repeat("\0", strlen($raw) - strlen($encoded)),
                    $offset,
                    strlen($raw)
                );
                $count += $replaced;
                $cursor = $offset + strlen($encoded);
            } else {
                $cursor = $offset + strlen($raw);
            }
        }

        return $count;
    }

    private static function asciiToUtf16Le(string $value): string
    {
        $result = '';

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $result .= $value[$i] . "\x00";
        }

        return $result;
    }

    private static function compatibleUrl(
        string $server,
        int $desiredLength,
        bool $bare
    ): ?string {
        $parsed = parse_url($server);
        if (!is_array($parsed)) {
            return null;
        }

        $scheme = (string)$parsed['scheme'];
        $netloc = (string)$parsed['host'] .
            (isset($parsed['port']) ? ':' . (int)$parsed['port'] : '');

        $queue = [[]];
        $seen = [''];

        while ($queue !== []) {
            $parts = array_shift($queue);
            $suffix = $parts === [] ? '' : '/' . implode('/', $parts);

            $candidate = $bare
                ? $netloc . $suffix . '/database'
                : $scheme . '://' . $netloc . $suffix;

            if (strlen($candidate) === $desiredLength) {
                return $candidate;
            }

            if (count($parts) >= 6) {
                continue;
            }

            foreach (self::SEGMENTS as $segment) {
                if ($bare && $segment === 'database') {
                    continue;
                }

                $next = $parts;
                $next[] = $segment;
                $key = implode('/', $next);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $queue[] = $next;
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $replacements
     */
    private static function replaceBuffer(
        string $buffer,
        array $replacements,
        int &$count
    ): string {
        $count = 0;

        foreach ($replacements as $old => $new) {
            $matches = substr_count($buffer, $old);
            if ($matches > 0) {
                $buffer = str_replace($old, $new, $buffer);
                $count += $matches;
            }
        }

        return $buffer;
    }
}
