<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

final readonly class Request
{
    /** @var array<string, string> */
    public array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $path,
        public string $query = '',
        array $headers = [],
    ) {
        $this->headers = array_change_key_case($headers);
    }

    /** Header names are case-insensitive. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Path and query string, as the client sent them. */
    public function target(): string
    {
        return $this->query === '' ? $this->path : $this->path . '?' . $this->query;
    }
}
