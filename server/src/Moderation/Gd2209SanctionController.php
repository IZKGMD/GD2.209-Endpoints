<?php

declare(strict_types=1);

namespace MuchoCore\Moderation;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/** Strictly authenticated HTTP adapters for four persisted 2.209 ban pairs. */
final readonly class Gd2209SanctionController
{
    public function __construct(private Gd2209SanctionService $service) {}

    public function apply(Request $r, string $kind, bool $enabled): Response
    {
        if (strtoupper($r->method) !== 'POST'
            || $r->postInt('accountID') < 1
            || $r->gdCredential() === '') {
            return Response::text('-1');
        }
        try {
            return Response::text($this->service->change(
                $r->postInt('accountID'), $r->gdCredential(),
                $r->postInt('targetAccountID'),
                $kind, $enabled, $r->postInt('durationMinutes')
            ) ? '1' : '-1');
        } catch (Throwable $e) {
            error_log('[MuchoCore 2.209 sanctions] ' . $e::class);
            return Response::text('-1');
        }
    }
}
