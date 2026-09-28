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
        /** The raw body, byte for byte: a signature is computed over exactly these bytes. */
        public string $body = '',
    ) {
        $this->headers = array_change_key_case($headers);
    }

    /** One parameter of the query string, when it is there once and as text. */
    public function queryParameter(string $name): ?string
    {
        parse_str($this->query, $parameters);
        $value = $parameters[$name] ?? null;

        return is_string($value) ? $value : null;
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
