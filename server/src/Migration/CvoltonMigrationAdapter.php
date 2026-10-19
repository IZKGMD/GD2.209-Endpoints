<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;

final class CvoltonMigrationAdapter implements MigrationAdapterInterface
{
    public function __construct(
        private readonly PDO $target
    ) {
    }

    public function id(): string
    {
        return 'cvolton';
    }

    public function label(): string
    {
        return 'Cvolton-compatible GDPS';
    }

    public function supports(array $inspection): bool
    {
        return ($inspection['engine'] ?? '') === $this->id();
    }

    public function preflight(PDO $source): array
    {
        return (new CvoltonDatabaseImporter($this->target))
            ->preflight($source);
    }

    public function apply(PDO $source): array
    {
        return (new CvoltonDatabaseImporter($this->target))
            ->apply($source);
    }
}
