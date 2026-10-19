<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use InvalidArgumentException;

/**
 * Validated metadata for in-game MuchoClient navigation.
 * No remote PHP, scripts, assets, credentials or arbitrary URLs are delivered.
 */
final class ClientFeatureRegistry
{
    /** @var array<string,array<string,mixed>> */
    private array $features = [];

    public function add(
        string $id,
        string $name,
        string $description,
        string $entrypoint
    ): void {
        if (
            !preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $id) ||
            $id === 'clans' ||
            strlen($name) < 1 ||
            strlen($name) > 64 ||
            strlen($description) > 200 ||
            preg_match('/[\x00-\x1f\x7f]/', $name . $description) ||
            !preg_match('#^/extensions/[a-z0-9/_-]{1,120}$#D', $entrypoint)
        ) {
            throw new InvalidArgumentException('Invalid client feature metadata.');
        }

        if (isset($this->features[$id])) {
            throw new InvalidArgumentException('Duplicate client feature ID: ' . $id);
        }

        if (count($this->features) >= 50) {
            throw new InvalidArgumentException('Client feature count limit exceeded.');
        }

        $this->features[$id] = [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'entrypoint' => $entrypoint,
            'origin' => 'plugin',
            'requires_client' => true,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_values($this->features);
    }
}
