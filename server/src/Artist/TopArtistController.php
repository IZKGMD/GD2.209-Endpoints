<?php
declare(strict_types=1);

namespace MuchoCore\Artist;

final class TopArtistController
{
    public function __construct(private readonly TopArtistService $service)
    {
    }

    public function get(): string
    {
        return $this->service->get();
    }
}
