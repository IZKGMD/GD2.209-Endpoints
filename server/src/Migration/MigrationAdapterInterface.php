<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

interface MigrationAdapterInterface
{
    public function id(): string;

    public function label(): string;

    public function supports(array $inspection): bool;

    /**
     * @return array<string,mixed>
     */
    public function preflight(PDO $source): array;

    /**
     * @return array<string,int>
     */
    public function apply(PDO $source): array;
}
