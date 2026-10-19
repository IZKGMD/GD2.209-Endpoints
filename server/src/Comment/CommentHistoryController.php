<?php
declare(strict_types=1);

namespace MuchoCore\Comment;

final class CommentHistoryController
{
    public function __construct(private readonly CommentHistoryService $service)
    {
    }

    public function get(): string
    {
        return $this->service->get();
    }
}
