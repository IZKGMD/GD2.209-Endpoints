<?php

declare(strict_types=1);

namespace MuchoCore\Music;

use MuchoCore\Protocol\GdSongEncoder;

final readonly class SongService
{
    public function __construct(
        private SongRepository $repository,
        private GdSongEncoder $encoder
    ) {}

    public function getSongInfo(int $songId): string
    {
        $song = $this->repository->findSong($songId);
        
        if (!$song) {
            return '-1';
        }

        return $this->encoder->encode($song);
    }
}
