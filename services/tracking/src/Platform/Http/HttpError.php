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
