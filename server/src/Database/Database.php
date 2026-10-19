<?php

declare(strict_types=1);

namespace MuchoCore\Database;

use MuchoCore\Core\Environment;
use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct()
    {
        // Environment is the single configuration source for database values.
        // On Docker/VPS installs it transparently reads the protected runtime
        // environment first; on shared hosting it uses the installation's
        // local .env and never touches Docker-only paths.
        $host = (string)(Environment::get('DB_HOST', '127.0.0.1') ?? '127.0.0.1');
        $port = (string)(Environment::get('DB_PORT', '3306') ?? '3306');
        $name = (string)(Environment::get('DB_NAME', '') ?? '');
        $user = (string)(Environment::get('DB_USER', '') ?? '');
        $pass = (string)(Environment::get('DB_PASS', '') ?? '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    public function connection(): PDO
    {
        return $this->pdo;
    }
}
