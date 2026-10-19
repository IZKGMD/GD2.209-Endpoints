<?php

declare(strict_types=1);

namespace MuchoCore\Http;

final readonly class Response
{
    public function __construct(
        public string $body,
        public int $status = 200,
        public string $contentType = 'text/plain; charset=utf-8',
    ) {}

    public static function text(
        string $body,
        int $status = 200
    ): self {
        return new self($body, $status);
    }

    public static function json(
        array $data,
        int $status = 200
    ): self {
        return new self(
            json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ),
            $status,
            'application/json; charset=utf-8'
        );
    }

    /**
     * Return a payload-safe Response snapshot for plugin lifecycle events.
     *
     * Response bodies may contain account, message or protocol data, so only
     * transport metadata is exposed through the plugin event contract.
     */
    public function forPluginEvent(): self
    {
        return new self(
            '',
            $this->status,
            $this->contentType,
        );
    }

    public function send(): never
    {
        // Clear output buffers, BOMs and accidental whitespace.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);

        echo $this->body;
        exit;
    }
}
