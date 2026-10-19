<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use MuchoCore\Core\Application;
use MuchoCore\Core\Environment;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Routing\Router;
use MuchoCore\Security\MuchoProtect;

ob_start();

/*
 * Shared-hosting PHP can terminate the request with a fatal error before the
 * normal Throwable handler gets a chance to run (for example memory exhaustion
 * or a startup/parse failure from an autoloaded class). Keep the HTTP endpoint
 * alive and write the exact fatal to the provider's PHP error log instead of
 * exposing a generic server 500 page.
 */
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!is_array($error)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];
    if (!in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $requestId = (string)($_SERVER['MUCHO_REQUEST_ID'] ?? bin2hex(random_bytes(8)));
    $message = (string)($error['message'] ?? 'Unknown fatal error');
    $file = (string)($error['file'] ?? 'unknown');
    $line = (int)($error['line'] ?? 0);

    error_log(sprintf(
        '[MuchoCore Fatal] request=%s %s | %s:%d',
        $requestId,
        $message,
        $file,
        $line
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo '-1';
});

//
// Keep the legacy liveness endpoints independent from the database/application
// bootstrap. Installers, load balancers, and Geometry Dash clients use these
// endpoints to determine whether the HTTP service itself is alive.
//
$__muchoLivenessPath = parse_url(
    (string)($_SERVER['REQUEST_URI'] ?? '/'),
    PHP_URL_PATH
);
$__muchoLivenessPath = is_string($__muchoLivenessPath)
    ? strtolower(rtrim($__muchoLivenessPath, '/'))
    : '/';

if (
    $__muchoLivenessPath === '/health' ||
    $__muchoLivenessPath === '/checkifserveronline'
) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo '1';
    exit;
}

$root = dirname(__DIR__);

try {
    require_once $root . '/vendor/autoload.php';

    if (file_exists($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }
} catch (\Throwable $e) {
    error_log(sprintf(
        '[MuchoCore Config] %s: %s | %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo '-1';
    exit;
}

// Native news images also work when Apache/Caddy sends non-PHP paths through
// this front controller. These bytes must never become a GD "-1" response.
if (preg_match('~^/(?:gd-content/)?news/(mcnews_[a-f0-9]{64}\.jpg)$~D',
    (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''),PHP_URL_PATH),$newsImageMatch)) {
    $_GET['file']=$newsImageMatch[1];
    require __DIR__.'/news/image.php';
    exit;
}

/* MUCHO CONTROL FLAGS */
$__muchoControl = Environment::get('MUCHO_CONTROL_DIR', $root . '/storage/control') ?? ($root . '/storage/control');
$__muchoUri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));

if (!is_dir($__muchoControl)) {
    @mkdir($__muchoControl, 0770, true);
}

if (is_file($__muchoControl . '/maintenance.flag')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo '-1';
    exit;
}

if (
    is_file($__muchoControl . '/registrations-disabled.flag') &&
    str_contains($__muchoUri, 'registergjaccount')
) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo '-1';
    exit;
}
/* END MUCHO CONTROL FLAGS */

$requestId = bin2hex(random_bytes(8));
$_SERVER['MUCHO_REQUEST_ID'] = $requestId;

try {
    $request = Request::fromGlobals();

    $router = new Router();
    $path = strtolower($router->normalizePath($request->path));

    // Apply the unified protection layer before constructing Application/DB state.
    $protection = (new MuchoProtect())->inspect(
        $request,
        $path
    );

    if ($protection['decision'] === 'block') {
        $_SERVER['MUCHO_PROTECT_PRECHECKED'] = '1';
        Response::text('-1')->send();
        exit;
    }

    $_SERVER['MUCHO_PROTECT_PRECHECKED'] = '1';

    (new Application())->run();

} catch (\Throwable $e) {
    error_log(sprintf(
        '[MuchoCore FrontController] %s %s | %s: %s | %s:%d',
        (string)($_SERVER['REQUEST_METHOD'] ?? '?'),
        (string)($_SERVER['REQUEST_URI'] ?? '?'),
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo '-1';
}
