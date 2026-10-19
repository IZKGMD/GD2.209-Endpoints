<?php

declare(strict_types=1);

namespace MuchoCore\Level;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

/**
 * GD 2.209 death-data and custom-controls adapters.
 * The server-side operations are implemented; native framing remains TBD.
 */
final readonly class LevelTelemetryController
{
    public function __construct(private LevelTelemetryService $service) {}

    private function level(Request $r): int
    {
        return $r->postInt('levelID', $r->postInt('levelId'));
    }

    public function getDeaths(Request $r): Response
    {
        try {
            $rows = $this->service->deaths($this->level($r));
            if ($rows === []) {
                return Response::text('-2');
            }
            $result = [];
            foreach ($rows as $row) {
                $result[] = implode(',', [
                    $row['percent'], $row['x'], $row['y'], $row['count'],
                ]);
            }
            return Response::text(implode('|', $result));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function uploadDeath(Request $r): Response
    {
        try {
            $x = $r->postString('x');
            if ($x === '') {
                $x = $r->postString('posX');
            }
            $y = $r->postString('y');
            if ($y === '') {
                $y = $r->postString('posY');
            }
            return Response::text($this->service->uploadDeath(
                $r->postInt('accountID'), $r->gdCredential(), $this->level($r),
                $r->postInt('percent'), $x, $y
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function getControls(Request $r): Response
    {
        try {
            $contents = $this->service->controls($this->level($r));
            return Response::text($contents === null ? '-2' : $contents);
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function uploadControls(Request $r): Response
    {
        try {
            $contents = $r->postString('controls');
            if ($contents === '') {
                $contents = $r->postString('data');
            }
            return Response::text($this->service->uploadControls(
                $r->postInt('accountID'), $r->gdCredential(),
                $this->level($r), $contents
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }

    public function deleteControls(Request $r): Response
    {
        try {
            return Response::text($this->service->deleteControls(
                $r->postInt('accountID'), $r->gdCredential(), $this->level($r)
            ) ? '1' : '-1');
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
