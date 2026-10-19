<?php

declare(strict_types=1);

namespace MuchoCore\Plugin;

use RuntimeException;

/**
 * Read-only, locally curated marketplace metadata.
 *
 * Never downloads packages or executes plugin code. Publishing a catalog
 * entry is a separate, reviewed core change.
 */
final readonly class PluginCatalog
{
    public function __construct(
        private string $path,
        private string $coreVersion
    ) {}

    /** @return list<array<string, mixed>> */
    public function listings(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $size = filesize($this->path);
        if ($size === false || $size > 262144) {
            throw new RuntimeException('Plugin catalog is too large.');
        }

        $raw = file_get_contents($this->path);
        if (!is_string($raw)) {
            throw new RuntimeException('Plugin catalog cannot be read.');
        }

        try {
            $catalog = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Invalid plugin catalog JSON.', 0, $e);
        }

        if (
            !is_array($catalog) ||
            ($catalog['schema_version'] ?? null) !== 1 ||
            !isset($catalog['plugins']) ||
            !is_array($catalog['plugins']) ||
            !array_is_list($catalog['plugins']) ||
            count($catalog['plugins']) > 100
        ) {
            throw new RuntimeException('Unsupported plugin catalog format.');
        }

        $seen = [];
        $listings = [];

        foreach ($catalog['plugins'] as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Plugin catalog entry must be an object.');
            }

            $id = $this->string($item, 'id', 64);
            $name = $this->string($item, 'name', 80);
            $description = $this->string($item, 'description', 280);
            $version = $this->version($item, 'version');
            $minCore = $this->version($item, 'min_core_version');
            $maxCore = array_key_exists('max_core_version', $item)
                ? $this->version($item, 'max_core_version')
                : null;
            if ($maxCore !== null && version_compare($minCore, $maxCore, '>')) {
                throw new RuntimeException('Plugin core version range is inverted.');
            }

            if (
                preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $id) !== 1 ||
                isset($seen[$id])
            ) {
                throw new RuntimeException('Invalid or duplicate plugin catalog ID.');
            }
            $seen[$id] = true;

            $api = $item['api'] ?? null;
            if (!is_int($api) || $api < 1) {
                throw new RuntimeException('Invalid plugin SDK API version.');
            }

            $permissions = $item['permissions'] ?? null;
            if (!is_array($permissions) || !array_is_list($permissions)) {
                throw new RuntimeException('Invalid plugin permissions.');
            }
            foreach ($permissions as $permission) {
                if (
                    !is_string($permission) ||
                    !in_array($permission, ['events', 'routes', 'database', 'client_features'], true)
                ) {
                    throw new RuntimeException('Unknown plugin permission.');
                }
            }
            if (count($permissions) !== count(array_unique($permissions))) {
                throw new RuntimeException('Duplicate plugin permissions.');
            }

            $source = $this->string($item, 'source_url', 512);
            $parts = parse_url($source);
            if (
                !is_array($parts) ||
                ($parts['scheme'] ?? '') !== 'https' ||
                ($parts['host'] ?? '') !== 'github.com' ||
                isset($parts['user']) ||
                isset($parts['pass']) ||
                isset($parts['port']) ||
                !filter_var($source, FILTER_VALIDATE_URL)
            ) {
                throw new RuntimeException('Plugin source must be a GitHub HTTPS URL.');
            }

            $listings[] = [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'version' => $version,
                'api' => $api,
                'min_core_version' => $minCore,
                'max_core_version' => $maxCore,
                'permissions' => $permissions,
                'source_url' => $source,
                'compatible' => $api === 1 &&
                    version_compare($this->coreVersion, $minCore, '>=') &&
                    ($maxCore === null || version_compare($this->coreVersion, $maxCore, '<=')),
            ];
        }

        return $listings;
    }

    /** @param array<string, mixed> $item */
    private function string(array $item, string $field, int $maxBytes): string
    {
        $value = $item[$field] ?? null;
        if (
            !is_string($value) ||
            trim($value) === '' ||
            strlen($value) > $maxBytes ||
            preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            throw new RuntimeException('Invalid plugin catalog field: ' . $field);
        }

        return trim($value);
    }

    /** @param array<string, mixed> $item */
    private function version(array $item, string $field): string
    {
        $value = $this->string($item, $field, 32);
        if (preg_match('/^\d+\.\d+\.\d+$/D', $value) !== 1) {
            throw new RuntimeException('Invalid plugin catalog version: ' . $field);
        }
        return $value;
    }
}
