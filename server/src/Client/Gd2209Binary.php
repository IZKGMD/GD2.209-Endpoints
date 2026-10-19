<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use RuntimeException;

/**
 * Exact-build Windows GD 2.209 URL patcher.
 *
 * No code/instruction patching, no binary version spoofing, no PE resizing.
 * Patched origin must be a shorter or equal-length HTTPS hostname.
 */
final class Gd2209Binary
{
    public const SHA256 = '524265b3781596fcc7c9e4d60dc5ac2d0a4cd9530ee3ea637e123f9ac1f9b6a8';

    public static function supports(string $path): bool
    {
        return is_file($path) && hash_file('sha256', $path) === self::SHA256;
    }

    /**
     * @return array<string, mixed> Matches WindowsClientPatcher::patchFile() manifest.
     */
    public static function patchFile(string $inputPath, string $outputPath, string $serverUrl): array
    {
        $server = WindowsClientPatcher::validateServerUrl($serverUrl);
        $parts = parse_url($server);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['port'])) {
            throw new RuntimeException('GD 2.209 patcher requires an HTTPS origin without a port.');
        }
        if (!self::supports($inputPath)) {
            throw new RuntimeException('Unrecognized GD 2.209 EXE build (SHA-256 mismatch).');
        }
        if ($inputPath === $outputPath || realpath($inputPath) === realpath($outputPath)) {
            throw new RuntimeException('Never overwrite the original Geometry Dash executable.');
        }
        $binary = file_get_contents($inputPath);
        if ($binary === false) {
            throw new RuntimeException('Unable to read the Geometry Dash executable.');
        }
        [$data, $count, $paths] = self::patchBytes($binary, (string)$parts['host']);
        if (file_put_contents($outputPath, $data, LOCK_EX) !== strlen($data)) {
            @unlink($outputPath);
            throw new RuntimeException('Unable to save patched GD 2.209 executable.');
        }
        return [
            'server_url' => $server,
            'input_size' => strlen($binary),
            'output_size' => strlen($data),
            'replacements' => ['GD 2.209 server URLs' => $count],
            'replacement_count' => $count,
            'input_sha256' => self::SHA256,
            'output_sha256' => hash('sha256', $data),
            'endpoint_paths' => $paths,
            'verified' => 'static-binary-only',
        ];
    }

    /**
     * Portable core for regression testing with a synthetic PE64 fixture.
     * Input must contain an x64 PE with a .rdata section.
     *
     * @return array{string, int, list<string>}
     */
    public static function patchBytes(string $input, string $targetHost): array
    {
        if (!preg_match('/^[A-Za-z0-9.-]+$/D', $targetHost)) {
            throw new RuntimeException('Invalid target hostname.');
        }
        if (substr($input, 0, 2) !== 'MZ' || strlen($input) < 0x100) {
            throw new RuntimeException('Not a PE executable.');
        }
        $peOffset = unpack('V', substr($input, 0x3c, 4))[1];
        if (substr($input, $peOffset, 4) !== "PE\0\0") {
            throw new RuntimeException('Missing PE signature.');
        }
        if ((unpack('v', substr($input, $peOffset + 4, 2))[1] ?? 0) !== 0x8664) {
            throw new RuntimeException('Expected Windows x64 executable.');
        }
        $sections = unpack('v', substr($input, $peOffset + 6, 2))[1];
        $optionalSize = unpack('v', substr($input, $peOffset + 20, 2))[1];
        $sectionOffset = $peOffset + 24 + $optionalSize;
        $rdataOffset = -1;
        $rdataSize = 0;
        for ($i = 0; $i < $sections; $i++) {
            $offset = $sectionOffset + $i * 40;
            if (substr($input, $offset, 8) !== ".rdata\0\0") {
                continue;
            }
            $rdataSize = unpack('V', substr($input, $offset + 16, 4))[1];
            $rdataOffset = unpack('V', substr($input, $offset + 20, 4))[1];
            break;
        }
        if ($rdataOffset < 0 || $rdataSize < 100 || $rdataOffset + $rdataSize > strlen($input)) {
            throw new RuntimeException('Invalid or missing PE .rdata section.');
        }
        $section = substr($input, $rdataOffset, $rdataSize);
        $regex = '~https?://(?:www\.boomlings\.com|boomlings\.com|www\.geometrydash\.com)/(?:database|databas)/[A-Za-z0-9._/-]+\.php(?=\x00)~';
        $matches = [];
        preg_match_all($regex, $section, $matches, PREG_OFFSET_CAPTURE);
        $count = 0;
        $paths = [];
        foreach ($matches[0] as [$url, $position]) {
            if ($position > 0 && !in_array($section[$position - 1], ["\0", "\n", "\r", ' ', '"', "'"], true)) {
                continue;
            }
            $oldPath = substr($url, strpos($url, '/', strpos($url, '://') + 3));
            if (str_starts_with($oldPath, '/databas/')) {
                $oldPath = '/database/' . substr($oldPath, strlen('/databas/'));
            }
            $patched = 'https://' . $targetHost . $oldPath;
            $oldLength = strlen($url);
            if (strlen($patched) > $oldLength) {
                throw new RuntimeException("GD 2.209 target host too long for {$url}");
            }
            $section = substr_replace(
                $section,
                $patched . str_repeat("\0", $oldLength - strlen($patched)),
                $position,
                $oldLength
            );
            $count++;
            $paths[] = $oldPath;
        }
        if ($count === 0) {
            throw new RuntimeException('No supported GD server endpoints found in PE .rdata.');
        }
        // The original bytes outside .rdata are left untouched.
        $patchedBinary = substr_replace($input, $section, $rdataOffset, $rdataSize);
        if (strlen($patchedBinary) !== strlen($input)) {
            throw new RuntimeException('PE image size mismatch after patch.');
        }
        return [$patchedBinary, $count, array_values(array_unique($paths))];
    }
}
