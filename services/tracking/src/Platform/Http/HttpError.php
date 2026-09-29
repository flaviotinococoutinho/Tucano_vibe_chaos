<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

use RuntimeException;

/** An error that already knows its HTTP status, such as a path no route serves. */
final class HttpError extends RuntimeException
{
    /** @param array<string, string> $headers */
    private function __construct(
        public readonly int $status,
        string $detail,
        public readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, $detail);
    }

    /** No WWW-Authenticate: the signature scheme is not one of HTTP's, and the caller knows it. */
    public static function unauthorized(string $detail): self
    {
        return new self(401, $detail);
    }

    /** A plain request to a WebSocket endpoint: RFC 9110 answers 426 and names the protocol to switch to. */
    public static function upgradeRequired(string $path): self
    {
        return new self(426, sprintf('The route %s speaks WebSocket only.', $path), ['Upgrade' => 'websocket', 'Connection' => 'Upgrade']);
    }

    public static function notFound(string $path): self
    {
        return new self(404, sprintf('The route %s could not be found.', $path));
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(string $method, string $path, array $allowed): self
    {
        $methods = implode(', ', $allowed);

        return new self(
            405,
            sprintf('The %s method is not supported for route %s. Supported methods: %s.', $method, $path, $methods),
            ['Allow' => $methods],
        );
    }
}
