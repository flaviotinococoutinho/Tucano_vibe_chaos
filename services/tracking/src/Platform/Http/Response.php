<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
    ) {}

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200, string $contentType = 'application/json'): self
    {
        // A path with invalid UTF-8 ends up in problem details; it must not turn the answer into a crash.
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return new self($status, $body, ['Content-Type' => $contentType]);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => $value]);
    }

    /** The answer to HEAD: the same headers, including the length of the body, and no body. */
    public function withoutBody(): self
    {
        return new self($this->status, '', [...$this->headers, 'Content-Length' => (string) strlen($this->body)]);
    }
}
