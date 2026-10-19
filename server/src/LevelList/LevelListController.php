<?php
declare(strict_types=1);

namespace MuchoCore\LevelList;

final class LevelListController
{
    public function __construct(private readonly LevelListService $service)
    {
    }

    public function get(): string
    {
        return $this->service->get();
    }

    public function upload(): string
    {
        return $this->service->upload();
    }

    public function delete(): string
    {
        return $this->service->delete();
    }
}
