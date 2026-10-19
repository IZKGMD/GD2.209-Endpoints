<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use MuchoCore\Http\Request;
use MuchoCore\Http\Response;
use Throwable;

final readonly class SongController
{
    public function __construct(
        private SongService $service
    ) {}

    public function info(Request $request): Response
    {
        $songId = $request->postInt('songID');

        if ($songId <= 0) {
            return Response::text('-1');
        }

        try {
            return Response::text($this->service->getSongInfo($songId));
        } catch (Throwable) {
            return Response::text('-1');
        }
    }
}
