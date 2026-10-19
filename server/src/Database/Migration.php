<?php

declare(strict_types=1);

namespace MuchoCore\Database;

use PDO;

interface Migration
{
    public function up(PDO $pdo): void;

    public function down(PDO $pdo): void;
}
