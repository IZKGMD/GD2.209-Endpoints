<?php

declare(strict_types=1);

namespace MuchoCore\Core;

final readonly class RequestContext
{
    public function __construct(
        public string $requestId,
        public float $startedAt,
    ) {
    }

    public static function create(): self
    {
        return new self(
            bin2hex(random_bytes(8)),
            microtime(true)
        );
    }

    public function durationMs(): float
    {
        return (
            microtime(true)
            - $this->startedAt
        ) * 1000;
    }
}
