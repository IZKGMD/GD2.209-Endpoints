<?php
declare(strict_types=1);

/**
 * MuchoCore v7.1 endpoint configuration.
 * Copyright (C) 2026 IZK
 */

$accountUrl = trim((string)(\MuchoCore\Core\Environment::get('MUCHO_ACCOUNT_URL', '') ?? ''));

if ($accountUrl === '') {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    if (
        $host !== '' &&
        preg_match('/^[A-Za-z0-9.-]+(?::[0-9]+)?$/', $host) === 1
    ) {
        $accountUrl = 'https://' . $host;
    }
}

// Optional native 2.209 news host. All other CDN paths are transparently
// proxied to the official asset host under /gd-content/*.
$nativeNewsEnabled = (string)(\MuchoCore\Core\Environment::get('MUCHO_NATIVE_NEWS', '0') ?? '0') === '1';
$nativeNewsContentUrl = $accountUrl !== ''
    ? rtrim($accountUrl, '/') . '/gd-content'
    : '';

return [
    // Use the configured account URL when provided. Otherwise use the host
    // that contacted this server, so every GDPS installation gets its own URL.
    'account_url' => $accountUrl,

    // Keep official content CDN by default; override later for Mucho-hosted music/SFX.
    'custom_content_url' => $nativeNewsEnabled && $nativeNewsContentUrl !== ''
        ? $nativeNewsContentUrl
        : trim((string)(\MuchoCore\Core\Environment::get('MUCHO_CUSTOM_CONTENT_URL', 'https://geometrydashfiles.b-cdn.net') ?? 'https://geometrydashfiles.b-cdn.net')),
];
