<?php

declare(strict_types=1);

namespace MuchoCore\Migration;

use PDO;
use RuntimeException;

final class MigrationAdapterRegistry
{
    /** @var list<MigrationAdapterInterface> */
    private array $adapters;

    /**
     * @param list<MigrationAdapterInterface> $adapters
     */
    public function __construct(
        private readonly PDO $target,
        ?array $adapters = null
    ) {
        $this->adapters = $adapters ?? [
            new CvoltonMigrationAdapter($target),
        ];
    }

    /**
     * @return array{adapter:MigrationAdapterInterface,inspection:array<string,mixed>}
     */
    public function detect(PDO $source): array
    {
        $inspection = (new SourceDetector())->inspect($source);

        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($inspection)) {
                return [
                    'adapter' => $adapter,
                    'inspection' => $inspection,
                ];
            }
        }

        throw new RuntimeException(
            'No migration adapter supports the detected schema: ' .
            (string)($inspection['label'] ?? 'Unknown GDPS schema')
        );
    }

    /**
     * @return list<array{id:string,label:string}>
     */
    public function available(): array
    {
        return array_map(
            static fn(MigrationAdapterInterface $adapter): array => [
                'id' => $adapter->id(),
                'label' => $adapter->label(),
            ],
            $this->adapters
        );
    }
}
