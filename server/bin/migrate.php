<?php

declare(strict_types=1);

use MuchoCore\Database\Database;
use MuchoCore\Database\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$database = new Database();

$migrator = new Migrator(
    $database->connection(),
    dirname(__DIR__) . '/database/migrations'
);

$command = $argv[1] ?? 'migrate';

switch ($command) {
    case 'migrate':
        $migrator->migrate();
        break;

    case 'status':
        $migrator->status();
        break;

    default:
        fwrite(
            STDERR,
            "Usage: php bin/migrate.php [migrate|status]\n"
        );

        exit(1);
}
