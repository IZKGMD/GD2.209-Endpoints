<?php

declare(strict_types=1);

namespace MuchoCore\Plugin;

use MuchoCore\Client\ClientFeatureRegistry;
use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use MuchoCore\Routing\Router;
use PDO;
use RuntimeException;

final readonly class PluginContext
{
    public function __construct(
        private PDO $db,
        private Router $router,
        private PluginEventBus $events,
        private array $permissions,
        private string $pluginName,
        private ?ClientFeatureRegistry $clientFeatures = null
    ) {}

    public function name(): string
    {
        return $this->pluginName;
    }

    public function db(): PDO
    {
        $this->requirePermission('database');
        return $this->db;
    }

    public function on(string $event, callable $listener): void
    {
        $this->requirePermission('events');
        $this->events->on($event, $listener);
    }

    public function route(string $method, string $path, callable $handler): void
    {
        $this->requirePermission('routes');

        $method = strtoupper(trim($method));
        $path = trim($path);

        if (!in_array($method, ['GET', 'POST', 'ANY'], true)) {
            throw new RuntimeException('Plugin routes support GET, POST, and ANY.');
        }

        if ($path === '' || !str_starts_with($path, '/')) {
            throw new RuntimeException('Plugin route path must start with /.');
        }

        $this->router->addPlugin(
            $method,
            $path,
            static function (Request $request) use ($handler): Response {
                $result = $handler($request);
                return $result instanceof Response
                    ? $result
                    : Response::text((string)$result);
            }
        );
    }

    /** Advertise a server-provided section inside the MuchoClient menu. */
    public function clientFeature(
        string $id,
        string $name,
        string $description,
        string $entrypoint
    ): void {
        $this->requirePermission('client_features');

        if ($this->clientFeatures === null) {
            throw new RuntimeException('Client feature registry is unavailable.');
        }

        $this->clientFeatures->add($id, $name, $description, $entrypoint);
    }

    private function requirePermission(string $permission): void
    {
        if (!in_array($permission, $this->permissions, true)) {
            throw new RuntimeException(
                'Plugin permission denied: ' . $permission
            );
        }
    }
}
