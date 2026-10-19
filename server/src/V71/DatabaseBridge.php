<?php
declare(strict_types=1);

namespace MuchoCore\V71;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Additive DB adapter for v7.1.
 * It reuses the current project connection when possible and falls back to .env.
 */
final class DatabaseBridge
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $root = dirname(__DIR__, 2);

        $vendor = $root . '/vendor/autoload.php';
        if (is_file($vendor)) {
            require_once $vendor;
        }

        // Prefer the canonical MuchoCore connection so Docker runtime credentials
        // from /var/lib/muchocore/runtime.env are loaded consistently.
        if (class_exists('MuchoCore\\Database\\Database')) {
            try {
                $canonical = new \MuchoCore\Database\Database();
                return self::$pdo = self::normalize($canonical->connection());
            } catch (Throwable) {
                // Fall through to legacy adapters.
            }
        }

        // Fall back to legacy/project connection adapters only when the
        // canonical MuchoCore connection is unavailable.
        // Prefer the existing project/Cvolton connection so v7.1 does not invent a second DB config.
        $candidates = [
            $root . '/config/connection.php',
            $root . '/incl/lib/connection.php',
            $root . '/src/Core/connection.php',
            $root . '/src/Database/connection.php',
        ];

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }

            try {
                $conn = (static function (string $__file): ?PDO {
                    $db = null;
                    $pdo = null;
                    require $__file;

                    if ($pdo instanceof PDO) {
                        return $pdo;
                    }
                    if ($db instanceof PDO) {
                        return $db;
                    }
                    return null;
                })($file);

                if ($conn instanceof PDO) {
                    return self::$pdo = self::normalize($conn);
                }
            } catch (Throwable) {
                // Try the next adapter.
            }
        }

        // Try already-loaded database classes without coupling v7.1 to a concrete implementation.
        foreach ([
            'Mucho\\Core\\Database',
            'Mucho\\Database\\Database',
            'App\\Core\\Database',
            'App\\Database\\Database',
        ] as $class) {
            if (!class_exists($class)) {
                continue;
            }

            foreach (['pdo', 'connection', 'getConnection', 'instance'] as $method) {
                if (!method_exists($class, $method)) {
                    continue;
                }

                try {
                    $value = $class::$method();
                    if ($value instanceof PDO) {
                        return self::$pdo = self::normalize($value);
                    }
                    if (is_object($value)) {
                        foreach (['pdo', 'connection', 'getConnection'] as $nested) {
                            if (method_exists($value, $nested)) {
                                $nestedValue = $value->$nested();
                                if ($nestedValue instanceof PDO) {
                                    return self::$pdo = self::normalize($nestedValue);
                                }
                            }
                        }
                    }
                } catch (Throwable) {
                    // Continue to the next convention.
                }
            }
        }

        $env = self::loadEnv($root . '/.env');
        foreach ([
            $root . '/.env.local',
            $root . '/config/.env',
            '/var/lib/muchocore/runtime.env'
        ] as $extraEnv) {
            $env = array_merge($env, self::loadEnv($extraEnv));
        }

        $dsn = self::pick($env, ['DB_DSN']);
        $user = self::pick($env, ['DB_USERNAME', 'DB_USER', 'MYSQL_USER']) ?? '';
        $pass = self::pick($env, ['DB_PASSWORD', 'DB_PASS', 'MYSQL_PASSWORD']) ?? '';

        if (!$dsn) {
            $host = self::pick($env, ['DB_HOST', 'MYSQL_HOST']) ?? '127.0.0.1';
            $port = self::pick($env, ['DB_PORT', 'MYSQL_PORT']) ?? '3306';
            $name = self::pick($env, ['DB_DATABASE', 'DB_NAME', 'MYSQL_DATABASE']);

            if (!$name) {
                throw new RuntimeException(
                    'Unable to resolve MuchoCore PDO connection. ' .
                    'Expected existing config/connection.php, incl/lib/connection.php, a known Database class, or .env DB_* values.'
                );
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }

        return self::$pdo = self::normalize(new PDO($dsn, $user, $pass));
    }

    private static function normalize(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return $pdo;
    }

    /** @return array<string,string> */
    private static function loadEnv(string $file): array
    {
        $result = [];

        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                $result[$key] = $value;
            }
        }

        // Process-level environment variables are a fallback only. On shared
        // hosting, providers may expose generic DB_* variables that belong to
        // another application. MuchoCore's own config files must win.
        foreach (array_keys($_ENV) as $key) {
            if (array_key_exists((string)$key, $result)) {
                continue;
            }
            $value = getenv((string)$key);
            if ($value !== false) {
                $result[(string)$key] = $value;
            }
        }

        foreach (array_keys($_SERVER) as $key) {
            if (array_key_exists((string)$key, $result)) {
                continue;
            }
            $value = getenv((string)$key);
            if ($value !== false) {
                $result[(string)$key] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,string> $env */
    private static function pick(array $env, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($env[$key]) && $env[$key] !== '') {
                return $env[$key];
            }
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                return $value;
            }
        }
        return null;
    }
}
