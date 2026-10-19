<?php

declare(strict_types=1);

namespace MuchoCore\Client;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;

/**
 * Versioned public discovery protocol for the optional Geode client.
 *
 * This protocol does NOT authenticate users and does NOT prove which executable
 * a user runs. All privileged feature endpoints must authenticate separately.
 */
final readonly class ClientBridgeController
{
    private const CLIENT_ID = 'izkgmd.muchoclient';
    private const MIN_CLIENT_VERSION = '0.1.0';
    private const PROTOCOL_VERSION = 1;

    public function __construct(
        private ClientFeatureRegistry $features,
        private string $coreVersion
    ) {}

    public function manifest(Request $request): Response
    {
        if (strtoupper($request->method) !== 'GET') {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        return Response::json([
            'schema_version' => 1,
            'core_version' => $this->coreVersion,
            'client' => [
                'id' => self::CLIENT_ID,
                'min_version' => self::MIN_CLIENT_VERSION,
                'protocol' => self::PROTOCOL_VERSION,
                'required_for_extensions' => true,
                'required_for_legacy_gameplay' => false,
            ],
            'features' => array_merge(
                [[
                    'id' => 'clans',
                    'name' => 'Clans',
                    'description' => 'Clan features for authenticated players',
                    'entrypoint' => '/api/clans/my',
                    'origin' => 'core',
                    'requires_client' => true,
                ]],
                $this->features->all()
            ),
        ]);
    }

    public function negotiate(Request $request): Response
    {
        if (strtoupper($request->method) !== 'POST') {
            return Response::json(['error' => 'method_not_allowed'], 405);
        }

        $version = $request->postString('client_version');
        $protocol = $request->postInt('protocol');
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            return Response::json(['error' => 'invalid_client_version'], 400);
        }

        $compatible = $protocol === self::PROTOCOL_VERSION &&
            version_compare($version, self::MIN_CLIENT_VERSION, '>=');

        return Response::json([
            'compatible' => $compatible,
            'status' => $compatible ? 'ready' : 'upgrade_required',
            'min_version' => self::MIN_CLIENT_VERSION,
            'protocol' => self::PROTOCOL_VERSION,
            // This is a compatibility response, never an access token.
            'session_token' => null,
        ]);
    }
}
