<?php

declare(strict_types=1);

namespace MuchoCore\Compatibility;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class Gd2209UtilityController
{
    public function __construct(private Gd2209UtilityService $service) {}

    private function run(Request $r, callable $handler): Response
    {
        if (strtoupper($r->method) !== 'POST') {
            return Response::text('-1');
        }
        try {
            return Response::text((string)$handler());
        } catch (Throwable $e) {
            error_log('[MuchoCore GD2.209 utilities] ' . $e::class);
            return Response::text('-1');
        }
    }

    public function audioSize(Request $r): Response
    {
        return $this->run($r, fn(): string => $this->service->audioSizes(
            $r->postString('songIDs'), $r->postString('sfxIDs')
        ));
    }

    public function contentStatus(Request $r): Response
    {
        if ($r->postInt('accountID') <= 0 || $r->gdCredential() === '') {
            return Response::text('-1');
        }
        return $this->run($r, fn(): string => $this->service->contentStatus(
            $r->postInt('accountID'), $r->gdCredential(), $r->postInt('special')
        ));
    }

    public function openedRegister(Request $r): Response
    {
        return $this->run($r, fn(): string =>
            $this->service->openedRegister($r->postString('secret'))
        );
    }
}
