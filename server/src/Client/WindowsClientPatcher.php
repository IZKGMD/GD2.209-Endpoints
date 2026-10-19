<?php

declare(strict_types=1);

namespace MuchoCore\Client;

require_once __DIR__ . '/Gd2209Binary.php';

use RuntimeException;

final class WindowsClientPatcher
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

    public static function validateServerUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException('Server URL is required.');
        }

        if (!str_contains($value, '://')) {
            $value = 'https://' . $value;
        }

        $value = rtrim($value, '/');
        $parsed = parse_url($value);

        if (!is_array($parsed)) {
            throw new RuntimeException('Invalid server URL.');
        }

        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        $host = (string)($parsed['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Server URL must use http:// or https://.');
        }

        if ($host === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new RuntimeException('Server URL must contain a valid hostname.');
        }

        if (
            isset($parsed['user']) ||
            isset($parsed['pass']) ||
            isset($parsed['query']) ||
            isset($parsed['fragment']) ||
            isset($parsed['path']) && $parsed['path'] !== ''
        ) {
            throw new RuntimeException(
                'Enter the server root only, for example https://gdps.example.com.'
            );
        }

        $port = isset($parsed['port']) ? ':' . (int)$parsed['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    public static function patchFile(
        string $inputPath,
        string $outputPath,
        string $serverUrl
    ): array {
        $server = self::validateServerUrl($serverUrl);

        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException('Uploaded executable cannot be read.');
        }

        $size = filesize($inputPath);
        if ($size === false || $size < 1024) {
            throw new RuntimeException('Uploaded file is too small to be a Geometry Dash executable.');
        }

        $head = file_get_contents($inputPath, false, null, 0, 512);
        if (!is_string($head) || !self::isPeFileHeader($head)) {
            throw new RuntimeException('The uploaded file is not a valid Windows PE executable.');
        }

        if ($outputPath === $inputPath) {
            throw new RuntimeException('Output path must be different from input path.');
        }

        /*
         * GD 2.209 uses a larger endpoint family, including notifications,
         * follows, editor sounds, threads and death data. Patch complete
         * NUL-terminated URLs in .rdata; never patch code or guess offsets.
         * Keep the legacy patcher for other known client versions.
         */
        if (Gd2209Binary::supports($inputPath)) {
            return Gd2209Binary::patchFile($inputPath, $outputPath, $server);
        }

        $replacements = self::buildReplacements($server);

        if ($replacements === []) {
            throw new RuntimeException(
                'The supplied server URL is too long for the supported fixed-size client URL fields.'
            );
        }

        $stats = [];
        self::streamReplaceFixed(
            $inputPath,
            $outputPath,
            $replacements,
            $stats
        );

        if (filesize($outputPath) !== $size) {
            @unlink($outputPath);
            throw new RuntimeException('Patched executable size changed unexpectedly.');
        }

        self::patchKnownHostsInFile($outputPath, $server, $stats);

        if (!self::fileContainsAny($outputPath, [
            parse_url($server, PHP_URL_HOST) ?: '',
        ])) {
            @unlink($outputPath);
            throw new RuntimeException('The target server hostname was not found in the patched executable.');
        }

        $totalReplacements = array_sum($stats);

        if ($totalReplacements <= 0) {
            @unlink($outputPath);
            throw new RuntimeException(
                'No supported Geometry Dash server URL was found in this executable.'
            );
        }

        return [
            'server_url' => $server,
            'input_size' => $size,
            'output_size' => filesize($outputPath) ?: $size,
            'replacements' => $stats,
            'replacement_count' => $totalReplacements,
            'input_sha256' => hash_file('sha256', $inputPath) ?: '',
            'output_sha256' => hash_file('sha256', $outputPath) ?: '',
        ];
    }

    private static function isPeFileHeader(string $head): bool
    {
        if (strlen($head) < 0x40 || substr($head, 0, 2) !== 'MZ') {
            return false;
        }

        $peOffset = unpack('V', substr($head, 0x3c, 4))[1] ?? -1;

        return $peOffset >= 0x40 &&
            $peOffset + 4 <= strlen($head) &&
            substr($head, $peOffset, 4) === "PE\0\0";
    }

    /**
     * @return array<string,string>
     */
    private static function buildReplacements(string $server): array
    {
        $parsed = parse_url($server);
        $base = is_array($parsed)
            ? ((string)$parsed['scheme'] . '://' . (string)$parsed['host'] .
                (isset($parsed['port']) ? ':' . (int)$parsed['port'] : ''))
            : $server;

        $map = [];

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

        foreach ($forms as $old => $fixedLength) {
            $length = $fixedLength ?? strlen($old);

            if ($fixedLength === null) {
                $length = strlen($old);
            }

            /*
             * Preserve the scheme used by the original client URL.
             * Older Geometry Dash binaries commonly use plain HTTP; forcing
             * those fixed-length fields to HTTPS can introduce TLS failures
             * unrelated to MuchoCore protocol compatibility.
             */
            $oldParsed = parse_url($old);
            $targetBase = $base;

            if (
                is_array($oldParsed) &&
                isset($oldParsed['scheme'])
            ) {
                $targetBase =
                    strtolower((string)$oldParsed['scheme']) .
                    '://' .
                    (string)$parsed['host'] .
                    (isset($parsed['port']) ? ':' . (int)$parsed['port'] : '');
            }

            $new = self::compatibleUrl($targetBase, $length, false);

            if ($new !== null) {
                self::addReplacement($map, $old, $new);
                self::addReplacement(
                    $map,
                    base64_encode($old),
                    base64_encode($new)
                );
                self::addReplacement(
                    $map,
                    self::asciiToUtf16Le($old),
                    self::asciiToUtf16Le($new)
                );
            }
        }

        $bareNew = self::compatibleUrl($base, 26, true);
        if ($bareNew !== null) {
            self::addReplacement(
                $map,
                'www.boomlings.com/database',
                $bareNew
            );
            self::addReplacement(
                $map,
                base64_encode('www.boomlings.com/database'),
                base64_encode($bareNew)
            );
        }

        return $map;
    }

    private static function patchKnownHostsInFile(
        string $path,
        string $server,
        array &$stats
    ): void {
        $parsed = parse_url($server);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return;
        }

        $targetHost = (string)$parsed['host'];

        $data = @file_get_contents($path);
        if (!is_string($data)) {
            throw new RuntimeException('Cannot inspect patched executable for embedded server hosts.');
        }

        foreach (self::KNOWN_HOSTS as $oldHost) {
            if ($oldHost === $targetHost || strlen($targetHost) > strlen($oldHost)) {
                continue;
            }

            $count = self::replaceNullTerminatedUrlHost(
                $data,
                $oldHost,
                $targetHost
            );
            if ($count > 0) {
                $stats['Known host ' . $oldHost] =
                    ($stats['Known host ' . $oldHost] ?? 0) + $count;
            }

            $count = self::replaceNullTerminatedUtf16Host(
                $data,
                $oldHost,
                $targetHost
            );
            if ($count > 0) {
                $stats['Known host [UTF-16] ' . $oldHost] =
                    ($stats['Known host [UTF-16] ' . $oldHost] ?? 0) + $count;
            }

            $count = self::replaceBase64EmbeddedHost(
                $data,
                $oldHost,
                $targetHost
            );
            if ($count > 0) {
                $stats['Known host [Base64] ' . $oldHost] =
                    ($stats['Known host [Base64] ' . $oldHost] ?? 0) + $count;
            }
        }

        $parsedPrefix = strtolower((string)$parsed['scheme']) . '://' . $targetHost;
        $oldMalformed = $parsedPrefix . '/databas/checkIfServerOnline.php' . "\0\0";
        $newMalformed = $parsedPrefix . '/database/checkIfServerOnline.php' . "\0";

        $malformedCount = substr_count($data, $oldMalformed);
        if ($malformedCount > 0) {
            $data = str_replace($oldMalformed, $newMalformed, $data);
            $stats['Repair malformed checkIfServerOnline path'] =
                ($stats['Repair malformed checkIfServerOnline path'] ?? 0) +
                $malformedCount;
        }

        if (@file_put_contents($path, $data, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the patched executable after embedded-host patching.');
        }
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
            /*
             * Do not search backwards for "\0\0": a UTF-16LE ASCII string
             * contains NUL bytes in every code unit and binary data before the
             * string may contain arbitrary double-NUL sequences. Anchor the
             * URL from the scheme immediately preceding the host instead.
             */
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

            /*
             * Keep the PE byte layout fixed. If the new host is shorter, the
             * path shifts left and the remaining UTF-16 code units become the
             * string terminator/padding at the end, never in the middle.
             */
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

    private static function addReplacement(
        array &$map,
        string $old,
        string $new
    ): void {
        if ($old === '' || strlen($old) !== strlen($new)) {
            return;
        }

        $map[$old] = $new;
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
     * @param array<string,int> $stats
     */
    private static function streamReplaceFixed(
        string $input,
        string $output,
        array $replacements,
        array &$stats
    ): void {
        $in = fopen($input, 'rb');
        $out = fopen($output, 'wb');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            throw new RuntimeException('Cannot open executable for patching.');
        }

        $maxLength = 1;
        foreach ($replacements as $old => $new) {
            $maxLength = max($maxLength, strlen($old), strlen($new));
            $stats[$new] = 0;
        }

        $buffer = '';
        $chunkSize = 4 * 1024 * 1024;
        $tailLength = max(0, $maxLength - 1);

        while (!feof($in)) {
            $piece = fread($in, $chunkSize);
            if ($piece === false) {
                fclose($in);
                fclose($out);
                @unlink($output);
                throw new RuntimeException('Failed while reading the executable.');
            }

            $buffer .= $piece;

            if (strlen($buffer) <= $tailLength) {
                continue;
            }

            $processLength = strlen($buffer) - $tailLength;
            $process = substr($buffer, 0, $processLength);
            $buffer = substr($buffer, $processLength);

            $process = self::replaceBuffer($process, $replacements, $stats);

            if (fwrite($out, $process) === false) {
                fclose($in);
                fclose($out);
                @unlink($output);
                throw new RuntimeException('Failed while writing the patched executable.');
            }
        }

        if ($buffer !== '') {
            $buffer = self::replaceBuffer($buffer, $replacements, $stats);
            if (fwrite($out, $buffer) === false) {
                fclose($in);
                fclose($out);
                @unlink($output);
                throw new RuntimeException('Failed while finalizing the patched executable.');
            }
        }

        fclose($in);
        fclose($out);
    }

    private static function replaceBuffer(
        string $buffer,
        array $replacements,
        array &$stats
    ): string {
        foreach ($replacements as $old => $new) {
            $count = substr_count($buffer, $old);

            if ($count > 0) {
                $buffer = str_replace($old, $new, $buffer);
                $stats[$new] += $count;
            }
        }

        return $buffer;
    }

    private static function fileContainsAny(
        string $path,
        array $needles
    ): bool {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $maxLength = 1;
        foreach ($needles as $needle) {
            $maxLength = max($maxLength, strlen($needle));
        }

        $tail = '';
        while (!feof($handle)) {
            $piece = fread($handle, 4 * 1024 * 1024);
            if ($piece === false) {
                fclose($handle);
                return false;
            }

            $data = $tail . $piece;
            foreach ($needles as $needle) {
                if (str_contains($data, $needle)) {
                    fclose($handle);
                    return true;
                }
            }

            $tail = substr($data, -($maxLength - 1));
        }

        fclose($handle);
        return false;
    }
}
