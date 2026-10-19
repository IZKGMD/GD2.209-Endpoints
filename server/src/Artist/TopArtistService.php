<?php
declare(strict_types=1);

namespace MuchoCore\Artist;

use MuchoCore\V71\Request;
use Throwable;

final class TopArtistService
{
    public function __construct(private readonly TopArtistRepository $repo)
    {
    }

    public function get(): string
    {
        $page = min(1000, max(0, Request::int('page', 0)));
        $offset = $page * 20;

        try {
            $result = $this->repo->page($offset, 20);
        } catch (Throwable) {
            return '-1';
        }

        if (!$result['rows']) {
            return '-1';
        }

        $parts = [];

        foreach ($result['rows'] as $row) {
            $name = str_replace([':', '#', '|'], ['', '', ''], (string)$row['authorName']);
            $item = '4:' . $name;
            $download = (string)($row['download'] ?? '');

            if (str_starts_with($download, 'https://api.soundcloud.com')) {
                $item .= ':7:../redirect?q='
                    . rawurlencode('https://soundcloud.com/search/people?q=' . $name);
            }

            $parts[] = $item;
        }

        return implode('|', $parts)
            . '#'
            . (int)$result['total']
            . ':' . $offset
            . ':20';
    }
}
